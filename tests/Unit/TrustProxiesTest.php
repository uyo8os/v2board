<?php

namespace Tests\Unit;

use App\Http\Middleware\TrustProxies;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class TrustProxiesTest extends TestCase
{
    private $previousContainer;
    private array $previousProxies;
    private int $previousHeaders;
    private Repository $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousProxies = Request::getTrustedProxies();
        $this->previousHeaders = Request::getTrustedHeaderSet();
        $app = new Application(dirname(__DIR__, 2));
        $this->config = new Repository(['webman' => ['trusted_proxies' => ['127.0.0.1', '::1']]]);
        $app->instance('config', $this->config);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies($this->previousProxies, $this->previousHeaders);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    #[DataProvider('forwardedRequests')]
    public function testOnlyTrustedProxyChainsDetermineTheClientIp(string $remote, string $forwarded, array $proxies, string $expected): void
    {
        define('isWEBMAN', true);
        $this->config->set('webman.trusted_proxies', $proxies);
        $request = Request::create('http://board.example.test/api', server: [
            'REMOTE_ADDR' => $remote,
            'HTTP_X_FORWARDED_FOR' => $forwarded,
            'HTTP_X_REAL_IP' => '192.0.2.66',
            'HTTP_CF_CONNECTING_IP' => '192.0.2.77',
        ]);

        $ip = (new TrustProxies())->handle($request, static fn (Request $request) => $request->ip());

        $this->assertSame($expected, $ip);
    }

    public static function forwardedRequests(): array
    {
        $local = ['127.0.0.1', '::1'];
        return [
            'local nginx' => ['127.0.0.1', '203.0.113.8', $local, '203.0.113.8'],
            'ipv6 local nginx' => ['::1', '2001:db8::8', $local, '2001:db8::8'],
            'direct request' => ['203.0.113.9', '198.51.100.66', $local, '203.0.113.9'],
            'forged chain prefix' => ['127.0.0.1', '198.51.100.66, 203.0.113.8', $local, '203.0.113.8'],
            'explicit proxy' => ['10.0.0.6', '203.0.113.8', ['10.0.0.6'], '203.0.113.8'],
            'explicit network' => ['10.0.0.6', '203.0.113.8', ['10.0.0.0/24'], '203.0.113.8'],
            'multiple proxies' => ['127.0.0.1', '203.0.113.8, 10.0.0.6', [...$local, '10.0.0.6'], '203.0.113.8'],
            'disabled trust' => ['127.0.0.1', '203.0.113.8', [], '127.0.0.1'],
        ];
    }

    public function testTheNginxHostIsPreservedAndTheForwardedSchemeIsTrusted(): void
    {
        define('isWEBMAN', true);
        $request = Request::create('http://board.example.test/api', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.8',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'forged.example.test',
            'HTTP_X_FORWARDED_PORT' => '444',
        ]);
        (new TrustProxies())->handle($request, static fn () => null);

        $this->assertSame('board.example.test', $request->getHost());
        $this->assertSame('https', $request->getScheme());
        $this->assertSame(443, $request->getPort());
    }

    public function testConsecutiveWorkerRequestsDoNotReuseThePreviousTrustedPeer(): void
    {
        define('isWEBMAN', true);
        $middleware = new TrustProxies();
        foreach ([['127.0.0.1', '203.0.113.8'], ['203.0.113.9', '203.0.113.9']] as [$remote, $expected]) {
            $request = Request::create('/', server: ['REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => '203.0.113.8']);
            $this->assertSame($expected, $middleware->handle($request, static fn (Request $request) => $request->ip()));
        }
        $this->config->set('webman.trusted_proxies', []);
        $request = Request::create('/', server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.8']);
        $this->assertSame('127.0.0.1', $middleware->handle($request, static fn (Request $request) => $request->ip()));
    }

    public function testPhpFpmKeepsItsExistingDirectRequestBehavior(): void
    {
        foreach (['127.0.0.1', '203.0.113.9'] as $remote) {
            $request = Request::create('http://board.example.test/', server: [
                'REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => '198.51.100.66',
            ]);
            $this->assertSame($remote, (new TrustProxies())->handle($request, static fn (Request $request) => $request->ip()));
        }
    }

    public function testEmptyWebmanTrustDoesNotFallBackToOtherProxyDefaults(): void
    {
        define('isWEBMAN', true);
        $this->config->set('webman.trusted_proxies', []);
        $this->config->set('trustedproxy.proxies', '*');
        foreach (['board.example.test', 'board.on-forge.com'] as $host) {
            $request = Request::create('http://' . $host . '/', server: [
                'REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.66',
            ]);
            $this->assertSame('203.0.113.9', (new TrustProxies())->handle($request, static fn (Request $request) => $request->ip()));
        }
    }

    public function testPhpFpmKeepsItsExistingExplicitProxyConfiguration(): void
    {
        $this->config->set('trustedproxy.proxies', ['10.0.0.6']);
        $request = Request::create('http://board.example.test/', server: [
            'REMOTE_ADDR' => '10.0.0.6', 'HTTP_X_FORWARDED_FOR' => '203.0.113.8',
        ]);
        $this->assertSame('203.0.113.8', (new TrustProxies())->handle($request, static fn (Request $request) => $request->ip()));
        $this->assertSame(
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_AWS_ELB,
            Request::getTrustedHeaderSet()
        );
    }
}
