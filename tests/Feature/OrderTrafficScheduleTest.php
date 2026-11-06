<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\OrderService;
use App\Services\TrafficResetService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Exercises order activation against an isolated database. */
class OrderTrafficScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) $this->markTestSkipped('PDO SQLite required.');
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'v2board.new_order_event_id' => 0, 'v2board.renew_order_event_id' => 0,
            'v2board.change_order_event_id' => 0, 'v2board.reset_traffic_method' => 1]);
        DB::purge('sqlite');
        DB::statement('CREATE TABLE v2_user (id INTEGER PRIMARY KEY, plan_id INTEGER, group_id INTEGER,
            expired_at INTEGER, u INTEGER, d INTEGER, balance INTEGER, transfer_enable INTEGER,
            device_limit INTEGER, speed_limit INTEGER, updated_at INTEGER, email TEXT, traffic_reset_anchor_at INTEGER,
            traffic_reset_offset INTEGER NOT NULL DEFAULT 0, traffic_reset_at INTEGER,
            traffic_reset_plan_id INTEGER, traffic_reset_method INTEGER)');
        DB::statement('CREATE TABLE v2_plan (id INTEGER PRIMARY KEY, name TEXT, group_id INTEGER, transfer_enable INTEGER,
            device_limit INTEGER, speed_limit INTEGER, reset_traffic_method INTEGER)');
        DB::statement('CREATE TABLE v2_order (id INTEGER PRIMARY KEY, user_id INTEGER, plan_id INTEGER,
            type INTEGER, period TEXT, status INTEGER, total_amount INTEGER, refund_amount INTEGER,
            surplus_order_ids TEXT, updated_at INTEGER)');
        DB::statement('CREATE TABLE v2_traffic_advance_log (id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER, email TEXT, plan_id INTEGER, plan_name TEXT, deduct_seconds INTEGER,
            old_expired_at INTEGER, new_expired_at INTEGER, created_at INTEGER)');
        DB::table('v2_plan')->insert([
            ['id' => 1, 'group_id' => 1, 'transfer_enable' => 160, 'device_limit' => 5, 'speed_limit' => null, 'reset_traffic_method' => 1],
            ['id' => 2, 'group_id' => 2, 'transfer_enable' => 160, 'device_limit' => 5, 'speed_limit' => null, 'reset_traffic_method' => 1],
        ]);
    }

    private function prepareOrder(int $type, string $period, int $plan = 1, ?int $expiry = null, int $status = 1): array
    {
        $schedule = ['traffic_reset_anchor_at' => time() + 3600, 'traffic_reset_offset' => 60,
            'traffic_reset_at' => time() + 3600, 'traffic_reset_plan_id' => 1, 'traffic_reset_method' => 1];
        DB::table('v2_user')->insert($schedule + ['id' => 1, 'plan_id' => 1, 'group_id' => 1,
            'expired_at' => $expiry ?? time() + 86400, 'u' => 50, 'd' => 20, 'balance' => 0,
            'transfer_enable' => 100, 'device_limit' => 5]);
        DB::table('v2_order')->insert(['id' => 1, 'user_id' => 1, 'plan_id' => $plan, 'type' => $type,
            'period' => $period, 'status' => $status, 'total_amount' => 0, 'refund_amount' => 0]);
        return $schedule;
    }

    private function activate(): void
    {
        (new OrderService(Order::findOrFail(1)))->open();
    }

    private function assertSchedule(array $expected): void
    {
        $saved = (array) DB::table('v2_user')->where('id', 1)->first();
        foreach ($expected as $field => $value) $this->assertSame($value, $saved[$field], $field);
    }

    public function test_new_purchase_of_same_plan_clears_the_old_schedule_and_uses_new_expiry(): void
    {
        $this->prepareOrder(1, 'month_price', 1, time() - 86400);
        $this->activate();
        $this->assertSchedule(TrafficResetService::EMPTY_SCHEDULE);
        $user = \App\Models\User::findOrFail(1);
        $this->assertSame(0, (int) $user->u + (int) $user->d);
        $this->assertNull((new TrafficResetService())->nextResetAt($user));
    }

    public function test_plan_change_clears_old_schedule(): void
    {
        $this->prepareOrder(3, 'month_price', 2);
        $this->activate();
        $this->assertSchedule(TrafficResetService::EMPTY_SCHEDULE);
        $this->assertSame(2, (int) DB::table('v2_user')->where('id', 1)->value('plan_id'));
    }

    public function test_new_order_clears_old_schedule_even_if_same_plan_is_active_at_activation(): void
    {
        $this->prepareOrder(1, 'month_price', 1, time() + 86400);
        $this->activate();
        $this->assertSchedule(TrafficResetService::EMPTY_SCHEDULE);
        $this->assertSame(0, (int) DB::table('v2_user')->where('id', 1)->value('u'));
        $this->assertSame(0, (int) DB::table('v2_user')->where('id', 1)->value('d'));
    }

    public function test_new_purchase_then_renewal_resets_on_the_new_cycle(): void
    {
        $this->prepareOrder(1, 'month_price', 1, strtotime('+4 months'));
        DB::table('v2_user')->where('id', 1)->update(TrafficResetService::EMPTY_SCHEDULE + [
            'u' => 100, 'd' => 0, 'email' => 'offline@example.test',
        ]);
        $resets = new TrafficResetService();
        $resets->advance(\App\Models\User::findOrFail(1), time() - 3600);
        $this->assertNotNull(DB::table('v2_user')->where('id', 1)->value('traffic_reset_anchor_at'));
        $this->assertSame(1, DB::table('v2_traffic_advance_log')->count());

        // After the advanced subscription expires, a new purchase starts a fresh cycle.
        DB::table('v2_user')->where('id', 1)->update(['expired_at' => time() - 86400]);
        $this->activate();
        $this->assertSchedule(TrafficResetService::EMPTY_SCHEDULE);
        $firstExpiry = (int) DB::table('v2_user')->where('id', 1)->value('expired_at');
        DB::table('v2_order')->insert(['id' => 2, 'user_id' => 1, 'plan_id' => 1, 'type' => 2,
            'period' => 'month_price', 'status' => 1, 'total_amount' => 0, 'refund_amount' => 0]);
        (new OrderService(Order::findOrFail(2)))->open();
        $this->assertSchedule(TrafficResetService::EMPTY_SCHEDULE);
        $this->assertSame(strtotime('+1 month', $firstExpiry), (int) DB::table('v2_user')->where('id', 1)->value('expired_at'));
        DB::table('v2_user')->where('id', 1)->update(['u' => 50, 'd' => 20]);
        $user = \App\Models\User::findOrFail(1);
        $next = $resets->nextResetAt($user);
        $this->assertNotNull($next);
        $this->assertTrue($resets->resetLegacy($user, $next));
        $this->assertSame(0, (int) DB::table('v2_user')->where('id', 1)->value('u'));
        $this->assertSame(0, (int) DB::table('v2_user')->where('id', 1)->value('d'));
    }

    public function test_one_time_purchase_clears_old_schedule(): void
    {
        $this->prepareOrder(2, 'onetime_price');
        $this->activate();
        $this->assertSchedule(TrafficResetService::EMPTY_SCHEDULE);
        $this->assertNull(DB::table('v2_user')->where('id', 1)->value('expired_at'));
    }

    public function test_active_same_plan_renewal_preserves_schedule(): void
    {
        $expiry = time() + 2 * 86400;
        $schedule = $this->prepareOrder(2, 'month_price', 1, $expiry);
        $this->activate();
        $this->assertSchedule($schedule);
        $user = DB::table('v2_user')->where('id', 1)->first();
        $this->assertSame(strtotime('+1 month', $expiry), (int) $user->expired_at);
        $this->assertSame(50, (int) $user->u);
        $this->assertSame(20, (int) $user->d);
    }

    public function test_expired_renewal_order_clears_stale_schedule(): void
    {
        $this->prepareOrder(2, 'month_price', 1, time() - 86400);
        $this->activate();
        $this->assertSchedule(TrafficResetService::EMPTY_SCHEDULE);
    }

    public function test_traffic_reset_order_keeps_schedule(): void
    {
        $expiry = time() + 2 * 86400;
        $schedule = $this->prepareOrder(4, 'reset_price', 1, $expiry);
        $this->activate();
        $this->assertSchedule($schedule);
        $user = DB::table('v2_user')->where('id', 1)->first();
        $this->assertSame($expiry, (int) $user->expired_at);
        $this->assertSame(0, (int) $user->u);
        $this->assertSame(0, (int) $user->d);
    }

    public function test_failed_order_transition_rolls_back_schedule_changes(): void
    {
        $schedule = $this->prepareOrder(1, 'month_price', 1, time() - 86400, 0);
        $this->activate();
        $this->assertSchedule($schedule);
        $this->assertSame(0, DB::transactionLevel());
    }

}
