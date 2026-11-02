<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\InsiderGuardService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class InsiderGuardUaTest extends TestCase
{
    private const UA = 'InsiderGuard regression client/1.0';

    /** @dataProvider legacyCacheCases */
    public function testLoginRepairsMissingUaEvenWhenTheLegacyCacheMarkerExistsOrRedisFails(bool $cacheFails): void
    {
        $this->requireMbstring();
        $hash = md5(self::UA);
        // A stale marker or unavailable Redis must not participate in dictionary maintenance.
        $cache = Cache::shouldReceive('add')->never();
        if ($cacheFails) {
            $cache->andThrow(new RuntimeException('Redis unavailable'));
        } else {
            $cache->andReturn(false);
        }
        Cache::shouldReceive('forget')->never();
        $this->expectLoginActivities(2, $hash);
        $dictionary = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('v2_insider_guard_ua')->times(4)->andReturn($dictionary);
        $dictionary->shouldReceive('where')->with('ua_hash', $hash)->twice()->andReturnSelf();
        // The same hash can disappear after an earlier successful insert, while an old cache marker remains.
        $dictionary->shouldReceive('value')->with('last_seen')->twice()->andReturn(null);
        $dictionary->shouldReceive('upsert')->twice()->with(
            $this->uaRows($hash), ['ua_hash'], ['last_seen']
        )->andReturn(1);
        [$user, $request] = $this->loginInput();

        $this->assertNull(InsiderGuardService::recordLogin($user, $request));
        $this->assertNull(InsiderGuardService::recordLogin($user, $request));
    }

    public static function legacyCacheCases(): array
    {
        return [
            'legacy UA marker already exists' => [false],
            'Redis is unavailable' => [true],
        ];
    }

    public function testFailedDictionaryInsertDoesNotLoseActivitiesOrSuppressTheNextRetry(): void
    {
        $this->requireMbstring();
        $hash = md5(self::UA);
        $this->expectLoginActivities(2, $hash);
        $dictionary = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('v2_insider_guard_ua')->times(4)->andReturn($dictionary);
        $dictionary->shouldReceive('where')->with('ua_hash', $hash)->twice()->andReturnSelf();
        $dictionary->shouldReceive('value')->with('last_seen')->twice()->andReturn(null);
        $attempts = 0;
        $dictionary->shouldReceive('upsert')->twice()->with(
            $this->uaRows($hash), ['ua_hash'], ['last_seen']
        )->andReturnUsing(function () use (&$attempts) {
            if (++$attempts === 1) throw new RuntimeException('UA insert temporarily failed');
            return 1;
        });
        Cache::shouldReceive('add')->once()->with(
            'insider_guard_write_error:' . md5('InsiderGuard ua dictionary unavailable'),
            1,
            InsiderGuardService::LOG_THROTTLE_SECONDS
        )->andReturn(true);
        Cache::shouldReceive('forget')->never();
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('daily')->once()->andReturn($logger);
        $logger->shouldReceive('warning')->once()->with('InsiderGuard ua dictionary unavailable', Mockery::type('array'));
        [$user, $request] = $this->loginInput();

        $this->assertNull(InsiderGuardService::recordLogin($user, $request));
        $this->assertNull(InsiderGuardService::recordLogin($user, $request));
        $this->assertSame(2, $attempts);
    }

    /** @dataProvider refreshBoundaryCases */
    public function testDictionaryRefreshUsesADailyBoundaryAndPreservesFirstSeen(int $age, bool $shouldWrite): void
    {
        $now = 1750000000;
        $hash = md5(self::UA);
        Cache::shouldReceive('add')->never();
        $dictionary = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('v2_insider_guard_ua')->times($shouldWrite ? 2 : 1)->andReturn($dictionary);
        $dictionary->shouldReceive('where')->with('ua_hash', $hash)->once()->andReturnSelf();
        $dictionary->shouldReceive('value')->with('last_seen')->once()->andReturn($now - $age);
        if ($shouldWrite) {
            // first_seen is supplied for a new row but deliberately excluded from conflict updates.
            $dictionary->shouldReceive('upsert')->once()->with([
                ['ua_hash' => $hash, 'ua' => self::UA, 'first_seen' => $now, 'last_seen' => $now],
            ], ['ua_hash'], ['last_seen'])->andReturn(1);
        } else {
            $dictionary->shouldReceive('upsert')->never();
        }

        $this->assertNull($this->rememberUa($hash, $now));
    }

    public static function refreshBoundaryCases(): array
    {
        return [
            '23 hours old stays fresh' => [23 * 3600, false],
            'one second before a day stays fresh' => [86400 - 1, false],
            'exactly one day refreshes' => [86400, true],
            'older than one day refreshes' => [86400 + 1, true],
        ];
    }

    private function expectLoginActivities(int $count, string $hash): void
    {
        $activity = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('v2_insider_guard_activity')->times($count * 2)->andReturn($activity);
        $activity->shouldReceive('where')->times($count * 2)->andReturnSelf();
        $activity->shouldReceive('count')->times($count)->andReturn(0);
        DB::shouldReceive('raw')->with('times + 1')->times($count)->andReturn(new Expression('times + 1'));
        $activity->shouldReceive('upsert')->times($count)->with(
            Mockery::on(function ($rows) use ($hash) {
                $this->assertCount(1, $rows);
                $this->assertSame($hash, $rows[0]['ua_hash']);
                $this->assertSame(42, $rows[0]['user_id']);
                $this->assertSame('login', $rows[0]['event_type']);
                return true;
            }),
            ['user_id', 'event_type', 'hour_start', 'ip_address', 'ua_hash'],
            Mockery::type('array')
        )->andReturn(1);
    }

    private function uaRows(string $hash)
    {
        return Mockery::on(function ($rows) use ($hash) {
            $this->assertCount(1, $rows);
            $this->assertSame($hash, $rows[0]['ua_hash']);
            $this->assertSame(self::UA, $rows[0]['ua']);
            $this->assertSame($rows[0]['first_seen'], $rows[0]['last_seen']);
            return true;
        });
    }

    private function loginInput(): array
    {
        $user = new User();
        $user->id = 42;
        $request = Request::create('/api/v1/passport/auth/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.42', 'HTTP_USER_AGENT' => self::UA,
        ]);
        return [$user, $request];
    }

    private function requireMbstring(): void
    {
        if (!function_exists('mb_substr') || !function_exists('mb_convert_encoding')) {
            $this->markTestSkipped('The audit path requires the mbstring extension');
        }
    }

    private function rememberUa(string $hash, int $now)
    {
        $method = new ReflectionMethod(InsiderGuardService::class, 'rememberUa');
        $method->setAccessible(true);
        return $method->invoke(null, $hash, self::UA, $now);
    }
}
