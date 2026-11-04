<?php

namespace Tests\Unit;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Library\AlipayF2F;
use PHPUnit\Framework\TestCase;

class HttpDependencyCompatibilityTest extends TestCase
{
    public function testAlipayPaymentRequestPreservesSignedQueryValues(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'alipay_trade_precreate_response' => ['msg' => 'Success', 'qr_code' => 'https://example.test/qr'],
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));
        $previous = Http::getFacadeRoot();
        Http::swap($this->httpFactory($stack));

        try {
            $payment = new class extends AlipayF2F {
                public function buildParam(): array
                {
                    return [
                        'biz_content' => '{"subject":"套餐 & renewal","out_trade_no":"test+1"}',
                        'sign' => 'example+signature/with=padding',
                    ];
                }
            };
            $payment->setMethod('alipay.trade.precreate');
            $expected = $payment->buildParam();
            $payment->send();

            $this->assertSame('https://example.test/qr', $payment->getQrCodeUrl());
            $this->assertCount(1, $history);
            $this->assertSame('openapi.alipay.com', $history[0]['request']->getUri()->getHost());
            parse_str($history[0]['request']->getUri()->getQuery(), $query);
            $this->assertSame($expected, $query);
        } finally {
            if ($previous !== null) {
                Http::swap($previous);
            } else {
                Http::clearResolvedInstance(Factory::class);
            }
        }
    }

    public function testLaravelImageUploadPreservesMultipartFieldsAndRedirectPolicy(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"result":"success"}'),
        ]));
        $stack->push(Middleware::history($history));

        $response = $this->httpFactory($stack)->timeout(30)
            ->withOptions(['allow_redirects' => false, 'connect_timeout' => 10])
            ->withHeaders(['Accept' => 'application/json'])
            ->attach('image', 'image-content', 'ticket.png', ['Content-Type' => 'image/png'])
            ->post('https://uploads.example.test/api', ['token' => 'test-token']);

        $this->assertSame('success', $response->json('result'));
        $this->assertCount(1, $history);
        $this->assertFalse($history[0]['options']['allow_redirects']);
        $request = $history[0]['request'];
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        $body = (string)$request->getBody();
        $this->assertStringContainsString('name="image"; filename="ticket.png"', $body);
        $this->assertStringContainsString('image-content', $body);
        $this->assertStringContainsString('test-token', $body);
    }

    private function httpFactory(HandlerStack $handler): Factory
    {
        return new class($handler) extends Factory {
            public function __construct(private HandlerStack $handler)
            {
                parent::__construct();
            }

            protected function newPendingRequest()
            {
                return parent::newPendingRequest()->setHandler($this->handler);
            }
        };
    }
}
