<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;

use App\Services\SubscriptionAuditRequest;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class SubscriptionAuditRequestTest extends TestCase
{
    public function testMetadataOmitsHeadersUrlTokensAndUnknownParameters(): void
    {
        $temporary = 'temporary+/=credential';
        $user = (object)['token' => 'long-lived-subscription-secret', 'uuid' => 'proxy-credential-uuid'];
        $request = Request::create('/custom/' . rawurlencode($temporary), 'GET', [
            'token' => $temporary, 'flag' => 'sing-box 1.12.0', 'target' => 'private-target',
            'password' => 'private-password', 'user' => $user, 'nested' => ['token' => 'private-nested'],
        ], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer private-auth', 'HTTP_COOKIE' => 'session=private-cookie',
            'HTTP_X_DIAGNOSTIC' => 'private-header',
        ]);
        $metadata = new SubscriptionAuditRequest($request, $user);
        $details = $metadata->capture($request);
        $details['headers'] = ['secret' => 'must-not-serialize'];
        $details['url'] = 'https://example.test/?token=must-not-serialize';
        $details['decoy'] = true;
        $details['node_count'] = 12;
        $encoded = $metadata->encode($details);
        $safe = json_decode($encoded, true);

        $this->assertSame('GET', $safe['method']);
        $this->assertSame(['flag' => 'sing-box', 'version' => '1.12.0'], $safe['query']);
        $this->assertTrue($safe['decoy']);
        $this->assertSame(12, $safe['node_count']);
        foreach ([$temporary, rawurlencode($temporary), $user->token, $user->uuid, 'private-target', 'private-password', 'private-auth', 'private-cookie', 'private-header', 'private-nested', 'must-not-serialize'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $this->assertArrayNotHasKey('url', $safe);
        $this->assertArrayNotHasKey('headers', $safe);
        $this->assertStringContainsString('[redacted]', $safe['path']);
        $this->assertLessThanOrEqual(SubscriptionAuditRequest::MAX_DETAILS_BYTES, strlen($encoded));
    }

    public function testUaRedactsLongTermTemporaryAndEncodedProxyCredentials(): void
    {
        $request = Request::create('/subscribe', 'GET', ['token' => 'temporary+/secret']);
        $user = (object)['token' => 'long-term-token', 'uuid' => 'proxy-uuid'];
        $metadata = new SubscriptionAuditRequest($request, $user);
        $text = $metadata->text('UA long-term-token temporary+/secret temporary%2b%2fsecret proxy-uuid https://test/?access_token=other-secret', 512);
        foreach (['long-term-token', 'temporary+/secret', 'temporary%2b%2fsecret', 'proxy-uuid', 'other-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }

    #[DataProvider('unsafeFlags')]
    public function testArbitraryFlagValuesAndArraysAreNotPersisted($flag): void
    {
        $request = Request::create('/subscribe', 'GET', ['flag' => $flag, 'version' => 'private-value']);
        $metadata = new SubscriptionAuditRequest($request, (object)['token' => 'user-token']);
        $this->assertSame([], $metadata->capture($request)['query']);
    }

    public static function unsafeFlags(): array
    {
        return [['clash-private-credential'], [['token' => 'private-token']], ['sing-box password'], ['https://example.test/?token=private']];
    }

    public function testEncodingProjectsSafeKeysAndBoundsUnicodeMetadata(): void
    {
        $request = Request::create('/subscribe');
        $metadata = new SubscriptionAuditRequest($request, (object)[]);
        $details = json_decode($metadata->encode([
            'method' => 'GET', 'path' => '/' . str_repeat('测', 5000) . '?token=private-value',
            'query' => ['flag' => 'clash', 'token' => 'private-token', 'version' => 'private-version'],
            'user' => ['password' => 'private-password'], 'headers' => ['authorization' => 'private-header'],
            'node_count' => 5000000, 'decoy' => true,
        ]), true);
        $this->assertSame(['flag' => 'clash'], $details['query']);
        $this->assertLessThanOrEqual(512, strlen($details['path']));
        $this->assertSame(1000000, $details['node_count']);
        $this->assertSame(1, preg_match('//u', $details['path']));
        $this->assertSame(['method', 'path', 'query', 'decoy', 'node_count'], array_keys($details));
    }
}
