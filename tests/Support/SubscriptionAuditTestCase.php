<?php

namespace Tests\Support;

use App\Services\SubscriptionAuditService;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PDO;
use PHPUnit\Framework\TestCase;

/** 用真实 SQLite 查询验证审计，不读取项目 .env、不启动完整应用或连接生产服务。 */
abstract class SubscriptionAuditTestCase extends TestCase
{
    protected $container;
    protected $database;
    protected $jobs = [];
    protected $logger;
    private $previousContainer;
    private $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) $this->markTestSkipped('Requires PDO SQLite');
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->container = new Container();
        Container::setInstance($this->container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->container);
        $this->container->instance('config', new ConfigRepository([
            'app' => ['key' => 'audit-isolated-test-key'],
            'v2board' => [
                'insider_guard_subscribe_audit_enabled' => 1,
                'insider_guard_subscribe_audit_retention_days' => 3,
                'insider_guard_subscribe_audit_max_records' => 100000,
            ],
        ]));
        $this->database = new Manager($this->container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $this->container->instance('db', $this->database->getDatabaseManager());
        $this->container->instance('cache', new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore()));
        $this->logger = new class {
            public $warnings = [];
            public function channel($name) { return $this; }
            public function warning($message, array $context = []) {
                $record = new \Monolog\LogRecord(new \DateTimeImmutable(), 'daily', \Monolog\Level::Warning, $message, $context);
                $safe = (new \App\Logging\SafeLogProcessor())($record);
                $this->warnings[] = [$safe->message, $safe->context];
            }
        };
        $this->container->instance('log', $this->logger);
        $dispatcher = Mockery::mock(DispatcherContract::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($job) {
            $this->jobs[] = $job;
            return null;
        });
        $this->container->instance(DispatcherContract::class, $dispatcher);
        $responses = Mockery::mock(ResponseFactoryContract::class);
        $responses->shouldReceive('make')->andReturnUsing(function ($content = '', $status = 200, array $headers = []) {
            return new \Illuminate\Http\Response($content, $status, $headers);
        });
        $responses->shouldReceive('json')->andReturnUsing(function ($data = [], $status = 200, array $headers = [], $options = 0) {
            return new \Illuminate\Http\JsonResponse($data, $status, $headers, $options);
        });
        $this->container->instance(ResponseFactoryContract::class, $responses);
        $this->database->schema()->create(SubscriptionAuditService::TABLE, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('event_id', 32)->unique();
            $table->unsignedInteger('user_id');
            $table->string('email', 64);
            $table->string('ip_address', 45);
            $table->string('ua', 512);
            $table->string('source', 16);
            $table->string('format', 64);
            $table->string('result', 16);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('requested_at');
            $table->text('request_details')->nullable();
            $table->index(['requested_at', 'id']);
            $table->index(['user_id', 'requested_at', 'id']);
            $table->index(['ip_address', 'requested_at', 'id']);
        });
    }

    protected function tearDown(): void
    {
        if ($this->container) {
            $this->database->getDatabaseManager()->disconnect();
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->previousFacadeApplication);
            Container::setInstance($this->previousContainer);
        }
        Mockery::close();
        parent::tearDown();
    }

    protected function event(array $overrides = []): array
    {
        return array_merge([
            'event_id' => bin2hex(random_bytes(16)), 'user_id' => 7, 'email' => 'audit@example.test',
            'ip_address' => '198.51.100.7', 'ua' => 'Audit Test/1.0', 'source' => 'subscription',
            'format' => 'ClashMeta', 'result' => 'success', 'status' => 200,
            'duration_ms' => 12, 'requested_at' => time(), 'request_details' => null,
        ], $overrides);
    }
}
