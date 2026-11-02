<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\InsiderGuardService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InsiderGuardServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'insider-guard-test-key',
            'v2board.insider_guard_decoy_host' => '',
        ]);
    }

    /** @dataProvider scoreCases */
    public function testScoreChangesOnlyAfterPullAndIpThresholds(int $pulls, int $ips, int $expected): void
    {
        $this->assertSame($expected, InsiderGuardService::calcScore($pulls, $ips));
    }

    public static function scoreCases(): array
    {
        return [
            'at both boundaries' => [20, 8, 0],
            'pulls exceed boundary' => [21, 8, 25],
            'ips exceed boundary' => [20, 9, 25],
            'both exceed boundaries' => [21, 9, 50],
            'large counts stay capped' => [PHP_INT_MAX, PHP_INT_MAX, 50],
        ];
    }

    public function testDefaultDecoyIsStableAndDerivedFromTheUser(): void
    {
        $hosts = [];
        foreach ([41, 42, 43, 44] as $userId) {
            $host = InsiderGuardService::decoyHost($userId);
            $this->assertSame($host, InsiderGuardService::decoyHost($userId));
            $this->assertNotFalse(filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4));
            $firstOctet = (int)explode('.', $host)[0];
            $this->assertGreaterThanOrEqual(240, $firstOctet);
            $this->assertLessThanOrEqual(254, $firstOctet);
            $hosts[] = $host;
        }
        // The address space is finite: verify user derivation without asserting collision-free allocation.
        $this->assertGreaterThan(1, count(array_unique($hosts)));
    }

    public function testDefaultDecoyAlsoDependsOnTheApplicationKey(): void
    {
        $host = InsiderGuardService::decoyHost(42);
        config(['app.key' => 'a-different-insider-guard-test-key']);
        $this->assertNotSame($host, InsiderGuardService::decoyHost(42));
    }

    /** @dataProvider customHostCases */
    public function testCustomDecoyHostIsAppliedToNodes(string $configured, string $expected): void
    {
        config(['v2board.insider_guard_decoy_host' => $configured]);
        $servers = [['id' => 1, 'type' => 'trojan', 'host' => 'real.example.com', 'name' => 'Node', 'port' => 443]];

        InsiderGuardService::applyDecoy($servers, 42);

        $this->assertSame($expected, InsiderGuardService::decoyHost(42));
        $this->assertSame($expected, $servers[0]['host']);
    }

    public static function customHostCases(): array
    {
        return [
            'ipv4' => ['198.51.100.27', '198.51.100.27'],
            'domain' => ['honeypot.example.com', 'honeypot.example.com'],
            'bracketed ipv6' => ['[2001:db8::27]', '2001:db8::27'],
        ];
    }

    public function testDecoyReplacesNestedAddressesWithoutChangingNodeIdentityOrCredentials(): void
    {
        config(['v2board.insider_guard_decoy_host' => '240.10.20.30']);
        $uuid = '9851b84e-672c-4b9b-b31b-69ee230f8348';
        $servers = [
            [
                'id' => 11, 'type' => 'vmess', 'port' => 8443, 'uuid' => $uuid,
                'host' => 'origin.example.com', 'name' => ' ORIGIN.EXAMPLE.COM ',
                'tlsSettings' => [
                    'serverName' => 'tls.example.com',
                    'pinned_peer_cert_sha256' => ['vmess-secret-pin'], 'tls_cert' => 'vmess-secret-certificate',
                    'ech' => 'custom', 'ech_config' => ['vmess-secret-ech-config'],
                ],
                'networkSettings' => [
                    'path' => '/websocket',
                    'headers' => ['Host' => 'ws.example.com', 'User-Agent' => 'client-agent'],
                ],
                'sni' => '',
            ],
            [
                'id' => 11, 'type' => 'v2node', 'protocol' => 'vless', 'port' => 443, 'password' => $uuid,
                'host' => 'origin.example.com', 'server' => '192.0.2.5', 'address' => 'backend.example.com',
                'name' => 'origin.example.com',
                'tls_settings' => [
                    'server_name' => 'sni.example.com', 'SNI' => 'other-sni.example.com',
                    'allow_insecure' => false, 'alpn' => ['h2', 'http/1.1'],
                    'pinned_peer_cert_sha256' => ['secret-pin'], 'tls_cert' => 'secret-certificate',
                    'ech' => 'custom', 'ech_config' => ['secret-ech-config'],
                ],
                'network_settings' => [
                    'header' => ['request' => ['headers' => ['Host' => ['http-a.example.com', 'http-b.example.com']]]],
                ],
            ],
            [
                'id' => 12, 'type' => 'shadowsocks', 'port' => 8388, 'password' => $uuid,
                'host' => 'ss.example.com', 'name' => 'Readable node name',
                'obfs-host' => 'obfs.example.com', 'obfs_host' => 'another-obfs.example.com',
                'obfs_settings' => ['host' => 'nested-obfs.example.com', 'path' => '/obfs-path'],
            ],
        ];
        $before = $servers;

        InsiderGuardService::applyDecoy($servers, 42);

        $decoy = '240.10.20.30';
        $this->assertSame([$decoy, $decoy . ' 2', 'Readable node name'], array_column($servers, 'name'));
        $this->assertCount(3, array_unique(array_column($servers, 'name')));
        $this->assertSame($decoy, $servers[0]['tlsSettings']['serverName']);
        $this->assertSame($decoy, $servers[0]['networkSettings']['headers']['Host']);
        $this->assertSame('', $servers[0]['sni']);
        $this->assertSame($decoy, $servers[1]['server']);
        $this->assertSame($decoy, $servers[1]['address']);
        $this->assertSame($decoy, $servers[1]['tls_settings']['server_name']);
        $this->assertSame($decoy, $servers[1]['tls_settings']['SNI']);
        $this->assertSame([$decoy, $decoy], $servers[1]['network_settings']['header']['request']['headers']['Host']);
        $this->assertSame($decoy, $servers[2]['obfs-host']);
        $this->assertSame($decoy, $servers[2]['obfs_host']);
        $this->assertSame($decoy, $servers[2]['obfs_settings']['host']);
        foreach ($servers as $index => $server) {
            $this->assertSame($decoy, $server['host']);
            foreach (['id', 'type', 'protocol', 'port', 'uuid', 'password'] as $field) {
                if (array_key_exists($field, $before[$index])) {
                    $this->assertSame($before[$index][$field], $server[$field], $field . ' must stay unchanged');
                }
            }
        }
        foreach (['pinned_peer_cert_sha256', 'tls_cert', 'ech_config'] as $field) {
            $this->assertArrayNotHasKey($field, $servers[0]['tlsSettings']);
            $this->assertArrayNotHasKey($field, $servers[1]['tls_settings']);
        }
        $this->assertFalse($servers[1]['tls_settings']['allow_insecure']);
        $this->assertSame(['h2', 'http/1.1'], $servers[1]['tls_settings']['alpn']);
        $this->assertSame('/websocket', $servers[0]['networkSettings']['path']);
        $this->assertSame('client-agent', $servers[0]['networkSettings']['headers']['User-Agent']);
        $this->assertSame('/obfs-path', $servers[2]['obfs_settings']['path']);
    }

    public function testRestrictionAndReleaseAreVisibleOnTheNextLookup(): void
    {
        $query = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('v2_insider_guard_restrict')->times(3)->andReturn($query);
        $query->shouldReceive('where')->with('user_id', 42)->times(3)->andReturnSelf();
        $query->shouldReceive('exists')->times(3)->andReturn(false, true, false);

        $this->assertFalse(InsiderGuardService::isRestricted(42));
        $this->assertTrue(InsiderGuardService::isRestricted(42));
        $this->assertFalse(InsiderGuardService::isRestricted(42));
    }

    /** @dataProvider loggerFailureCases */
    public function testRestrictionLookupFailureRefusesConfigurationWith503(bool $loggerFails): void
    {
        DB::shouldReceive('table')->with('v2_insider_guard_restrict')->once()
            ->andThrow(new RuntimeException('Database unavailable'));
        Cache::shouldReceive('add')->once()->andReturn(true);
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('daily')->once()->andReturn($logger);
        $warning = $logger->shouldReceive('warning')->once()->with('InsiderGuard restrict lookup unavailable', Mockery::type('array'));
        if ($loggerFails) $warning->andThrow(new RuntimeException('Log storage unavailable'));

        try {
            InsiderGuardService::isRestricted(42);
            $this->fail('An unknown restriction state must not expose real node addresses');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
    }

    public static function loggerFailureCases(): array
    {
        return [
            'logger available' => [false],
            'logger also unavailable' => [true],
        ];
    }

    /** @dataProvider loggerFailureCases */
    public function testLoginAndSubscriptionAuditFailuresDoNotInterruptTheCaller(bool $loggerFails): void
    {
        if (!function_exists('mb_substr') || !function_exists('mb_convert_encoding')) {
            $this->markTestSkipped('The audit path requires the mbstring extension');
        }
        $user = new User();
        $user->id = 42;
        $user->uuid = '9851b84e-672c-4b9b-b31b-69ee230f8348';
        $request = Request::create('/api/v1/client/subscribe', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.42', 'HTTP_USER_AGENT' => 'guard-test-client',
        ]);
        DB::shouldReceive('table')->with('v2_insider_guard_activity')->twice()
            ->andThrow(new RuntimeException('Activity database unavailable'));
        DB::shouldReceive('table')->with('v2_insider_guard_node_user')->once()
            ->andThrow(new RuntimeException('Delivery database unavailable'));
        Cache::shouldReceive('add')->andReturn(true);
        Cache::shouldReceive('forget')->once();
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('daily')->times(3)->andReturn($logger);
        $warning = $logger->shouldReceive('warning')->times(3);
        if ($loggerFails) $warning->andThrow(new RuntimeException('Log storage unavailable'));

        $this->assertNull(InsiderGuardService::recordLogin($user, $request));
        $this->assertNull(InsiderGuardService::recordSubscribe($user, $request, [
            ['id' => 11, 'type' => 'trojan', 'host' => 'origin.example.com'],
        ]));
        $this->assertSame('9851b84e-672c-4b9b-b31b-69ee230f8348', $user->uuid);
    }
}
