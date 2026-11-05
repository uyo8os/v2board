<?php

namespace Tests\Feature;

use App\Services\TrafficResetService;
use App\Utils\CacheKey;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Real admin middleware and HTTP routes; isolated in-memory database and sessions. */
class TrafficAdvanceAdminTest extends TestCase
{
    private string $endpoint;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) $this->markTestSkipped('PDO SQLite required.');
        config([
            'app.key' => str_repeat('offline-advance-admin-', 3),
            'cache.default' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        DB::purge('sqlite');
        Cache::flush();
        DB::statement('CREATE TABLE v2_user (id INTEGER PRIMARY KEY,email TEXT,is_admin INTEGER DEFAULT 0,
            is_staff INTEGER DEFAULT 0,banned INTEGER DEFAULT 0,plan_id INTEGER DEFAULT 9,
            expired_at INTEGER DEFAULT 1796486400,u INTEGER DEFAULT 5,d INTEGER DEFAULT 6,
            transfer_enable INTEGER DEFAULT 100,traffic_reset_anchor_at INTEGER DEFAULT 1796486400,
            traffic_reset_offset INTEGER DEFAULT 43201,traffic_reset_at INTEGER DEFAULT 1796443199,
            traffic_reset_plan_id INTEGER DEFAULT 9,traffic_reset_method INTEGER DEFAULT 1)');
        DB::table('v2_user')->insert([
            ['id' => 1, 'email' => 'admin@example.test', 'is_admin' => 1],
            ['id' => 2, 'email' => 'user@example.test', 'is_admin' => 0],
        ]);
        DB::statement('CREATE TABLE v2_plan (id INTEGER PRIMARY KEY,name TEXT)');
        DB::statement('CREATE TABLE v2_traffic_advance_log (id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,email TEXT NOT NULL,plan_id INTEGER NOT NULL,plan_name TEXT NOT NULL,
            deduct_seconds INTEGER NOT NULL,old_expired_at INTEGER NOT NULL,new_expired_at INTEGER NOT NULL,created_at INTEGER NOT NULL)');

        $this->endpoint = '';
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->getActionName() !== 'App\\Http\\Controllers\\V1\\Admin\\TrafficAdvanceController@fetch') continue;
            $this->assertContains('GET', $route->methods());
            $this->assertContains('admin', $route->gatherMiddleware());
            $this->endpoint = '/' . ltrim($route->uri(), '/');
            break;
        }
        $this->assertNotSame('', $this->endpoint, 'The actual protected admin route must be registered.');
        $this->withHeaders(['User-Agent' => 'OfflineAdminTest', 'Origin' => 'https://admin.example.test']);
    }

    private function token(int $userId): string
    {
        $session = 'offline-advance-session-' . $userId;
        $jwt = JWT::encode(['id' => $userId, 'session' => $session], config('app.key'), 'HS256');
        Cache::forever(CacheKey::get('USER_SESSIONS', $userId), [$session => ['auth_data' => $jwt]]);
        return $jwt;
    }

    private function record(int $id, array $extra = []): array
    {
        $record = $extra + [
            'id' => $id, 'user_id' => 2, 'email' => 'snapshot@example.test', 'plan_id' => 9,
            'plan_name' => '操作时订阅', 'deduct_seconds' => 43201,
            'old_expired_at' => 1796486400, 'new_expired_at' => 1796443199,
            'created_at' => 1793836800 - $id,
        ];
        DB::table(TrafficResetService::ADVANCE_LOG_TABLE)->insert($record);
        return $record;
    }

    public function test_anonymous_and_regular_user_cannot_read_records(): void
    {
        $this->record(1);
        $this->getJson($this->endpoint . '?user[is_admin]=1')->assertForbidden();
        $this->getJson($this->endpoint, ['authorization' => $this->token(2)])->assertForbidden();
    }

    public function test_admin_gets_requested_page_by_descending_id_and_total(): void
    {
        for ($id = 1; $id <= 12; $id++) $this->record($id);
        $response = $this->getJson($this->endpoint . '?current=2&pageSize=5', ['authorization' => $this->token(1)])
            ->assertOk()->assertJsonPath('total', 12)->assertJsonCount(5, 'data')
            ->assertHeader('Pragma', 'no-cache');
        $this->assertSame([7, 6, 5, 4, 3], array_column($response->json('data'), 'id'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertEqualsCanonicalizing([
            'id', 'user_id', 'created_at', 'email', 'plan_id', 'plan_name',
            'deduct_seconds', 'old_expired_at', 'new_expired_at',
        ], array_keys($response->json('data.0')));
    }

    public function test_default_pagination_returns_first_ten_records(): void
    {
        for ($id = 1; $id <= 12; $id++) $this->record($id);
        $response = $this->getJson($this->endpoint, ['authorization' => $this->token(1)])
            ->assertOk()->assertJsonPath('total', 12)->assertJsonCount(10, 'data');
        $this->assertSame(range(12, 3), array_column($response->json('data'), 'id'));
    }

    public function test_empty_records_return_empty_page(): void
    {
        $this->getJson($this->endpoint, ['authorization' => $this->token(1)])
            ->assertOk()->assertExactJson(['data' => [], 'total' => 0]);
    }

    public function test_invalid_pagination_is_rejected(): void
    {
        $headers = ['authorization' => $this->token(1)];
        $this->getJson($this->endpoint . '?pageSize=101', $headers)
            ->assertStatus(422)->assertJsonValidationErrors('pageSize');
        $this->getJson($this->endpoint . '?current=0', $headers)
            ->assertStatus(422)->assertJsonValidationErrors('current');
    }

    public function test_email_and_plan_snapshots_survive_source_changes_and_deletion(): void
    {
        DB::table('v2_plan')->insert(['id' => 9, 'name' => '当前订阅']);
        $record = $this->record(1);
        $headers = ['authorization' => $this->token(1)];
        DB::table('v2_user')->where('id', 2)->update(['email' => 'changed@example.test']);
        DB::table('v2_plan')->where('id', 9)->update(['name' => '已改名订阅']);
        $this->getJson($this->endpoint, $headers)->assertOk()->assertExactJson(['data' => [$record], 'total' => 1]);

        DB::table('v2_user')->where('id', 2)->delete();
        DB::table('v2_plan')->where('id', 9)->delete();
        $this->getJson($this->endpoint, $headers)->assertOk()->assertExactJson(['data' => [$record], 'total' => 1]);
    }

    private function clearEndpoint(): string
    {
        return substr($this->endpoint, 0, -strlen('/fetch')) . '/clear';
    }

    public function test_email_search_is_exact_trimmed_and_applies_to_the_total_and_page(): void
    {
        for ($id = 1; $id <= 6; $id++) $this->record($id, ['email' => 'wanted@example.test']);
        $this->record(7, ['email' => 'other@example.test']);
        $this->record(8, ['email' => 'prefix-wanted@example.test']);
        $headers = ['authorization' => $this->token(1)];
        $response = $this->getJson($this->endpoint . '?' . http_build_query([
            'email' => ' wanted@example.test ', 'current' => 2, 'pageSize' => 2,
        ]), $headers)->assertOk()->assertJsonPath('total', 6)->assertJsonCount(2, 'data');
        $this->assertSame([4, 3], array_column($response->json('data'), 'id'));
        $this->getJson($this->endpoint . '?email=%25', $headers)
            ->assertOk()->assertExactJson(['data' => [], 'total' => 0]);
    }

    public function test_cleanup_requires_admin_and_post_and_validates_email(): void
    {
        $this->record(1);
        $this->postJson($this->clearEndpoint())->assertForbidden();
        $this->postJson($this->clearEndpoint(), [], ['authorization' => $this->token(2)])->assertForbidden();
        $headers = ['authorization' => $this->token(1)];
        $this->getJson($this->clearEndpoint(), $headers)->assertStatus(405);
        $this->postJson($this->clearEndpoint(), ['email' => ['snapshot@example.test']], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->getJson($this->endpoint . '?email=' . str_repeat('a', 65), $headers)
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame(1, DB::table(TrafficResetService::ADVANCE_LOG_TABLE)->count());
    }

    public function test_cleanup_only_removes_matching_history_and_leaves_accounts_and_plans_intact(): void
    {
        $this->record(1);
        $other = $this->record(2, ['email' => 'other@example.test']);
        DB::table('v2_plan')->insert(['id' => 9, 'name' => '原订阅']);
        $usersBefore = DB::table('v2_user')->orderBy('id')->get()->toArray();
        $plansBefore = DB::table('v2_plan')->get()->toArray();
        $headers = ['authorization' => $this->token(1)];
        $this->postJson($this->clearEndpoint(), ['email' => ' snapshot@example.test '], $headers)
            ->assertOk()->assertExactJson(['data' => ['deleted' => 1]]);
        $this->assertEquals($usersBefore, DB::table('v2_user')->orderBy('id')->get()->toArray());
        $this->assertEquals($plansBefore, DB::table('v2_plan')->get()->toArray());
        $this->getJson($this->endpoint, $headers)->assertOk()->assertExactJson(['data' => [$other], 'total' => 1]);
        $this->postJson($this->clearEndpoint(), ['email' => 'snapshot@example.test'], $headers)
            ->assertOk()->assertExactJson(['data' => ['deleted' => 0]]);
        $this->record(3);
        $this->getJson($this->endpoint, $headers)->assertOk()->assertJsonPath('total', 2);
    }

    public function test_cleanup_is_bounded_and_removes_the_oldest_thousand_records_only(): void
    {
        $records = [];
        for ($id = 1; $id <= 1002; $id++) $records[] = [
            'id' => $id, 'user_id' => 2, 'email' => 'snapshot@example.test', 'plan_id' => 9,
            'plan_name' => '操作时订阅', 'deduct_seconds' => 43201, 'old_expired_at' => 1796486400,
            'new_expired_at' => 1796443199, 'created_at' => 1793836800,
        ];
        foreach (array_chunk($records, 100) as $batch) DB::table(TrafficResetService::ADVANCE_LOG_TABLE)->insert($batch);
        $headers = ['authorization' => $this->token(1)];
        $this->postJson($this->clearEndpoint(), [], $headers)
            ->assertOk()->assertExactJson(['data' => ['deleted' => 1000]]);
        $this->assertSame([1001, 1002], DB::table(TrafficResetService::ADVANCE_LOG_TABLE)->orderBy('id')->pluck('id')->all());
        $this->postJson($this->clearEndpoint(), [], $headers)
            ->assertOk()->assertExactJson(['data' => ['deleted' => 2]]);
        $this->postJson($this->clearEndpoint(), [], $headers)
            ->assertOk()->assertExactJson(['data' => ['deleted' => 0]]);
    }
}
