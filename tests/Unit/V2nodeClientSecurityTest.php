<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Client\ClientController;
use App\Http\Controllers\V1\User\ServerController;
use App\Http\Controllers\V2\Server\ServerController as NodeController;
use App\Http\Middleware\Client as ClientMiddleware;
use App\Http\Middleware\User as UserMiddleware;
use App\Models\User;
use App\Services\AuthService;
use App\Services\ServerService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Real middleware, JWT sessions, controllers and SQLite; synthetic keys, no .env or network. */
class V2nodeClientSecurityTest extends TestCase
{
    private $previousContainer;
    private $previousFacadeApplication;
    private $previousResolver;
    private ?Application $app = null;
    private Manager $database;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) $this->markTestSkipped('Requires PDO SQLite');
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Config([
            'app' => ['key' => str_repeat('offline-v2node-test-', 3)],
            'v2board' => ['server_token' => 'offline-node-token'],
        ]));
        $this->app->instance('cache', new Repository(new ArrayStore()));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->database = new Manager($this->app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->bootEloquent();
        $this->app->instance('db', $this->database->getDatabaseManager());
        $db = $this->database->getConnection();
        $db->statement('CREATE TABLE v2_user (id INTEGER PRIMARY KEY, email TEXT, uuid TEXT, token TEXT, is_admin INTEGER DEFAULT 0, is_staff INTEGER DEFAULT 0, banned INTEGER DEFAULT 0, group_id INTEGER, transfer_enable INTEGER, expired_at INTEGER, u INTEGER DEFAULT 0, d INTEGER DEFAULT 0)');
        foreach (['shadowsocks', 'vmess', 'trojan', 'tuic', 'hysteria', 'vless', 'anytls', 'v2node'] as $type) {
            if ($type !== 'v2node') $db->statement('CREATE TABLE v2_server_' . $type . ' (id INTEGER PRIMARY KEY, sort INTEGER)');
        }
        $db->statement('CREATE TABLE v2_server_v2node (id INTEGER PRIMARY KEY, name TEXT, host TEXT, port TEXT, sort INTEGER, show INTEGER, parent_id INTEGER, group_id TEXT, tls_settings TEXT, encryption_settings TEXT, protocol TEXT, tls INTEGER, encryption TEXT, flow TEXT, network TEXT, network_settings TEXT, route_id TEXT, created_at INTEGER, updated_at INTEGER)');
        // Minimal real tables used by subscription eligibility and delivery recording.
        $db->statement('CREATE TABLE v2_insider_guard_restrict (user_id INTEGER PRIMARY KEY)');
        $db->statement('CREATE TABLE v2_insider_guard_ua (ua_hash TEXT PRIMARY KEY, ua TEXT, first_seen INTEGER, last_seen INTEGER)');
        $db->statement('CREATE TABLE v2_insider_guard_activity (user_id INTEGER, event_type TEXT, hour_start INTEGER, ip_address TEXT, ua_hash TEXT, times INTEGER, node_count INTEGER, first_at INTEGER, last_at INTEGER, UNIQUE(user_id,event_type,hour_start,ip_address,ua_hash))');
        $db->statement('CREATE TABLE v2_insider_guard_node_user (user_id INTEGER, node_type TEXT, node_id INTEGER, times INTEGER, first_at INTEGER, last_at INTEGER, UNIQUE(user_id,node_type,node_id))');
        $db->table('v2_user')->insert([
            'id' => 7, 'email' => 'offline@example.test', 'uuid' => 'offline-user-uuid',
            'token' => 'offline-subscription-token', 'group_id' => 1,
            'transfer_enable' => 1000000, 'expired_at' => time() + 3600,
        ]);
        $responses = Mockery::mock(ResponseFactory::class);
        $responses->shouldReceive('make')->andReturnUsing(static fn ($data = '', $status = 200, $headers = []) => new Response($data, $status, $headers));
        $this->app->instance(ResponseFactory::class, $responses);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            $this->database->getDatabaseManager()->disconnect();
            if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver);
            else Model::unsetConnectionResolver();
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->previousFacadeApplication);
            Container::setInstance($this->previousContainer);
        }
        Mockery::close();
        parent::tearDown();
    }

    public function testOrdinaryUserFetchContainsOnlyVisibleNodesAndPublicTlsSettings(): void
    {
        $this->seedNode();
        $this->seedNode(['id' => 2, 'group_id' => '[2]', 'name' => 'Other group']);
        $this->seedNode(['id' => 3, 'show' => 0, 'name' => 'Hidden node']);
        $response = $this->fetch();
        $this->assertSame(200, $response->getStatusCode());
        $nodes = json_decode($response->getContent(), true)['data'];
        $this->assertSame([1], array_column($nodes, 'id'));
        $this->assertSame($this->clientTls(), $nodes[0]['tls_settings']);
        $this->assertSame(['password' => 'OFFLINE-ENCRYPTION-PUBLIC'], $nodes[0]['encryption_settings']);
        $this->assertSame('v2node', $nodes[0]['type']);
        $this->assertSame(443, $nodes[0]['port']);
        $this->assertSecretsAbsent($response->getContent());
    }

    public static function tlsShapes(): array
    {
        return [
            'null' => [null, null],
            'empty settings' => [[], []],
        ];
    }

    #[DataProvider('tlsShapes')]
    public function testAbsentTlsSettingsDoNotBreakNodeFetch($settings, $expected): void
    {
        $this->seedNode(['tls_settings' => $settings === null ? null : json_encode($settings)]);
        $nodes = json_decode($this->fetch()->getContent(), true)['data'];
        $this->assertCount(1, $nodes);
        $this->assertSame($expected, $nodes[0]['tls_settings']);
    }

    public function testAnonymousUserCannotReachNodeListing(): void
    {
        $called = false;
        try {
            (new UserMiddleware())->handle(Request::create('/api/v1/user/server/fetch'), static function () use (&$called) {
                $called = true;
            });
            $this->fail('Anonymous request reached node listing');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertFalse($called);
    }

    public function testGeneralSubscriptionRetainsRealityEchAndCertificatePinWithoutSecrets(): void
    {
        $this->seedNode();
        $content = base64_decode($this->subscribe('general'), true);
        $this->assertIsString($content);
        $this->assertStringContainsString('vless://offline-user-uuid@node.example.test:443?', $content);
        parse_str(parse_url(trim($content), PHP_URL_QUERY), $query);
        $this->assertSame('OFFLINE-REALITY-PUBLIC', $query['pbk']);
        $this->assertSame('OFFLINE-ECH-PUBLIC', $query['ech']);
        $this->assertSame(str_repeat('a', 64), $query['pcs']);
        $this->assertSame('node.example.test', $query['sni']);
        $this->assertSecretsAbsent($content);
        $this->assertSame(1, $this->database->getConnection()->table('v2_insider_guard_node_user')->count());
    }

    public function testSingboxSubscriptionRetainsClientTlsFieldsWithoutSecrets(): void
    {
        $this->seedNode();
        // Use a tiny controlled template rather than a site's custom subscription configuration.
        $root = sys_get_temp_dir() . '/v2node-client-test-' . bin2hex(random_bytes(8));
        mkdir($root . '/resources/rules', 0777, true);
        $template = $root . '/resources/rules/default.sing-box.json';
        file_put_contents($template, '{"outbounds":[]}');
        $this->app->setBasePath($root);
        try {
            $response = $this->subscribe('sing-box 1.12.0');
            $this->assertSame(200, $response->getStatusCode());
            $data = json_decode($response->getContent(), true);
            $this->assertCount(1, $data['outbounds']);
            $node = $data['outbounds'][0];
            $this->assertSame('vless', $node['type']);
            $this->assertSame('node.example.test', $node['tls']['server_name']);
            $this->assertSame('OFFLINE-REALITY-PUBLIC', $node['tls']['reality']['public_key']);
            $this->assertSame(['OFFLINE-ECH-PUBLIC'], $node['tls']['ech']['config']);
            $this->assertSecretsAbsent($response->getContent());
        } finally {
            $this->app->setBasePath(dirname(__DIR__, 2));
            unlink($template);
            rmdir(dirname($template));
            rmdir($root . '/resources');
            rmdir($root);
        }
    }

    public function testClientFilteringPreservesStoredSecretsForAdminAndAuthenticatedNodeConfig(): void
    {
        $this->seedNode();
        $db = $this->database->getConnection();
        $before = $db->table('v2_server_v2node')->where('id', 1)->first();
        $this->fetch();
        $this->assertEquals($before, $db->table('v2_server_v2node')->where('id', 1)->first());
        $admin = (new ServerService())->getAllV2node()[0];
        $this->assertSame($this->tls(), $admin['tls_settings']);
        $this->assertSame('OFFLINE-ENCRYPTION-PRIVATE', $admin['encryption_settings']['private_key']);
        $request = Request::create('/api/v2/server/config', 'GET', ['token' => 'offline-node-token', 'node_id' => 1]);
        $response = (new NodeController($request))->config($request);
        $this->assertSame(200, $response->getStatusCode());
        $node = json_decode($response->getContent(), true);
        $this->assertSame($this->tls(), $node['tls_settings']);
        $this->assertSame('OFFLINE-ENCRYPTION-PRIVATE', $node['encryption_settings']['private_key']);
    }

    private function fetch(): Response
    {
        $user = User::find(7);
        $this->assertSame(0, $user->is_admin);
        $this->assertSame(0, $user->is_staff);
        $jwt = (new AuthService($user))->generateAuthData(Request::create('/'))['auth_data'];
        $request = Request::create('/api/v1/user/server/fetch', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => $jwt, 'HTTP_IF_NONE_MATCH' => '']);
        return (new UserMiddleware())->handle($request, static fn ($request) => (new ServerController())->fetch($request));
    }

    private function subscribe(string $flag)
    {
        $request = Request::create('/api/v1/client/subscribe', 'GET', ['token' => 'offline-subscription-token', 'flag' => $flag]);
        return (new ClientMiddleware())->handle($request, static fn ($request) => (new ClientController())->subscribe($request));
    }

    private function seedNode(array $overrides = []): void
    {
        $this->database->getConnection()->table('v2_server_v2node')->insert(array_replace([
            'id' => 1, 'name' => 'Offline node', 'host' => 'node.example.test', 'port' => '443',
            'sort' => 1, 'show' => 1, 'parent_id' => 0, 'group_id' => '[1]', 'protocol' => 'vless',
            'tls' => 2, 'encryption' => '', 'flow' => '', 'network' => 'tcp', 'network_settings' => '{}',
            'tls_settings' => json_encode($this->tls()),
            'encryption_settings' => '{"private_key":"OFFLINE-ENCRYPTION-PRIVATE","password":"OFFLINE-ENCRYPTION-PUBLIC"}',
            'created_at' => 1000, 'updated_at' => 1000,
        ], $overrides));
    }

    private function clientTls(): array
    {
        return [
            'server_name' => 'node.example.test', 'allow_insecure' => 0, 'fingerprint' => 'chrome',
            'public_key' => 'OFFLINE-REALITY-PUBLIC', 'short_id' => '0123456789abcdef',
            'ech' => 'custom', 'ech_server_name' => null, 'ech_config' => ['OFFLINE-ECH-PUBLIC'],
            'pinned_peer_cert_sha256' => str_repeat('a', 64), 'tls_cert' => 'OFFLINE-PUBLIC-CERTIFICATE',
            'alpn' => ['h2', 'http/1.1'], 'serverName' => 'legacy.example.test', 'allowInsecure' => false,
            'cert_mode' => 'remote', 'custom_client_option' => 'OFFLINE-CUSTOM-OPTION',
        ];
    }

    private function tls(): array
    {
        return array_merge($this->clientTls(), [
            'private_key' => 'OFFLINE-REALITY-PRIVATE', 'ech_key' => 'OFFLINE-ECH-PRIVATE',
            'tls_key' => "-----BEGIN PRIVATE KEY-----\nOFFLINE-TLS-PRIVATE\n-----END PRIVATE KEY-----",
            'dns_env' => ['CF_DNS_API_TOKEN' => 'OFFLINE-DNS-CREDENTIAL'], 'provider' => 'OFFLINE-DNS-PROVIDER',
            'cert_file' => '/offline/certificate.pem', 'key_file' => '/offline/private.key',
        ]);
    }

    private function assertSecretsAbsent(string $content): void
    {
        foreach (['OFFLINE-REALITY-PRIVATE', 'OFFLINE-ECH-PRIVATE', 'OFFLINE-TLS-PRIVATE', 'OFFLINE-ENCRYPTION-PRIVATE', 'OFFLINE-DNS-CREDENTIAL', 'OFFLINE-DNS-PROVIDER', '/offline/', 'private_key', 'ech_key', 'tls_key', 'dns_env', 'provider', 'cert_file', 'key_file'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }
}
