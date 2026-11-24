<?php

namespace Tests\Unit;

use App\Http\Middleware\RequestLog;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Contracts\Log\ContextLogProcessor;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LogReportTestException extends RuntimeException
{
    public function context(): array
    {
        return ['config' => ['payment' => ['secret' => 'sentinel-reported-context']]];
    }
}

/** Real logging channels with synthetic secrets, SQLite and isolated temporary files. */
class LogSecurityTest extends TestCase
{
    private $previousContainer;
    private $previousFacadeApplication;
    private $previousResolver;
    private Application $app;
    private Manager $database;
    private string $logDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) $this->markTestSkipped('Requires PDO SQLite');
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->logDirectory = sys_get_temp_dir() . '/v2board-log-test-' . bin2hex(random_bytes(8));
        mkdir($this->logDirectory);
        $logging = require dirname(__DIR__, 2) . '/config/logging.php';
        $logging['channels']['daily']['path'] = $this->logDirectory . '/daily.log';
        $logging['channels']['single']['path'] = $this->logDirectory . '/single.log';
        $logging['channels']['emergency']['path'] = $this->logDirectory . '/emergency.log';
        $this->app->instance('config', new Config(['logging' => $logging, 'app' => ['env' => 'testing']]));
        $this->app->instance('events', new Dispatcher($this->app));
        $this->app->bind(ContextLogProcessor::class, \Illuminate\Log\Context\ContextLogProcessor::class);
        $this->database = new Manager($this->app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->bootEloquent();
        $this->app->instance('db', $this->database->getDatabaseManager());
        $this->database->getConnection()->statement('CREATE TABLE v2_log (id INTEGER PRIMARY KEY, title TEXT, level TEXT, host TEXT, uri TEXT, method TEXT, ip TEXT, data TEXT, context TEXT, created_at INTEGER, updated_at INTEGER)');
    }

    protected function tearDown(): void
    {
        if (isset($this->app) && $this->app->resolved('log')) {
            foreach (['mysql', 'single', 'daily', 'stack'] as $channel) {
                foreach ($this->app['log']->channel($channel)->getHandlers() as $handler) $handler->close();
            }
        }
        if (isset($this->database)) $this->database->getDatabaseManager()->disconnect();
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver);
        else Model::unsetConnectionResolver();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        if (isset($this->logDirectory)) {
            foreach (glob($this->logDirectory . '/*') as $file) unlink($file);
            rmdir($this->logDirectory);
        }
        parent::tearDown();
    }

    public function testAdminRequestNeverCopiesSecretsToDatabase(): void
    {
        $request = Request::create('https://audit.example.test/api/v1/admin/sentinel-path-secret?auth_data=sentinel-query-jwt&token=sentinel-query-token', 'POST', [
            'password' => 'sentinel-password',
            'email_password' => 'sentinel-mail-password',
            'server_token' => 'sentinel-node-token',
            'config' => ['payment' => ['secret' => 'sentinel-payment-key'], 'captcha' => 'sentinel-verification-code'],
            'user' => ['id' => 7], 'id' => 42,
        ]);
        $action = 'App\\Http\\Controllers\\V1\\Admin\\ConfigController@save';
        $request->setRouteResolver(static fn () => new Route('POST', '/admin/config/save', ['uses' => $action, 'controller' => $action]));
        $this->app->instance('request', $request);
        $response = (new RequestLog())->handle($request, static fn () => new Response('invalid', 422));
        $this->assertSame(422, $response->getStatusCode());
        $row = $this->database->getConnection()->table('v2_log')->first();
        $this->assertNotNull($row, 'The real request middleware must create a useful audit record');
        $this->assertSame('Admin POST request', $row->title);
        $this->assertSame('POST', $row->method);
        $this->assertSame($action, $row->uri);
        $context = json_decode($row->context, true);
        $this->assertSame(7, $context['actor_id']);
        $this->assertSame(42, $context['target_id']);
        $this->assertSecretsAbsent(json_encode($row), [
            'sentinel-path-secret', 'sentinel-query-jwt', 'sentinel-query-token', 'sentinel-password', 'sentinel-mail-password',
            'sentinel-node-token', 'sentinel-payment-key', 'sentinel-verification-code',
        ]);
    }

    public function testDatabaseAndConfiguredFileChannelsKeepRedactedDiagnostics(): void
    {
        $this->app->instance('request', Request::create('https://audit.example.test/action?jwt=sentinel-request-jwt'));
        $exception = (static function (string $localPassword): RuntimeException {
            return new RuntimeException(
                'Cache connection timed out; password=sentinel-exception-password',
                42,
                new RuntimeException('Connection reset by peer')
            );
        })('sentinel-stack-argument');
        $context = [
            'job' => [
                'attempt' => 2,
                'secret' => 'sentinel-nested-key',
                'endpoint' => '/internal/health?session=sentinel-context-query',
            ],
            'request_data' => ['value' => 'sentinel-raw-payload'],
            'token' => 'sentinel-context-token',
            'exception' => $exception,
        ];
        foreach (['mysql', 'single', 'daily', 'stack'] as $channel) {
            $this->app['log']->channel($channel)->error('Retry failed. Authorization: Bearer sentinel-message-secret', $context);
        }
        $row = $this->database->getConnection()->table('v2_log')->where('level', 'ERROR')->first();
        $this->assertNotNull($row);
        $this->assertStringStartsWith('Retry failed.', $row->title);
        $storedContext = json_decode($row->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $storedContext['job']['attempt']);
        $this->assertSame(RuntimeException::class, $storedContext['exception_type']);
        $this->assertSame('42', $storedContext['exception_code']);
        $this->assertStringStartsWith('Cache connection timed out;', $storedContext['exception_message']);
        $this->assertNotEmpty($storedContext['exception_trace']);
        $this->assertSame('Connection reset by peer', $storedContext['exception_previous']['message']);
        $files = glob($this->logDirectory . '/*');
        $this->assertNotEmpty($files, 'Configured file channels must actually write');
        $fileOutput = implode('', array_map('file_get_contents', $files));
        $this->assertSame(3, substr_count($fileOutput, 'Cache connection timed out;'), 'single, daily and stack each retain the error reason');
        $this->assertSecretsAbsent(json_encode($row) . $fileOutput, [
            'sentinel-message-secret', 'sentinel-exception-password', 'sentinel-stack-argument',
            'sentinel-nested-key', 'sentinel-context-token', 'sentinel-context-query', 'sentinel-request-jwt', 'sentinel-raw-payload',
        ]);
    }

    public function testQueryExceptionKeepsErrorCodesWithoutSqlOrBindings(): void
    {
        $this->app->instance('request', Request::create('/'));
        $driverError = new PDOException("Duplicate entry 'sentinel-driver-value'");
        $driverError->errorInfo = ['23000', 1062, $driverError->getMessage()];
        $exception = new QueryException('mysql', 'insert into accounts (password) values (?)', ['sentinel-sql-binding'], $driverError);

        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->report($exception);

        $row = $this->database->getConnection()->table('v2_log')->where('level', 'ERROR')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('SQLSTATE 23000', $row->title);
        $this->assertStringContainsString('driver code 1062', $row->context);
        $this->assertSecretsAbsent(json_encode($row), ['sentinel-driver-value', 'sentinel-sql-binding', 'insert into accounts']);
    }

    public function testHttpExceptionKeepsStatusWithoutRequestUrlOrResponseBody(): void
    {
        $this->app->instance('request', Request::create('/'));
        $request = new PsrRequest('POST', 'https://example.test/path?token=sentinel-upstream-url');
        $response = new PsrResponse(502, [], 'sentinel-upstream-body');
        $exceptions = [
            new \Illuminate\Http\Client\RequestException(new \Illuminate\Http\Client\Response($response)),
            GuzzleRequestException::create($request, $response),
            GuzzleRequestException::create($request),
        ];

        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        foreach ($exceptions as $exception) {
            $handler->report($exception);
        }

        $rows = $this->database->getConnection()->table('v2_log')->where('level', 'ERROR')->orderBy('id')->get();
        $this->assertSame([
            'HTTP request failed (status 502)',
            'HTTP request failed (status 502)',
            'Error completing request',
        ], $rows->pluck('title')->all());
        foreach ($rows as $row) {
            $this->assertSecretsAbsent(json_encode($row), ['sentinel-upstream-body', 'sentinel-upstream-url']);
        }
    }

    public function testDatabaseWriteFailureDoesNotLeakTheExceptionToDailyLog(): void
    {
        $this->app->instance('request', Request::create('/?token=sentinel-fallback-token'));
        $this->database->getConnection()->statement('DROP TABLE v2_log');
        $this->app['log']->error('Report export failed', ['password' => 'sentinel-fallback-password']);
        $files = glob($this->logDirectory . '/*');
        $this->assertNotEmpty($files, 'Failure should produce an isolated file fallback');
        $output = implode('', array_map('file_get_contents', $files));
        $this->assertStringContainsString('System log database write failed', $output);
        $this->assertStringContainsString('Report export failed', $output);
        $this->assertStringContainsString('Database operation failed', $output);
        $this->assertSecretsAbsent($output, ['sentinel-fallback-token', 'sentinel-fallback-password']);
    }

    public function testLaravelExceptionReportingProtectsDatabaseAndFileLogs(): void
    {
        $this->app->instance('request', Request::create('/admin/path-secret?jwt=sentinel-reported-jwt'));
        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $message = 'Unable to restore the job; token=sentinel-reported-message';
        $handler->report(new LogReportTestException($message));
        $row = $this->database->getConnection()->table('v2_log')->where('level', 'ERROR')->first();
        $this->assertNotNull($row, 'Laravel exception handler must actually write to the default database logger');
        $context = json_decode($row->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(LogReportTestException::class, $context['exception_type']);
        $this->assertStringStartsWith('Unable to restore the job;', $row->title);
        $this->assertStringStartsWith('Unable to restore the job;', $context['exception_message']);
        $secrets = ['sentinel-reported-message', 'sentinel-reported-context', 'sentinel-reported-jwt'];
        $this->assertSecretsAbsent(json_encode($row), $secrets);

        $this->app['config']->set('logging.default', 'single');
        $handler->report(new LogReportTestException($message));
        $path = $this->logDirectory . '/single.log';
        $this->assertFileExists($path, 'Laravel exception handler must actually write to the configured file logger');
        $output = file_get_contents($path);
        $this->assertStringContainsString('LogReportTestException', $output);
        $this->assertStringContainsString('Unable to restore the job;', $output);
        $this->assertSecretsAbsent($output, $secrets);
    }

    public function testChannelInitializationFailureUsesSafeEmergencyLogger(): void
    {
        $this->app->instance('request', Request::create('/'));
        $this->app['config']->set('logging.channels.broken', ['driver' => 'nonexistent']);
        $logger = $this->app['log']->channel('broken');
        $logger->error('Queue dispatch failed. token=sentinel-emergency-message', [
            'exception' => new RuntimeException('Cache unavailable. password: sentinel-emergency-exception'),
        ]);
        foreach ($logger->getHandlers() as $handler) $handler->close();
        $output = file_get_contents($this->logDirectory . '/emergency.log');
        $this->assertStringContainsString('Queue dispatch failed.', $output);
        $this->assertStringContainsString('Cache unavailable.', $output);
        $this->assertSecretsAbsent($output, ['sentinel-emergency-message', 'sentinel-emergency-exception']);
    }

    private function assertSecretsAbsent(string $output, array $secrets): void
    {
        foreach ($secrets as $secret) $this->assertStringNotContainsString($secret, $output);
    }
}
