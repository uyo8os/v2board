<?php

namespace Tests\Feature;

use App\Utils\CacheKey;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InsiderGuardRestrictionsTest extends TestCase
{
    private string $endpoint;
    private string $releaseEndpoint;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) $this->markTestSkipped('PDO SQLite required.');
        config([
            'app.key' => str_repeat('offline-guard-admin-', 3),
            'cache.default' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        DB::purge('sqlite');
        Cache::flush();
        DB::statement('CREATE TABLE v2_user (id INTEGER PRIMARY KEY, email TEXT, is_admin INTEGER DEFAULT 0, is_staff INTEGER DEFAULT 0, banned INTEGER DEFAULT 0)');
        DB::statement('CREATE TABLE v2_insider_guard_restrict (user_id INTEGER PRIMARY KEY, admin_id INTEGER, reason TEXT, created_at INTEGER)');
        DB::statement('CREATE TABLE v2_insider_guard_review (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, admin_id INTEGER, action TEXT, reason TEXT, created_at INTEGER)');
        DB::table('v2_user')->insert([
            ['id' => 1, 'email' => 'admin@example.test', 'is_admin' => 1],
            ['id' => 2, 'email' => 'ordinary@example.test', 'is_admin' => 0],
        ]);
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->getActionName() === 'App\\Http\\Controllers\\V1\\Admin\\InsiderGuardController@restrictions') {
                $this->assertContains('admin', $route->gatherMiddleware());
                $this->endpoint = '/' . ltrim($route->uri(), '/');
            }
            if ($route->getActionName() === 'App\\Http\\Controllers\\V1\\Admin\\InsiderGuardController@release') {
                $this->releaseEndpoint = '/' . ltrim($route->uri(), '/');
            }
        }
        $this->assertNotEmpty($this->endpoint ?? null);
        $this->assertNotEmpty($this->releaseEndpoint ?? null);
        $this->withHeaders(['User-Agent' => 'OfflineAdminTest', 'Origin' => 'https://admin.example.test']);
    }

    private function token(int $id): string
    {
        $session = 'offline-guard-session-' . $id;
        $jwt = JWT::encode(['id' => $id, 'session' => $session], config('app.key'), 'HS256');
        Cache::forever(CacheKey::get('USER_SESSIONS', $id), [$session => ['auth_data' => $jwt]]);
        return $jwt;
    }

    public function test_only_admin_can_access_the_list(): void
    {
        $this->getJson($this->endpoint)->assertForbidden();
        $this->getJson($this->endpoint, ['authorization' => $this->token(2)])->assertForbidden();
        $this->getJson($this->endpoint, ['authorization' => $this->token(1)])->assertOk()->assertJsonPath('total', 0);
    }

    public function test_pagination_deleted_users_and_release_reflect_current_restrictions(): void
    {
        for ($id = 2; $id <= 26; $id++) {
            DB::table('v2_insider_guard_restrict')->insert(['user_id' => $id, 'admin_id' => 1, 'reason' => '测试原因', 'created_at' => 1000]);
        }
        $auth = ['authorization' => $this->token(1)];
        $response = $this->getJson($this->endpoint . '?current=1&pageSize=10', $auth)
            ->assertOk()->assertJsonPath('total', 25)->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.user_id', 26)->assertJsonPath('data.0.email', null)
            ->assertHeader('Pragma', 'no-cache');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson($this->endpoint . '?current=3&pageSize=10', $auth)
            ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.4.email', 'ordinary@example.test');
        $this->getJson($this->endpoint . '?current=4&pageSize=10', $auth)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->endpoint . '?pageSize=1000', $auth)->assertOk()->assertJsonCount(25, 'data');
        $this->postJson($this->releaseEndpoint, ['id' => 2, 'reason' => '确认用户情况后解除限制'], $auth)->assertOk();
        $this->getJson($this->endpoint . '?current=3&pageSize=10', $auth)
            ->assertOk()->assertJsonPath('total', 24)->assertJsonCount(4, 'data');
        $this->assertDatabaseHas('v2_insider_guard_review', ['user_id' => 2, 'action' => 'release']);
    }

    public function test_invalid_pagination_is_rejected(): void
    {
        $auth = ['authorization' => $this->token(1)];
        foreach (['current=0', 'current=-1', 'current[]=1', 'pageSize=abc', 'pageSize=2147483648'] as $query) {
            $this->getJson($this->endpoint . '?' . $query, $auth)->assertStatus(422);
        }
    }
}
