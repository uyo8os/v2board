<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Guest\PaymentController;
use App\Jobs\OrderHandleJob;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PaymentCallbackFixtures as Fixtures;

/** Exercise real callback/order handling with SQLite; no .env, external queue, or gateway. */
class PaymentCallbackHttpTest extends TestCase
{
    private $previousContainer;
    private $previousFacadeApplication;
    private $previousResolver;
    private ?Application $app = null;
    private Manager $database;
    private array $jobs = [];
    private int $sequence = 0;

    public static function gateways(): array
    {
        return Fixtures::gateways();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) $this->markTestSkipped('Requires PDO SQLite');
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Config(['v2board' => ['telegram_bot_enable' => 0, 'deposit_bounus' => []]]));
        $this->app->instance('cache', new Repository(new ArrayStore()));
        $this->app->instance('log', new class {
            public function error($message, array $context = []): void { throw new RuntimeException($message); }
        });
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->database = new Manager($this->app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->bootEloquent();
        $this->app->instance('db', $this->database->getDatabaseManager());
        $db = $this->database->getConnection();
        $db->statement('CREATE TABLE v2_payment (id INTEGER PRIMARY KEY, uuid TEXT, payment TEXT, config TEXT, enable INTEGER, notify_domain TEXT, name TEXT, created_at INTEGER, updated_at INTEGER)');
        $db->statement('CREATE TABLE v2_user (id INTEGER PRIMARY KEY, email TEXT, balance INTEGER, invite_user_id INTEGER, created_at INTEGER, updated_at INTEGER)');
        $db->statement('CREATE TABLE v2_plan (id INTEGER PRIMARY KEY, name TEXT)');
        $db->statement('CREATE TABLE v2_order (id INTEGER PRIMARY KEY, user_id INTEGER, plan_id INTEGER, payment_id INTEGER, status INTEGER, type INTEGER, total_amount INTEGER, trade_no TEXT, callback_no TEXT, paid_at INTEGER, coupon_id INTEGER, balance_amount INTEGER, surplus_amount INTEGER, discount_amount INTEGER, refund_amount INTEGER, period TEXT, created_at INTEGER, updated_at INTEGER)');
        $dispatcher = Mockery::mock(DispatcherContract::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($job) { $this->jobs[] = $job; return null; });
        $this->app->instance(DispatcherContract::class, $dispatcher);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            $this->database->getDatabaseManager()->disconnect();
            if ($this->previousResolver) {
                Model::setConnectionResolver($this->previousResolver);
            } else {
                Model::unsetConnectionResolver();
            }
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->previousFacadeApplication);
            Container::setInstance($this->previousContainer);
        }
        Mockery::close();
        parent::tearDown();
    }

    #[DataProvider('gateways')]
    public function testForgedCallbacksNeverMarkOrdersPaidOrQueueFulfillment(string $gateway): void
    {
        $configs = [];
        foreach (['', null, false, 0, [], ' ', Fixtures::SECRET] as $secret) $configs[] = Fixtures::config($gateway, $secret);
        $configs[] = ['pid' => '123'];
        foreach ($configs as $config) {
            [$id, $uuid, $orderNo] = $this->seed($gateway, $config);
            $secret = $config[Fixtures::secretField($gateway)] ?? null;
            // The healthy-key control receives a signature made without its private key.
            $forgedKey = $secret !== Fixtures::SECRET && is_scalar($secret) ? (string) $secret : '';
            $this->reject($gateway, $uuid, $this->makeCallback($gateway, $orderNo, $forgedKey));
            $this->assertUnpaid($id);
        }
    }

    #[DataProvider('gateways')]
    public function testDisabledChannelsRejectEvenCorrectlySignedCallbacks(string $gateway): void
    {
        [$id, $uuid, $orderNo] = $this->seed($gateway, Fixtures::config($gateway), false);
        $this->reject($gateway, $uuid, $this->makeCallback($gateway, $orderNo, Fixtures::SECRET));
        $this->assertUnpaid($id);
    }

    #[DataProvider('gateways')]
    public function testLegitimateCallbacksStillCreditOnceAndKeepTheirAcknowledgements(string $gateway): void
    {
        [$id, $uuid, $orderNo] = $this->seed($gateway, Fixtures::config($gateway));
        $params = $this->makeCallback($gateway, $orderNo, Fixtures::SECRET);
        $expected = $gateway === 'BEasyPaymentUSDT' ? 'ok' : 'success';
        $this->assertSame($expected, $this->notify($gateway, $uuid, $params));
        $this->assertSame(1, $this->database->getConnection()->table('v2_order')->where('id', $id)->value('status'));
        $this->assertCount(1, $this->jobs);
        $this->assertInstanceOf(OrderHandleJob::class, $this->jobs[0]);
        $this->jobs[0]->handle();
        $this->assertSame(10000, $this->database->getConnection()->table('v2_user')->where('id', $id)->value('balance'));
        $this->assertSame(3, $this->database->getConnection()->table('v2_order')->where('id', $id)->value('status'));
        $this->assertSame($expected, $this->notify($gateway, $uuid, $params));
        $this->assertCount(1, $this->jobs);
        $this->assertSame(10000, $this->database->getConnection()->table('v2_user')->where('id', $id)->value('balance'));
    }

    private function seed(string $gateway, array $config, bool $enabled = true): array
    {
        $id = ++$this->sequence;
        $uuid = 'offline-uuid-' . $id;
        $orderNo = 'offline-order-' . $id;
        $db = $this->database->getConnection();
        $db->table('v2_payment')->insert(['id' => $id, 'uuid' => $uuid, 'payment' => $gateway, 'config' => json_encode($config), 'enable' => (int) $enabled, 'name' => 'Offline test']);
        $db->table('v2_user')->insert(['id' => $id, 'email' => 'audit' . $id . '@example.test', 'balance' => 0, 'created_at' => time(), 'updated_at' => time()]);
        $db->table('v2_order')->insert(['id' => $id, 'user_id' => $id, 'plan_id' => 0, 'payment_id' => $id, 'status' => 0, 'type' => 9, 'total_amount' => 10000, 'trade_no' => $orderNo, 'balance_amount' => 0, 'surplus_amount' => 0, 'discount_amount' => 0, 'refund_amount' => 0, 'period' => 'deposit', 'created_at' => time(), 'updated_at' => time()]);
        return [$id, $uuid, $orderNo];
    }

    private function makeCallback(string $gateway, string $orderNo, string $secret): array
    {
        return Fixtures::callback($gateway, [$gateway === 'BEasyPaymentUSDT' ? 'order_id' : 'out_trade_no' => $orderNo], $secret);
    }

    private function notify(string $gateway, string $uuid, array $params)
    {
        $request = Request::create('/api/v1/guest/payment/notify/' . $gateway . '/' . $uuid, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($params));
        return (new PaymentController())->notify($gateway, $uuid, $request);
    }

    private function reject(string $gateway, string $uuid, array $params): void
    {
        try {
            $this->notify($gateway, $uuid, $params);
            $this->fail('Forged callback was accepted');
        } catch (HttpException $error) {
            $this->assertSame(500, $error->getStatusCode());
        }
    }

    private function assertUnpaid(int $id): void
    {
        $db = $this->database->getConnection();
        $order = $db->table('v2_order')->where('id', $id)->first();
        $this->assertSame(0, $order->status);
        $this->assertNull($order->callback_no);
        $this->assertNull($order->paid_at);
        $this->assertSame(0, $db->table('v2_user')->where('id', $id)->value('balance'));
        $this->assertCount(0, $this->jobs);
    }
}
