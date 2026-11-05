<?php

namespace Tests\Feature;

use App\Console\Commands\ResetTraffic;
use App\Models\User;
use App\Services\TrafficResetService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TrafficResetCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite required.');
        }
        config(['database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array']);
        DB::purge('sqlite');
        Cache::forget(ResetTraffic::PERSONAL_CURSOR_CACHE_KEY);
        DB::statement('CREATE TABLE v2_user (id INTEGER PRIMARY KEY, plan_id INTEGER, expired_at INTEGER,
            u INTEGER, d INTEGER, transfer_enable INTEGER, traffic_reset_anchor_at INTEGER, traffic_reset_offset INTEGER,
            traffic_reset_at INTEGER, traffic_reset_plan_id INTEGER, traffic_reset_method INTEGER)');
        DB::statement('CREATE INDEX traffic_reset_due ON v2_user (traffic_reset_at,id)');
        Redis::shouldReceive('setex')->never();
        Redis::shouldReceive('del')->never();
    }

    private function candidates(int $count, int $due): void
    {
        $rows = [];
        for ($id = 1; $id <= $count; $id++) {
            // Even expired/final records are bounded candidates; filtering them
            // in SQL could otherwise scan arbitrarily much historical data.
            $rows[] = ['id' => $id, 'traffic_reset_at' => $due, 'expired_at' => $due - 1];
        }
        DB::table('v2_user')->insert($rows);
    }

    private function process(callable $callback): void
    {
        $service = Mockery::mock(TrafficResetService::class);
        $service->shouldReceive('resetPersonal')->andReturnUsing($callback);
        $this->app->instance(TrafficResetService::class, $service);
        $this->artisan('reset:traffic', ['--personal-only' => true])->assertExitCode(0);
    }

    public function test_personal_page_is_bounded_and_does_not_take_the_site_traffic_marker(): void
    {
        $due = time() - 10;
        $this->candidates(101, $due);
        $processed = [];
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $this->process(function (User $user) use (&$processed) {
            $processed[] = (int) $user->id;
            return false;
        });
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();
        $this->assertSame(range(1, 100), $processed);
        $cursor = Cache::get(ResetTraffic::PERSONAL_CURSOR_CACHE_KEY);
        $this->assertSame($due, $cursor['at']);
        $this->assertSame(100, $cursor['id']);
        $selects = array_values(array_filter($queries, fn ($query) => str_starts_with($query['query'], 'select')));
        $this->assertCount(1, $selects);
        $this->assertStringContainsString('limit 100', $selects[0]['query']);
        $this->assertStringNotContainsString('"expired_at" >', $selects[0]['query']);
        $this->assertStringContainsString('order by "traffic_reset_at" asc, "id" asc', $selects[0]['query']);
    }

    public function test_failed_first_page_does_not_starve_later_ids_and_is_revisited_next_sweep(): void
    {
        $due = time() - 10;
        $this->candidates(101, $due);
        $processed = [];
        Log::shouldReceive('error')->once()->withArgs(fn ($message, $context) => $context['count'] === 100 && count($context['examples']) === 3);
        $this->process(function (User $user) use (&$processed) {
            $processed[] = (int) $user->id;
            throw new RuntimeException('Simulated busy or invalid account.');
        });
        $firstUntil = Cache::get(ResetTraffic::PERSONAL_CURSOR_CACHE_KEY)['until'];
        $this->assertSame(range(1, 100), $processed);

        $processed = [];
        $this->process(function (User $user) use (&$processed) {
            $processed[] = (int) $user->id;
            return false;
        });
        $this->assertSame([101], $processed);
        $this->assertSame($firstUntil, Cache::get(ResetTraffic::PERSONAL_CURSOR_CACHE_KEY)['until']);

        $this->process(fn () => $this->fail('End of sweep must not reprocess a record.'));
        $this->assertNull(Cache::get(ResetTraffic::PERSONAL_CURSOR_CACHE_KEY)['at']);
        $processed = [];
        $this->process(function (User $user) use (&$processed) {
            $processed[] = (int) $user->id;
            return false;
        });
        $this->assertSame(range(1, 100), $processed);
    }

    public function test_sweep_high_water_is_fixed_so_new_candidates_wait_for_the_next_sweep(): void
    {
        $now = time();
        $this->candidates(2, $now - 10);
        Cache::forever(ResetTraffic::PERSONAL_CURSOR_CACHE_KEY, ['at' => $now - 10, 'id' => 1, 'until' => $now - 5]);
        DB::table('v2_user')->insert(['id' => 3, 'traffic_reset_at' => $now - 1, 'expired_at' => $now + 86400]);
        $processed = [];
        $this->process(function (User $user) use (&$processed) {
            $processed[] = (int) $user->id;
            return false;
        });
        $this->assertSame([2], $processed);
        $this->assertSame($now - 5, Cache::get(ResetTraffic::PERSONAL_CURSOR_CACHE_KEY)['until']);
        $this->assertSame(['at' => $now - 10, 'id' => 2, 'until' => $now - 5], Cache::get(ResetTraffic::PERSONAL_CURSOR_CACHE_KEY));
    }

    public function test_time_budget_keeps_progress_after_slow_accounts(): void
    {
        $due = time() - 10;
        $this->candidates(5, $due);
        $processed = [];
        $this->process(function (User $user) use (&$processed) {
            $processed[] = (int) $user->id;
            usleep(1050000);
            return false;
        });
        $this->assertSame([1, 2], $processed);
        $this->assertSame(2, Cache::get(ResetTraffic::PERSONAL_CURSOR_CACHE_KEY)['id']);
        $processed = [];
        $this->process(function (User $user) use (&$processed) {
            $processed[] = (int) $user->id;
            return false;
        });
        $this->assertSame([3, 4, 5], $processed);
    }
}
