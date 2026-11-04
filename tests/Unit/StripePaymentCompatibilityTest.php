<?php

namespace Tests\Unit;

use App\Payments\StripeALL;
use App\Payments\StripeAlipay;
use App\Payments\StripeCheckout;
use App\Payments\StripeCredit;
use App\Payments\StripeWepay;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StripePaymentCompatibilityTest extends TestCase
{
    private const WEBHOOK_SECRET = 'whsec_offline_compatibility_test';

    private $previousContainer;
    private $previousHttpClient;
    private $previousApiKey;
    private $previousApiVersion;
    private ?string $previousSignature;
    private Application $app;
    private StripeOfflineHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('log', new class {
            public function info($message, array $context = []): void
            {
            }
        });
        $this->previousHttpClient = (new \ReflectionProperty(ApiRequestor::class, '_httpClient'))->getValue();
        $this->previousApiKey = Stripe::getApiKey();
        $this->previousApiVersion = Stripe::getApiVersion();
        $this->previousSignature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? null;
        $this->http = new StripeOfflineHttpClient();
        ApiRequestor::setHttpClient($this->http);
        Stripe::setApiVersion('2026-10-28.endive');
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient($this->previousHttpClient);
        ApiRequestor::resetTelemetry();
        Stripe::setApiKey($this->previousApiKey);
        Stripe::setApiVersion($this->previousApiVersion);
        if ($this->previousSignature === null) {
            unset($_SERVER['HTTP_STRIPE_SIGNATURE']);
        } else {
            $_SERVER['HTTP_STRIPE_SIGNATURE'] = $this->previousSignature;
        }
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function testAllPaymentChannelsCreateRequestsUsingTheExistingApiContract(): void
    {
        $cases = [
            [StripeAlipayOffline::class, 'alipay', '/v1/sources', 1, 'https://payments.example/alipay'],
            [StripeWepayOffline::class, 'wechat_pay', '/v1/sources', 0, 'weixin://offline-payment'],
            [StripeCreditOffline::class, 'cards', '/v1/charges', 2, true],
            [StripeCheckoutOffline::class, 'cards', '/v1/checkout/sessions', 1, 'https://payments.example/checkout'],
            [StripeAllOffline::class, 'cards', '/v1/checkout/sessions', 1, 'https://payments.example/checkout'],
            [StripeAllOffline::class, 'alipay', '/v1/payment_intents', 1, 'https://payments.example/alipay'],
            [StripeAllOffline::class, 'wechat_pay', '/v1/payment_intents', 0, 'weixin://offline-payment'],
        ];

        foreach ($cases as [$class, $method, $endpoint, $type, $data]) {
            $this->http->requests = [];
            $payment = new $class($this->config($method));
            $result = $payment->pay([
                'total_amount' => 1000,
                'trade_no' => 'order-offline-123',
                'user_id' => 42,
                'return_url' => 'https://panel.example/payment/return',
                'stripe_token' => 'tok_offline',
            ]);

            $this->assertSame(['type' => $type, 'data' => $data], $result, $class . ':' . $method);
            $request = $this->http->requests[array_key_last($this->http->requests)];
            $this->assertSame($endpoint, parse_url($request['url'], PHP_URL_PATH));
            if ($endpoint === '/v1/checkout/sessions') {
                $this->assertSame('order-offline-123', $request['params']['client_reference_id']);
                $this->assertEquals(1250, $request['params']['line_items'][0]['price_data']['unit_amount']);
            } else {
                $this->assertSame('order-offline-123', $request['params']['metadata']['out_trade_no']);
                $this->assertEquals(1250, $request['params']['amount']);
            }
            foreach ($this->http->requests as $request) {
                $this->assertContains('Stripe-Version: 2024-04-10', $request['headers']);
            }
            $this->assertSame('2026-10-28.endive', Stripe::getApiVersion());
        }
    }

    public function testCheckoutApiFailureReturnsTheSdkErrorMessage(): void
    {
        $this->http->error = [
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Offline Checkout API failure',
                'param' => 'currency',
            ],
        ];

        try {
            (new StripeCheckoutOffline($this->config()))->pay([
                'total_amount' => 1000,
                'trade_no' => 'order-offline-123',
                'return_url' => 'https://panel.example/payment/return',
            ]);
            $this->fail('Checkout accepted a failed Stripe API request');
        } catch (HttpException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertSame('Failed to create order. Error: Offline Checkout API failure', $e->getMessage());
        }
        $this->assertCount(1, $this->http->requests);
    }

    public function testAllChannelsRejectInvalidOrMissingWebhookSignatures(): void
    {
        foreach ($this->channels() as $class) {
            foreach (['t=' . time() . ',v1=invalid', null] as $signature) {
                $this->setWebhook(json_encode($this->event('charge.succeeded', ['id' => 'ch_offline'])), $signature);
                $this->assertRejectedWebhook(new $class($this->config()));
            }
        }
        $this->assertSame([], $this->http->requests);
    }

    public function testAllChannelsRejectSignedMalformedJson(): void
    {
        foreach ($this->channels() as $class) {
            $payload = '{"malformed":';
            $this->setWebhook($payload, $this->sign($payload));
            $this->assertRejectedWebhook(new $class($this->config()));
        }
        $this->assertSame([], $this->http->requests);
    }

    public function testLegacySourceCallbacksPreserveOrderMetadataWhenCreatingCharges(): void
    {
        foreach ([StripeAlipay::class, StripeWepay::class, StripeCredit::class] as $class) {
            $payload = json_encode($this->event('source.chargeable', [
                'object' => 'source',
                'id' => 'src_offline',
                'amount' => 1250,
                'currency' => 'usd',
                'metadata' => ['out_trade_no' => 'order-offline-123', 'user_id' => '42'],
            ]));
            $this->setWebhook($payload, $this->sign($payload));

            $this->assertSame('success', (new $class($this->config()))->notify([]));
            $request = $this->http->requests[array_key_last($this->http->requests)];
            $this->assertSame('/v1/charges', parse_url($request['url'], PHP_URL_PATH));
            $this->assertSame('src_offline', $request['params']['source']);
            $this->assertSame('order-offline-123', $request['params']['metadata']['out_trade_no']);
            $this->assertContains('Stripe-Version: 2024-04-10', $request['headers']);
        }
    }

    public function testLegacyChargeCallbacksReturnTheExistingOrderIdentifiers(): void
    {
        foreach ([StripeAlipay::class, StripeWepay::class, StripeCredit::class] as $class) {
            foreach (['metadata', 'source'] as $metadataLocation) {
                $object = ['object' => 'charge', 'id' => 'ch_offline', 'status' => 'succeeded'];
                $metadata = ['out_trade_no' => 'order-offline-123'];
                if ($metadataLocation === 'metadata') {
                    $object['metadata'] = $metadata;
                } else {
                    $object['metadata'] = [];
                    $object['source'] = ['object' => 'source', 'metadata' => $metadata];
                }
                $payload = json_encode($this->event('charge.succeeded', $object));
                $this->setWebhook($payload, $this->sign($payload));

                $this->assertSame([
                    'trade_no' => 'order-offline-123', 'callback_no' => 'ch_offline',
                ], (new $class($this->config()))->notify([]));
            }
        }
        $this->assertSame([], $this->http->requests);
    }

    public function testCheckoutAndPaymentIntentCallbacksRetainTheirOrderMapping(): void
    {
        $cases = [
            [StripeALL::class, 'payment_intent.succeeded', [
                'object' => 'payment_intent', 'id' => 'pi_offline', 'status' => 'succeeded',
                'metadata' => ['out_trade_no' => 'order-offline-123'],
            ]],
        ];
        foreach ([StripeALL::class, StripeCheckout::class] as $class) {
            foreach (['checkout.session.completed', 'checkout.session.async_payment_succeeded'] as $type) {
                $cases[] = [$class, $type, [
                    'object' => 'checkout.session', 'id' => 'cs_offline',
                    'payment_status' => 'paid', 'client_reference_id' => 'order-offline-123',
                    'payment_intent' => 'pi_offline',
                ]];
            }
        }
        foreach ($cases as [$class, $type, $object]) {
            $payload = json_encode($this->event($type, $object));
            $this->setWebhook($payload, $this->sign($payload));
            $this->assertSame([
                'trade_no' => 'order-offline-123', 'callback_no' => 'pi_offline',
            ], (new $class($this->config()))->notify([]));
        }
        $this->assertSame([], $this->http->requests);
    }

    private function config(string $method = 'cards'): array
    {
        return [
            'currency' => 'usd',
            'stripe_sk_live' => 'sk_test_offline',
            'stripe_webhook_key' => self::WEBHOOK_SECRET,
            'payment_method' => $method,
        ];
    }

    private function channels(): array
    {
        return [StripeAlipay::class, StripeWepay::class, StripeCredit::class, StripeCheckout::class, StripeALL::class];
    }

    private function event(string $type, array $object): array
    {
        return ['object' => 'event', 'id' => 'evt_offline', 'type' => $type, 'data' => ['object' => $object]];
    }

    private function sign(string $payload): string
    {
        $timestamp = time();
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, self::WEBHOOK_SECRET);
    }

    private function setWebhook(string $payload, ?string $signature): void
    {
        $this->app->instance('request', Request::create('/stripe/webhook', 'POST', content: $payload));
        if ($signature === null) {
            unset($_SERVER['HTTP_STRIPE_SIGNATURE']);
        } else {
            $_SERVER['HTTP_STRIPE_SIGNATURE'] = $signature;
        }
    }

    private function assertRejectedWebhook($payment): void
    {
        try {
            $payment->notify([]);
            $this->fail('An invalid webhook was accepted by ' . get_class($payment));
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }
}

