<?php

namespace Tests\Feature;

use App\Jobs\OrderHandleJob;
use App\Utils\CacheKey;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConsumptionRestrictionOrderTest extends TestCase
{
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) $this->markTestSkipped('PDO SQLite required.');
        config([
            'app.key' => str_repeat('offline-consumption-test-', 3),
            'cache.default' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        DB::purge('sqlite');
        Cache::flush();
        DB::statement('CREATE TABLE v2_user (id INTEGER PRIMARY KEY, email TEXT, is_admin INTEGER DEFAULT 0, is_staff INTEGER DEFAULT 0, banned INTEGER DEFAULT 0, plan_id INTEGER, expired_at INTEGER, auto_renewal INTEGER DEFAULT 0, balance INTEGER DEFAULT 0, discount INTEGER DEFAULT 0, invite_user_id INTEGER, created_at INTEGER, updated_at INTEGER)');
        DB::statement("ALTER TABLE v2_user ADD auth_version TEXT NOT NULL DEFAULT ''");
        DB::statement('CREATE TABLE v2_insider_guard_consumption_restrict (user_id INTEGER PRIMARY KEY, admin_id INTEGER, reason TEXT, created_at INTEGER)');
        DB::statement('CREATE TABLE v2_plan (id INTEGER PRIMARY KEY, name TEXT, renew INTEGER, month_price INTEGER)');
        DB::statement('CREATE TABLE v2_order (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, plan_id INTEGER, period TEXT, trade_no TEXT, total_amount INTEGER DEFAULT 0, balance_amount INTEGER DEFAULT 0, discount_amount INTEGER DEFAULT 0, type INTEGER, status INTEGER DEFAULT 0, invite_user_id INTEGER, paid_at INTEGER, callback_no TEXT, payment_id INTEGER, coupon_id INTEGER, created_at INTEGER, updated_at INTEGER)');
        DB::statement('CREATE TABLE v2_payment (id INTEGER PRIMARY KEY, enable INTEGER)');
        DB::table('v2_user')->insert(['id' => 2, 'email' => 'user@example.test', 'plan_id' => 1, 'expired_at' => time() + 86400, 'auto_renewal' => 1, 'balance' => 5000]);
        DB::table('v2_plan')->insert(['id' => 1, 'name' => '套餐', 'renew' => 1, 'month_price' => 1000]);
        foreach ($this->app['router']->getRoutes() as $route) {
            $name = $route->getActionName();
            if (str_starts_with($name, 'App\\Http\\Controllers\\V1\\User\\OrderController@')) {
                $this->routes[substr($name, strrpos($name, '@') + 1)] = '/' . ltrim($route->uri(), '/');
            }
        }
        $session = 'offline-consumption-session';
        $jwt = JWT::encode(['id' => 2, 'session' => $session], config('app.key'), 'HS256');
        Cache::forever(CacheKey::get('USER_SESSIONS', 2), [$session => ['auth_data' => $jwt]]);
        $this->withHeaders(['authorization' => $jwt, 'User-Agent' => 'OfflineConsumptionTest']);
    }

    private function restrict(): void
    {
        DB::table('v2_insider_guard_consumption_restrict')->insert(['user_id' => 2, 'admin_id' => 1, 'reason' => '人工审核', 'created_at' => time()]);
    }

    public function test_new_orders_are_rejected_before_balance_or_order_changes(): void
    {
        $this->restrict();
        DB::table('v2_plan')->where('id', 1)->update(['month_price' => 0]);
        foreach ([
            ['plan_id' => 0, 'period' => 'deposit', 'deposit_amount' => 1000],
            ['plan_id' => 1, 'period' => 'month_price'],
            ['plan_id' => 2, 'period' => 'month_price'],
            ['plan_id' => 1, 'period' => 'reset_price'],
            ['plan_id' => 1, 'period' => 'onetime_price'],
        ] as $request) {
            $this->postJson($this->routes['save'], $request)->assertStatus(422)
                ->assertJsonPath('message', '当前账号已限制消费');
        }
        DB::table('v2_user')->where('id', 2)->update(['plan_id' => null]);
        $this->postJson($this->routes['save'], ['plan_id' => 1, 'period' => 'month_price'])->assertStatus(422);
        $this->assertDatabaseCount('v2_order', 0);
        $this->assertSame(5000, DB::table('v2_user')->where('id', 2)->value('balance'));
        DB::table('v2_insider_guard_consumption_restrict')->delete();
        $this->postJson($this->routes['save'], ['plan_id' => 0, 'period' => 'deposit', 'deposit_amount' => 1000])->assertOk();
        $this->assertDatabaseHas('v2_order', ['user_id' => 2, 'period' => 'deposit', 'total_amount' => 1000]);
    }

    public function test_preexisting_zero_order_checkout_and_cancel_remain_available(): void
    {
        Queue::fake();
        DB::table('v2_order')->insert([
            ['user_id' => 2, 'plan_id' => 1, 'period' => 'month_price', 'trade_no' => 'existing-free', 'total_amount' => 0, 'status' => 0],
            ['user_id' => 2, 'plan_id' => 1, 'period' => 'month_price', 'trade_no' => 'existing-cancel', 'total_amount' => 1000, 'status' => 0],
        ]);
        $this->restrict();
        $this->postJson($this->routes['checkout'], ['trade_no' => 'existing-free'])->assertOk()->assertJsonPath('data', true);
        Queue::assertPushed(OrderHandleJob::class);
        $this->postJson($this->routes['cancel'], ['trade_no' => 'existing-cancel'])->assertOk()->assertJsonPath('data', true);
        $this->assertDatabaseHas('v2_order', ['trade_no' => 'existing-free', 'status' => 1]);
        $this->assertDatabaseHas('v2_order', ['trade_no' => 'existing-cancel', 'status' => 2]);
    }

    public function test_auto_renewal_skips_restricted_user_and_resumes_after_release(): void
    {
        DB::table('v2_order')->insert(['user_id' => 2, 'plan_id' => 1, 'period' => 'month_price', 'trade_no' => 'old-order', 'status' => 3, 'created_at' => time() - 100]);
        $oldExpiry = DB::table('v2_user')->where('id', 2)->value('expired_at');
        $this->restrict();
        Artisan::call('check:renewal');
        $this->assertDatabaseCount('v2_order', 1);
        $this->assertDatabaseHas('v2_user', ['id' => 2, 'auto_renewal' => 1, 'balance' => 5000, 'expired_at' => $oldExpiry]);
        DB::table('v2_insider_guard_consumption_restrict')->delete();
        Artisan::call('check:renewal');
        $this->assertDatabaseCount('v2_order', 2);
        $this->assertDatabaseHas('v2_user', ['id' => 2, 'auto_renewal' => 1, 'balance' => 4000, 'expired_at' => strtotime('+1 month', $oldExpiry)]);
    }

    public function test_unavailable_restriction_state_does_not_disable_auto_renewal(): void
    {
        DB::statement('DROP TABLE v2_insider_guard_consumption_restrict');
        Artisan::call('check:renewal');
        $this->assertDatabaseCount('v2_order', 0);
        $this->assertDatabaseHas('v2_user', ['id' => 2, 'auto_renewal' => 1, 'balance' => 5000]);
        $this->postJson($this->routes['save'], ['plan_id' => 0, 'period' => 'deposit', 'deposit_amount' => 1000])->assertStatus(503);
    }
}
