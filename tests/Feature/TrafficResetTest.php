<?php

namespace Tests\Feature;

use App\Console\Commands\ResetTraffic;
use App\Models\User;
use App\Services\TrafficResetService;
use App\Services\UserService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** Isolated SQLite tests: no site database, node, Redis, or external requests. */
class TrafficResetTest extends TestCase
{
    private TrafficResetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) $this->markTestSkipped('PDO SQLite required.');
        config(['app.timezone' => 'Asia/Shanghai', 'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'v2board.allow_new_period' => 1, 'v2board.reset_traffic_method' => 1]);
        DB::purge('sqlite');
        DB::statement('CREATE TABLE v2_user (id INTEGER PRIMARY KEY, plan_id INTEGER, expired_at INTEGER,
            u INTEGER NOT NULL DEFAULT 0, d INTEGER NOT NULL DEFAULT 0, transfer_enable INTEGER NOT NULL DEFAULT 100,
            traffic_reset_anchor_at INTEGER, traffic_reset_offset INTEGER NOT NULL DEFAULT 0, traffic_reset_at INTEGER,
            traffic_reset_plan_id INTEGER, traffic_reset_method INTEGER, updated_at INTEGER,
            token TEXT, email TEXT, uuid TEXT, device_limit INTEGER)');
        DB::statement('CREATE INDEX traffic_reset_due ON v2_user (traffic_reset_at,id)');
        DB::statement('CREATE TABLE v2_plan (id INTEGER PRIMARY KEY,reset_traffic_method INTEGER,name TEXT)');
        DB::table('v2_plan')->insert(['id' => 1, 'reset_traffic_method' => 1, 'name' => '测试月付订阅']);
        DB::statement('CREATE TABLE v2_traffic_advance_log (id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,email TEXT NOT NULL,plan_id INTEGER NOT NULL,plan_name TEXT NOT NULL,
            deduct_seconds INTEGER NOT NULL,old_expired_at INTEGER NOT NULL,new_expired_at INTEGER NOT NULL,created_at INTEGER NOT NULL)');
        $this->service = new TrafficResetService();
    }

    private function at(string $time): int
    {
        return (new DateTimeImmutable($time, new DateTimeZone('Asia/Shanghai')))->getTimestamp();
    }

    private function account(string $expiry, array $extra = []): User
    {
        DB::table('v2_user')->insert($extra + ['id' => 1, 'plan_id' => 1, 'expired_at' => $this->at($expiry),
            'u' => 40, 'd' => 60, 'transfer_enable' => 100, 'token' => 'offline-token', 'email' => 'offline@example.test', 'uuid' => 'offline-uuid']);
        return User::findOrFail($extra['id'] ?? 1);
    }

    public function test_original_thirty_one_day_case_keeps_the_thirty_day_purchased_period(): void
    {
        $user = $this->account('2026-12-06 00:00:00');
        $this->service->advance($user, $this->at('2026-11-05 00:00:00'));
        $saved = $user->fresh();
        $this->assertSame($this->at('2026-12-05 00:00:00'), (int) $saved->expired_at);
        $this->assertSame(86400, (int) $saved->traffic_reset_offset);
        $this->assertSame(0, (int) $saved->u + (int) $saved->d);
        $this->assertNull((new UserService())->getResetDay($saved, $this->at('2026-11-06 00:00:00')));
    }

    public function test_half_day_is_forfeited_without_rounding_up(): void
    {
        $user = $this->account('2026-12-06 00:00:00');
        $this->service->advance($user, $this->at('2026-11-05 12:00:00'));
        $this->assertSame($this->at('2026-12-05 12:00:00'), (int) $user->fresh()->expired_at);
        $this->assertSame(43200, (int) $user->fresh()->traffic_reset_offset);
        $this->assertSame([
            'id' => 1, 'user_id' => 1, 'email' => 'offline@example.test', 'plan_id' => 1,
            'plan_name' => '测试月付订阅', 'deduct_seconds' => 43200,
            'old_expired_at' => $this->at('2026-12-06 00:00:00'),
            'new_expired_at' => $this->at('2026-12-05 12:00:00'),
            'created_at' => $this->at('2026-11-05 12:00:00'),
        ], (array) DB::table(TrafficResetService::ADVANCE_LOG_TABLE)->sole());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_record_failure_rolls_back_the_advance_and_releases_the_transaction(): void
    {
        $user = $this->account('2026-12-06 00:00:00');
        $before = (array) DB::table('v2_user')->where('id', 1)->first();
        DB::unprepared("CREATE TRIGGER reject_advance_record BEFORE INSERT ON v2_traffic_advance_log
            BEGIN SELECT RAISE(ABORT, 'offline record failure'); END");
        try {
            $this->service->advance($user, $this->at('2026-11-05 00:00:00'));
            $this->fail('A failed record insert must fail the entire advance.');
        } catch (\Illuminate\Database\QueryException) {}
        $this->assertSame($before, (array) DB::table('v2_user')->where('id', 1)->first());
        $this->assertSame(0, DB::table(TrafficResetService::ADVANCE_LOG_TABLE)->count());
        $this->assertSame(0, DB::transactionLevel());
        DB::unprepared('DROP TRIGGER reject_advance_record');
        $this->service->advance($user->fresh(), $this->at('2026-11-05 00:00:00'));
        $this->assertSame(1, DB::table(TrafficResetService::ADVANCE_LOG_TABLE)->count());
    }

    public function test_february_period_and_the_following_march_restore_the_original_anchor(): void
    {
        $user = $this->account('2027-03-31 00:00:00', [
            'traffic_reset_anchor_at' => $this->at('2027-01-31 00:00:00'),
            'traffic_reset_at' => $this->at('2027-01-31 00:00:00'),
            'traffic_reset_plan_id' => 1, 'traffic_reset_method' => 1,
        ]);
        $this->service->advance($user, $this->at('2027-01-30 00:00:00'));
        $saved = $user->fresh();
        $this->assertSame($this->at('2027-02-27 00:00:00'), (int) $saved->traffic_reset_at);
        $this->assertSame($this->at('2027-03-30 00:00:00'), (int) $saved->expired_at);
        DB::table('v2_user')->where('id', 1)->update(['u' => 20]);
        $this->assertTrue($this->service->resetPersonal($user->fresh(), $this->at('2027-02-27 00:00:00')));
        $this->assertSame($this->at('2027-03-30 00:00:00'), (int) $user->fresh()->traffic_reset_at);
    }

    public function test_incomplete_next_period_is_rejected_without_changes(): void
    {
        $user = $this->account('2026-11-06 00:00:00');
        try {
            $this->service->advance($user, $this->at('2026-11-05 00:00:00'));
            $this->fail('A partial future period cannot be advanced.');
        } catch (InvalidArgumentException) {}
        $this->assertSame($user->expired_at, $user->fresh()->expired_at);
        $this->assertSame(100, (int) $user->fresh()->u + (int) $user->fresh()->d);
        $this->assertNull($user->fresh()->traffic_reset_anchor_at);
        $this->assertSame(0, DB::table(TrafficResetService::ADVANCE_LOG_TABLE)->count());
    }

    public function test_stale_second_click_cannot_clear_newly_used_traffic(): void
    {
        $user = $this->account('2027-02-06 00:00:00');
        $stale = $user->fresh();
        $now = $this->at('2026-11-05 00:00:00');
        $this->service->advance($user, $now);
        $expiry = $user->fresh()->expired_at;
        DB::table('v2_user')->where('id', 1)->update(['u' => 7]);
        try {
            $this->service->advance($stale, $now);
            $this->fail('A stale snapshot must not succeed.');
        } catch (RuntimeException) {}
        $this->assertSame($expiry, $user->fresh()->expired_at);
        $this->assertSame(7, (int) $user->fresh()->u);
        $this->assertSame(1, DB::table(TrafficResetService::ADVANCE_LOG_TABLE)->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_stale_daily_reset_cannot_clear_the_advanced_period(): void
    {
        $user = $this->account('2026-12-06 00:00:00');
        $stale = $user->fresh();
        $this->service->advance($user, $this->at('2026-11-05 00:00:00'));
        DB::table('v2_user')->where('id', 1)->update(['u' => 9]);
        $this->assertFalse($this->service->resetLegacy($stale, $this->at('2026-11-06 00:00:00')));
        $this->assertSame(9, (int) $user->fresh()->u);
    }

    public function test_daily_reset_committing_first_invalidates_an_earlier_advance_snapshot(): void
    {
        $user = $this->account('2026-12-06');
        $this->assertTrue($this->service->resetLegacy($user, $this->at('2026-11-06')));
        try {
            $this->service->advance($user, $this->at('2026-11-05 23:59:59'));
            $this->fail('Reset counters must invalidate the stale advance.');
        } catch (RuntimeException) {}
        $this->assertSame($this->at('2026-12-06'), (int) $user->fresh()->expired_at);
        $this->assertNull($user->fresh()->traffic_reset_anchor_at);
    }

    public function test_daily_command_excludes_an_account_with_a_valid_personal_context(): void
    {
        $user = $this->account(date('Y-m-d 00:00:00', strtotime('+1 year')), [
            'traffic_reset_anchor_at' => time() - 86400, 'traffic_reset_at' => time() + 86400,
            'traffic_reset_plan_id' => 1, 'traffic_reset_method' => 1,
        ]);
        $service = \Mockery::mock(TrafficResetService::class);
        $service->shouldReceive('resetLegacy')->never();
        $this->app->instance(TrafficResetService::class, $service);
        Redis::shouldReceive('setex')->once()->with('traffic_reset_lock', 300, 1);
        Redis::shouldReceive('del')->once()->with('traffic_reset_lock');
        $this->artisan('reset:traffic')->assertExitCode(0);
        $this->assertSame(100, (int) $user->fresh()->u + (int) $user->fresh()->d);
    }

    public function test_personal_reset_advances_the_cursor_once_and_stale_retry_does_not_clear_again(): void
    {
        $user = $this->account('2027-02-06 00:00:00');
        $this->service->advance($user, $this->at('2026-11-05 00:00:00'));
        DB::table('v2_user')->where('id', 1)->update(['u' => 18]);
        $snapshot = $user->fresh();
        $due = (int) $snapshot->traffic_reset_at;
        $this->assertTrue($this->service->resetPersonal($snapshot, $due));
        DB::table('v2_user')->where('id', 1)->update(['u' => 8]);
        $this->assertFalse($this->service->resetPersonal($snapshot, $due));
        $this->assertFalse($this->service->resetPersonal($user->fresh(), $due));
        $this->assertSame(8, (int) $user->fresh()->u);
        $this->assertGreaterThan($due, (int) $user->fresh()->traffic_reset_at);
    }

    public function test_same_plan_extension_keeps_the_original_personal_boundary(): void
    {
        $user = $this->account('2026-12-06 00:00:00');
        $this->service->advance($user, $this->at('2026-11-05 00:00:00'));
        DB::table('v2_user')->where('id', 1)->update(['expired_at' => $this->at('2027-01-05 00:00:00'), 'u' => 12]);
        $this->assertSame($this->at('2026-12-05 00:00:00'), $this->service->nextResetAt($user->fresh(), $this->at('2026-11-07')));
        $this->assertTrue($this->service->resetPersonal($user->fresh(), $this->at('2026-12-05')));
    }

    public function test_month_start_advance_uses_the_personal_boundary_instead_of_the_old_first_day(): void
    {
        DB::table('v2_plan')->where('id', 1)->update(['reset_traffic_method' => 0]);
        $user = $this->account('2027-03-01');
        $this->service->advance($user, $this->at('2026-11-30 12:00:00'));
        DB::table('v2_user')->where('id', 1)->update(['u' => 13]);
        $saved = $user->fresh();
        $this->assertSame($this->at('2026-12-31 12:00:00'), (int) $saved->traffic_reset_at);
        $this->assertFalse($this->service->resetLegacy($saved, $this->at('2026-12-01')));
        $this->assertFalse($this->service->resetPersonal($saved, $this->at('2026-12-31 11:59:59')));
        $this->assertSame(13, (int) $user->fresh()->u);
        $this->assertTrue($this->service->resetPersonal($saved, $this->at('2026-12-31 12:00:00')));
    }

    public function test_annual_leap_day_reset_and_display_agree_in_a_common_year(): void
    {
        DB::table('v2_plan')->where('id', 1)->update(['reset_traffic_method' => 4]);
        $user = $this->account('2028-02-29');
        $this->assertSame($this->at('2027-02-28'), $this->service->nextResetAt($user, $this->at('2027-02-27')));
        $this->assertTrue($this->service->resetLegacy($user, $this->at('2027-02-28')));
        $this->assertSame(0, (int) $user->fresh()->u + (int) $user->fresh()->d);
    }

    public function test_advancing_does_not_add_a_reset_for_the_legacy_short_final_tail(): void
    {
        $user = $this->account('2027-03-10', [
            'traffic_reset_anchor_at' => $this->at('2027-01-31'), 'traffic_reset_at' => $this->at('2027-01-31'),
            'traffic_reset_plan_id' => 1, 'traffic_reset_method' => 1,
        ]);
        $this->service->advance($user, $this->at('2027-01-30'));
        DB::table('v2_user')->where('id', 1)->update(['u' => 19]);
        $saved = $user->fresh();
        $this->assertNull($this->service->nextResetAt($saved, $this->at('2027-02-01')));
        $this->assertFalse($this->service->resetPersonal($saved, $this->at('2027-02-27')));
        $this->assertSame(19, (int) $user->fresh()->u);
    }

    public function test_changed_plan_does_not_reuse_or_clear_traffic_with_the_old_personal_schedule(): void
    {
        $user = $this->account('2027-02-06 00:00:00');
        $this->service->advance($user, $this->at('2026-11-05'));
        DB::table('v2_plan')->insert(['id' => 2, 'reset_traffic_method' => 0]);
        DB::table('v2_user')->where('id', 1)->update(['plan_id' => 2, 'u' => 11]);
        $saved = $user->fresh();
        $this->assertSame($this->at('2026-12-01'), $this->service->nextResetAt($saved, $this->at('2026-11-07')));
        $this->service->resetPersonal($saved, $this->at('2026-12-05'));
        $this->assertSame(11, (int) $user->fresh()->u);
        $this->assertNull($user->fresh()->traffic_reset_anchor_at);
    }

    public function test_month_start_display_and_final_expiry_do_not_report_a_nonexistent_reset(): void
    {
        DB::table('v2_plan')->where('id', 1)->update(['reset_traffic_method' => 0]);
        $user = $this->account('2027-02-06');
        $this->assertSame(26, (new UserService())->getResetDay($user, $this->at('2026-11-05')));
        $this->assertSame(1, (new UserService())->getResetDay($user, $this->at('2026-11-30 12:00:00')));
    }

    public function test_personal_command_processes_at_most_one_hundred_due_accounts(): void
    {
        $now = time();
        $anchor = $this->at(date('Y-m-d', $now)) - 86400;
        for ($id = 1; $id <= 101; $id++) {
            $this->account(date('Y-m-d', $now + 100 * 86400), ['id' => $id,
                'traffic_reset_anchor_at' => $anchor, 'traffic_reset_at' => $anchor,
                'traffic_reset_plan_id' => 1, 'traffic_reset_method' => 1]);
        }
        Redis::shouldReceive('setex')->never();
        Redis::shouldReceive('del')->never();
        $this->artisan('reset:traffic', ['--personal-only' => true])->assertExitCode(0);
        $this->assertSame(100, DB::table('v2_user')->where('u', 0)->count());
        $this->assertSame(1, DB::table('v2_user')->where('traffic_reset_at', '<=', $now)->count());
    }

    public function test_http_contract_remains_synchronous_and_subscription_does_not_expose_internal_state(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\User::class);
        $user = $this->account(date('Y-m-d 00:00:00', strtotime('+2 months')));
        $this->postJson('/api/v1/user/newPeriod', ['user' => ['id' => 1]])->assertOk()->assertExactJson(['data' => true]);
        $response = $this->getJson('/api/v1/user/getSubscribe?user[id]=1')->assertOk();
        foreach (TrafficResetService::FIELDS as $field) $response->assertJsonMissingPath('data.' . $field);
        $this->assertNotNull($user->fresh()->traffic_reset_anchor_at);
    }
}