class StripeOfflineHttpClient implements ClientInterface
{
    public array $requests = [];
    public ?array $error = null;

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['url' => $absUrl, 'headers' => $headers, 'params' => $params];
        if ($this->error !== null) {
            return [json_encode($this->error), 400, []];
        }
        $response = match (parse_url($absUrl, PHP_URL_PATH)) {
            '/v1/sources' => [
                'object' => 'source', 'id' => 'src_offline',
                'redirect' => ['url' => 'https://payments.example/alipay'],
                'wechat' => ['qr_code_url' => 'weixin://offline-payment'],
            ],
            '/v1/charges' => ['object' => 'charge', 'id' => 'ch_offline', 'paid' => true],
            '/v1/checkout/sessions' => [
                'object' => 'checkout.session', 'id' => 'cs_offline', 'url' => 'https://payments.example/checkout',
            ],
            '/v1/payment_methods' => ['object' => 'payment_method', 'id' => 'pm_offline'],
            '/v1/payment_intents' => [
                'object' => 'payment_intent', 'id' => 'pi_offline',
                'next_action' => [
                    'alipay_handle_redirect' => ['url' => 'https://payments.example/alipay'],
                    'wechat_pay_display_qr_code' => ['data' => 'weixin://offline-payment'],
                ],
            ],
            default => throw new \LogicException('Unexpected Stripe API request: ' . $absUrl),
        };

        return [json_encode($response), 200, []];
    }
}

trait StripeOfflineExchange
{
    protected function exchange($from, $to)
    {
        return 1.25;
    }
}

class StripeAlipayOffline extends StripeAlipay
{
    use StripeOfflineExchange;
}

class StripeWepayOffline extends StripeWepay
{
    use StripeOfflineExchange;
}

class StripeCreditOffline extends StripeCredit
{
    use StripeOfflineExchange;
}

class StripeCheckoutOffline extends StripeCheckout
{
    use StripeOfflineExchange;
}

class StripeAllOffline extends StripeALL
{
    use StripeOfflineExchange;

    protected function getUserEmail($userId)
    {
        return 'offline@example.com';
    }
}
