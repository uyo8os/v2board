<?php

namespace Tests\Feature;

use App\Utils\CacheKey;
use App\Services\InsiderGuardService;
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
        DB::statement("CREATE TABLE v2_insider_guard_restrict (user_id INTEGER PRIMARY KEY, admin_id INTEGER, reason TEXT, created_at INTEGER, mode TEXT NOT NULL DEFAULT 'decoy')");
        DB::statement('CREATE TABLE v2_insider_guard_node_exclusion (user_id INTEGER, node_type TEXT, node_id INTEGER, PRIMARY KEY(user_id,node_type,node_id))');
        foreach (['shadowsocks', 'vmess', 'trojan', 'tuic', 'hysteria', 'vless', 'anytls', 'v2node'] as $type) {
            DB::statement("CREATE TABLE v2_server_{$type} (id INTEGER PRIMARY KEY, name TEXT)");
        }
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

    public function test_node_exclusion_switches_modes_preserves_audit_and_matches_type_and_id(): void
    {
        DB::table('v2_server_vmess')->insert(['id' => 1, 'name' => 'VMess 节点']);
        DB::table('v2_server_trojan')->insert(['id' => 1, 'name' => 'Trojan 节点']);
        $routes = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            $routes[$route->getActionName()] = '/' . ltrim($route->uri(), '/');
        }
        $prefix = 'App\\Http\\Controllers\\V1\\Admin\\InsiderGuardController@';
        $auth = ['authorization' => $this->token(1)];
        $nodes = json_encode([['node_type' => 'vmess', 'node_id' => 1]]);
        $body = ['id' => 2, 'nodes' => $nodes, 'reason' => '根据风险审查排除该节点'];
        $this->postJson($routes[$prefix . 'nodeExclusions'], $body, $auth)->assertOk();
        $response = $this->getJson($this->endpoint, $auth)->assertOk()
            ->assertJsonPath('data.0.mode', 'exclude')
            ->assertJsonPath('data.0.excluded_nodes.0.node_type', 'vmess')
            ->assertJsonPath('data.0.excluded_nodes.0.node_name', 'VMess 节点');
        $this->assertStringContainsString('vmess:1', DB::table('v2_insider_guard_review')->where('action', 'node_exclude')->value('reason'));
        $servers = [['type' => 'vmess', 'id' => 1], ['type' => 'trojan', 'id' => 1], ['type' => 'vmess', 'id' => 2]];
        $decoy = null;
        $this->assertSame(array_slice($servers, 1), InsiderGuardService::filterExcludedNodes($servers, 2, $decoy));
        $this->assertFalse($decoy);
        DB::table('v2_server_vmess')->where('id', 1)->delete();
        $this->getJson($this->endpoint, $auth)->assertOk()->assertJsonPath('data.0.excluded_nodes.0.node_name', null);
        $this->postJson($routes[$prefix . 'restrict'], ['id' => 2, 'confirm' => 1, 'reason' => '根据风险审查改用假入口限制'], $auth)->assertOk();
        $this->assertDatabaseCount('v2_insider_guard_node_exclusion', 0);
        $this->assertSame($servers, InsiderGuardService::filterExcludedNodes($servers, 2, $decoy));
        $this->assertTrue($decoy);
        DB::table('v2_server_vmess')->insert(['id' => 1, 'name' => 'VMess 节点']);
        $this->postJson($routes[$prefix . 'nodeExclusions'], $body, $auth)->assertOk();
        $this->getJson($this->endpoint, $auth)->assertOk()->assertJsonPath('data.0.mode', 'exclude');
        $this->postJson($this->releaseEndpoint, ['id' => 2, 'reason' => '确认用户情况后解除限制'], $auth)->assertOk();
        $this->assertDatabaseCount('v2_insider_guard_node_exclusion', 0);
        $this->assertSame($servers, InsiderGuardService::filterExcludedNodes($servers, 2, $decoy));
        $this->assertFalse($decoy);
    }

    public function test_node_exclusion_rejects_bad_nodes_and_unauthorized_requests(): void
    {
        $endpoint = null;
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->getActionName() === 'App\\Http\\Controllers\\V1\\Admin\\InsiderGuardController@nodeExclusions') {
                $endpoint = '/' . ltrim($route->uri(), '/');
            }
        }
        $body = ['id' => 2, 'nodes' => '[]', 'reason' => '根据风险审查排除该节点'];
        $this->postJson($endpoint, $body)->assertForbidden();
        $this->postJson($endpoint, $body, ['authorization' => $this->token(2)])->assertForbidden();
        $auth = ['authorization' => $this->token(1)];
        $this->postJson($endpoint, $body, $auth)->assertStatus(422);
        $body['nodes'] = '[{"node_type":"vmess","node_id":1}]';
        $this->postJson($endpoint, $body, $auth)->assertStatus(422);
        $body['nodes'] = '[{"node_type":"invalid","node_id":1}]';
        $this->postJson($endpoint, $body, $auth)->assertStatus(422);
        $this->assertDatabaseCount('v2_insider_guard_restrict', 0);
    }

    public function test_invalid_or_unavailable_restriction_state_fails_closed(): void
    {
        DB::table('v2_insider_guard_restrict')->insert(['user_id' => 2, 'admin_id' => 1, 'reason' => 'test', 'created_at' => time(), 'mode' => 'invalid']);
        try {
            InsiderGuardService::filterExcludedNodes([], 2);
            $this->fail('Invalid restriction mode must fail closed.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        DB::statement('DROP TABLE v2_insider_guard_node_exclusion');
        try {
            InsiderGuardService::filterExcludedNodes([], 2);
            $this->fail('Missing restriction table must fail closed.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
    }

    public function test_failed_node_exclusion_write_rolls_back_mode_and_audit(): void
    {
        DB::table('v2_server_vmess')->insert(['id' => 1, 'name' => '节点']);
        DB::table('v2_insider_guard_restrict')->insert(['user_id' => 2, 'admin_id' => 1, 'reason' => '旧原因', 'created_at' => 1, 'mode' => 'decoy']);
        DB::unprepared("CREATE TRIGGER reject_exclusion BEFORE INSERT ON v2_insider_guard_node_exclusion BEGIN SELECT RAISE(ABORT, 'failed'); END");
        $endpoint = null;
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->getActionName() === 'App\\Http\\Controllers\\V1\\Admin\\InsiderGuardController@nodeExclusions') $endpoint = '/' . ltrim($route->uri(), '/');
        }
        $this->postJson($endpoint, ['id' => 2, 'nodes' => '[{"node_type":"vmess","node_id":1}]',
            'reason' => '根据风险审查排除该节点'], ['authorization' => $this->token(1)])->assertStatus(500);
        $this->assertDatabaseHas('v2_insider_guard_restrict', ['user_id' => 2, 'mode' => 'decoy', 'reason' => '旧原因']);
        $this->assertDatabaseCount('v2_insider_guard_node_exclusion', 0);
        $this->assertDatabaseCount('v2_insider_guard_review', 0);
    }
}
