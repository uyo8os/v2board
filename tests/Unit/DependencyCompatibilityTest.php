<?php

namespace Tests\Unit;

use App\Payments\BEasyPaymentUSDT;
use App\Payments\EPayQrcode;
use App\Payments\Epusdt;
use App\Services\TelegramService;
use App\Utils\Helper;
use Curl\Curl;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use MessagePack\BufferUnpacker;
use MessagePack\Packer;
use ParagonIE_Sodium_Compat as SodiumCompat;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class DependencyCompatibilityTest extends TestCase
{
    private static $server;
    private static string $serverLog;
    private static string $baseUrl;
    private $previousContainer;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException($errorMessage);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        self::$baseUrl = 'http://' . $address;
        self::$serverLog = tempnam(sys_get_temp_dir(), 'v2board-dependency-');
        self::$server = proc_open([
            PHP_BINARY, '-S', $address, dirname(__DIR__) . '/Fixtures/dependency-http-server.php',
        ], [0 => ['pipe', 'r'], 1 => ['file', self::$serverLog, 'a'], 2 => ['file', self::$serverLog, 'a']], $pipes);
        if (!is_resource(self::$server)) {
            throw new RuntimeException('Unable to start the local HTTP fixture.');
        }
        fclose($pipes[0]);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.05);
            if (is_resource($probe)) {
                fclose($probe);
                return;
            }
            usleep(20000);
        }
        self::tearDownAfterClass();
        throw new RuntimeException('The local HTTP fixture did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (isset(self::$serverLog) && is_file(self::$serverLog)) {
            unlink(self::$serverLog);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository());
        $app->instance('request', Request::create('https://board.example.test', 'POST', server: ['REMOTE_ADDR' => '192.0.2.1']));
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function testCurlPreservesJsonPaymentRequestBodiesAndResponses(): void
    {
        $payment = new BEasyPaymentUSDT([
            'bepusdt_url' => self::$baseUrl,
            'bepusdt_apitoken' => 'fixture-secret',
            'bepusdt_trade_type' => 'usdt.trc20',
        ]);
        $request = $this->capturedPaymentRequest($payment->pay($this->order()));
        $body = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('POST', $request['method']);
        $this->assertSame('/api/v1/order/create-transaction', $request['path']);
        $this->assertSame('application/json', $request['content_type']);
        $this->assertSame(12.34, $body['amount']);
        $this->assertSame('fixture-order', $body['order_id']);
        $signature = $body['signature'];
        unset($body['signature']);
        ksort($body);
        $this->assertSame(md5(stripslashes(urldecode(http_build_query($body))) . 'fixture-secret'), $signature);
    }

    public function testCurlPreservesFormEncodedPaymentRequests(): void
    {
        $payment = new EPayQrcode(['url' => self::$baseUrl, 'pid' => '123', 'key' => 'fixture-secret', 'type' => 'alipay']);
        $request = $this->capturedPaymentRequest($payment->pay($this->order()));
        parse_str($request['body'], $body);

        $this->assertSame('POST', $request['method']);
        $this->assertSame('/mapi.php', $request['path']);
        $this->assertSame('application/x-www-form-urlencoded', $request['content_type']);
        $this->assertSame('12.34', $body['money']);
        $this->assertSame('fixture-order', $body['out_trade_no']);
        $this->assertSame($this->order()['notify_url'], $body['notify_url']);
        $signature = $body['sign'];
        unset($body['sign'], $body['sign_type']);
        $body = array_filter($body, static fn ($value) => $value !== '');
        ksort($body);
        $pairs = [];
        foreach ($body as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        $this->assertSame(md5(implode('&', $pairs) . 'fixture-secret'), $signature);
    }

    public function testCurlPreservesEpusdtOrderAndNetworkSelection(): void
    {
        $config = ['epusdt_url' => self::$baseUrl, 'epusdt_pid' => '123', 'epusdt_token' => 'fixture-secret'];
        $request = $this->capturedPaymentRequest((new Epusdt($config))->pay($this->order()));
        $body = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('/payments/gmpay/v1/order/create-transaction', $request['path']);
        $this->assertSame('application/json', $request['content_type']);
        $this->assertSame(12.34, $body['amount']);
        $this->assertSame('fixture-order', $body['order_id']);
        $this->assertSame('tron', $body['network']);

        $config['epusdt_network'] = 'ethereum';
        $request = $this->capturedPaymentRequest((new Epusdt($config))->pay($this->order()));
        $body = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('/pay/switch-network', $request['path']);
        $this->assertSame(['trade_id' => 'fixture-trade', 'token' => 'usdt', 'network' => 'ethereum'], $body);
    }

    public function testTelegramStillReceivesDecodedJsonObjectsAndEncodedQueryParameters(): void
    {
        $telegram = new class(self::$baseUrl) extends TelegramService {
            public function __construct(string $baseUrl)
            {
                $this->api = $baseUrl . '/telegram/';
            }
        };
        $this->assertSame(123, $telegram->getMe()->result->id);
        $params = ['chat_id' => -123456, 'text' => "Test & 中文 + /\n", 'parse_mode' => 'markdown'];
        $response = (new ReflectionMethod(TelegramService::class, 'request'))->invoke($telegram, 'sendMessage', $params);

        $this->assertTrue($response->ok);
        $this->assertSame('GET', $response->result->request->method);
        $this->assertSame('/telegram/sendMessage', $response->result->request->path);
        $this->assertSame((string) $params['chat_id'], $response->result->request->query->chat_id);
        $this->assertSame($params['text'], $response->result->request->query->text);
        $this->assertSame($params['parse_mode'], $response->result->request->query->parse_mode);
    }

    public function testCurlStillExposesHttpFailuresAndDecodedErrorResponses(): void
    {
        $curl = new Curl();
        try {
            $curl->get(self::$baseUrl . '/http-error');
            $this->assertTrue($curl->error);
            $this->assertSame(503, $curl->httpStatusCode);
            $this->assertSame('Fixture unavailable', $curl->response->message);
        } finally {
            $curl->close();
        }
    }

    public function testNodeMessagePackWireFormatIsUnchanged(): void
    {
        // Captured with the previous 0.9.2 packer; node clients rely on these types.
        $users = ['users' => [[
            'id' => 256, 'uuid' => 'user-uuid', 'speed_limit' => 0, 'device_limit' => 2, 'u' => 4294967296, 'd' => 0,
        ]]];
        $packed = (new Packer())->pack($users);
        $this->assertSame('81a575736572739186a26964cd0100a475756964a9757365722d75756964ab73706565645f6c696d697400ac6465766963655f6c696d697402a175cf0000000100000000a16400', bin2hex($packed));
        $this->assertSame($users, (new BufferUnpacker($packed))->unpack());
        $this->assertSame('81a5757365727390', bin2hex((new Packer())->pack(['users' => []])));
    }

    public function testSodiumNodeKeysRemainCompatibleWithAndWithoutTheExtension(): void
    {
        $previous = SodiumCompat::$disableFallbackForUnitTests;
        try {
            foreach ([false, true] as $purePhp) {
                SodiumCompat::$disableFallbackForUnitTests = $purePhp;
                $secret = hex2bin('77076d0a7318a57d3c16c17251b26645df4c2f87ebc0992ab177fba51db92c2a');
                $this->assertSame('8520f0098930a754748b7ddcb43ef75a0dbf3a0d26381af4eba4a98eaa9b4e6a', bin2hex(SodiumCompat::crypto_scalarmult_base($secret)));

                $pair = SodiumCompat::crypto_box_keypair();
                $private = SodiumCompat::crypto_box_secretkey($pair);
                $public = SodiumCompat::crypto_box_publickey($pair);
                $this->assertSame(32, strlen($private));
                $this->assertSame(32, strlen($public));
                $this->assertSame($public, SodiumCompat::crypto_scalarmult_base($private));
                foreach ([$private, $public] as $key) {
                    $encoded = Helper::base64EncodeUrlSafe($key);
                    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $encoded);
                    $this->assertSame($key, Helper::base64DecodeUrlSafe($encoded));
                }
            }
        } finally {
            SodiumCompat::$disableFallbackForUnitTests = $previous;
        }
    }

    private function order(): array
    {
        return [
            'trade_no' => 'fixture-order', 'total_amount' => 1234,
            'notify_url' => 'https://board.example.test/notify?param=a%26b',
            'return_url' => 'https://board.example.test/order',
        ];
    }

    private function capturedPaymentRequest(array $payment): array
    {
        parse_str(parse_url($payment['data'], PHP_URL_QUERY), $query);
        return json_decode(base64_decode($query['capture']), true, 512, JSON_THROW_ON_ERROR);
    }
}
