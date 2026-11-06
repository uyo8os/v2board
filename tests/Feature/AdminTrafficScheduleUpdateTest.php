<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\TrafficResetService;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminTrafficScheduleUpdateTest extends TestCase
{
    private string $endpoint;
    private TrafficResetService $resets;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) $this->markTestSkipped('PDO SQLite required.');
        config(['app.timezone' => 'Asia/Shanghai', 'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'v2board.reset_traffic_method' => 1]);
        DB::purge('sqlite');
        DB::statement('CREATE TABLE v2_user (id INTEGER PRIMARY KEY, email TEXT, plan_id INTEGER, group_id INTEGER,
            expired_at INTEGER, u INTEGER DEFAULT 0, d INTEGER DEFAULT 0, transfer_enable INTEGER DEFAULT 100,
            device_limit INTEGER, speed_limit INTEGER, invite_user_id INTEGER, banned INTEGER DEFAULT 0,
            is_admin INTEGER DEFAULT 0, is_staff INTEGER DEFAULT 0, remarks TEXT, updated_at INTEGER,
            traffic_reset_anchor_at INTEGER, traffic_reset_offset INTEGER DEFAULT 0, traffic_reset_at INTEGER,
            traffic_reset_plan_id INTEGER, traffic_reset_method INTEGER)');
        DB::statement('CREATE TABLE v2_plan (id INTEGER PRIMARY KEY, name TEXT, reset_traffic_method INTEGER,
            group_id INTEGER, transfer_enable INTEGER, device_limit INTEGER, speed_limit INTEGER)');
        DB::statement('CREATE TABLE v2_order (id INTEGER PRIMARY KEY, user_id INTEGER, plan_id INTEGER, type INTEGER,
            period TEXT, status INTEGER, refund_amount INTEGER DEFAULT 0, surplus_order_ids TEXT, updated_at INTEGER)');
        DB::statement('CREATE TABLE v2_traffic_advance_log (id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,email TEXT NOT NULL,plan_id INTEGER NOT NULL,plan_name TEXT NOT NULL,
            deduct_seconds INTEGER NOT NULL,old_expired_at INTEGER NOT NULL,new_expired_at INTEGER NOT NULL,created_at INTEGER NOT NULL)');
        // Other isolated tests use different v2_user schemas in the same process.
        // Eloquent caches guardable columns per model class, independent of connection.
        (new \ReflectionProperty(User::class, 'guardableColumns'))->setValue(null, []);
        DB::table('v2_plan')->insert(['id' => 1, 'name' => 'month', 'reset_traffic_method' => 1,
            'group_id' => 1, 'transfer_enable' => 1]);
        DB::table('v2_user')->insert(['id' => 1, 'email' => 'offline@example.test', 'plan_id' => 1,
            'group_id' => 1, 'expired_at' => strtotime('+3 months'), 'u' => 40, 'd' => 60]);
        $this->resets = new TrafficResetService();
        $this->resets->advance(User::findOrFail(1), time());
        $this->endpoint = '';
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->getActionName() === 'App\\Http\\Controllers\\V1\\Admin\\UserController@update') {
                $this->endpoint = '/' . ltrim($route->uri(), '/');
                break;
            }
        }
        $this->assertNotSame('', $this->endpoint);
        $this->withoutMiddleware();
    }

    private function updateUser(array $changes): void
    {
        $this->postJson($this->endpoint, $changes + ['id' => 1, 'email' => 'offline@example.test',
            'banned' => 0, 'is_admin' => 0, 'is_staff' => 0])->assertOk()->assertJsonPath('data', true);
    }

    public function test_manual_expiry_edit_then_real_monthly_renewal_resets_on_the_new_date(): void
    {
        $now = time();
        $expiry = strtotime('+1 day', $now);
        $before = User::findOrFail(1);
        $this->assertNotNull($before->traffic_reset_at);
        $this->updateUser(['expired_at' => $expiry, 'plan_id' => 1]);
        $edited = User::findOrFail(1);
        $this->assertSame(TrafficResetService::EMPTY_SCHEDULE, $edited->only(TrafficResetService::FIELDS));
        $this->assertSame((int) $before->u + (int) $before->d, (int) $edited->u + (int) $edited->d);

        DB::table('v2_order')->insert(['id' => 1, 'user_id' => 1, 'plan_id' => 1, 'type' => 2,
            'period' => 'month_price', 'status' => 1]);
        (new OrderService(Order::findOrFail(1)))->open();
        $renewed = User::findOrFail(1);
        $this->assertSame(strtotime('+1 month', $expiry), (int) $renewed->expired_at);
        $this->assertSame(1, (new UserService())->getResetDay($renewed, $now));
        DB::table('v2_user')->where('id', 1)->update(['u' => 12]);
        $due = strtotime(date('Y-m-d 00:00:00', $expiry));
        $this->assertTrue($this->resets->resetLegacy(User::findOrFail(1), $due));
        $this->assertSame(0, (int) User::findOrFail(1)->u);
    }

    public function test_unrelated_edits_and_equal_string_values_preserve_the_schedule(): void
    {
        $user = User::findOrFail(1);
        $original = $user->only(TrafficResetService::FIELDS);
        $this->updateUser(['remarks' => 'changed']);
        $this->assertSame($original, User::findOrFail(1)->only(TrafficResetService::FIELDS));
        $this->updateUser(['expired_at' => (string) $user->expired_at, 'plan_id' => '1']);
        $this->assertSame($original, User::findOrFail(1)->only(TrafficResetService::FIELDS));
    }

    public function test_plan_change_and_null_expiry_clear_only_when_values_change(): void
    {
        DB::table('v2_plan')->insert(['id' => 2, 'name' => 'other', 'reset_traffic_method' => 1, 'group_id' => 2]);
        $this->updateUser(['plan_id' => 2]);
        $this->assertSame(TrafficResetService::EMPTY_SCHEDULE, User::findOrFail(1)->only(TrafficResetService::FIELDS));
    }

    public function test_clearing_expiry_removes_a_valid_personal_schedule(): void
    {
        $this->assertNotNull(User::findOrFail(1)->traffic_reset_at);
        $this->updateUser(['expired_at' => null]);
        $user = User::findOrFail(1);
        $this->assertNull($user->expired_at);
        $this->assertSame(TrafficResetService::EMPTY_SCHEDULE, $user->only(TrafficResetService::FIELDS));
    }

    public function test_clearing_plan_removes_a_valid_personal_schedule(): void
    {
        $this->assertNotNull(User::findOrFail(1)->traffic_reset_at);
        $this->updateUser(['plan_id' => null]);
        $user = User::findOrFail(1);
        $this->assertNull($user->plan_id);
        $this->assertSame(TrafficResetService::EMPTY_SCHEDULE, $user->only(TrafficResetService::FIELDS));
    }

    public function test_null_and_omitted_fields_are_equivalent_to_existing_null_values(): void
    {
        $user = new User();
        $user->expired_at = null;
        $user->plan_id = null;
        $this->assertSame([], TrafficResetService::scheduleFieldsForManualUpdate($user, []));
        $this->assertSame([], TrafficResetService::scheduleFieldsForManualUpdate($user, ['expired_at' => null, 'plan_id' => null]));
    }
}
