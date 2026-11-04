<?php

namespace Tests\Unit;

use App\Console\Commands\NormalizeEmail;
use App\Http\Controllers\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Controllers\V1\Passport\CommController;
use App\Http\Requests\Admin\UserGenerate;
use App\Http\Requests\Admin\UserUpdate;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Models\User;
use App\Services\AuthService;
use App\Services\EmailIdentityService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Real controllers, validation, cache and SQL; no .env, network or production data. */
class EmailIdentitySecurityTest extends TestCase
{
    private $previousContainer;
    private $previousFacadeApplication;
    private $previousResolver;
    private ?Application $app = null;
    private Manager $database;
    private array $jobs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) $this->markTestSkipped('Requires PDO SQLite');
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Config([
            'app' => ['key' => str_repeat('offline-email-test-', 3)],
            'v2board' => ['password_limit_enable' => 1, 'password_limit_count' => 1, 'password_limit_expire' => 60, 'subscribe_url' => 'https://subscription.example.test'],
        ]));
        $this->app->instance('request', Request::create('https://offline.example.test/'));
        $this->app->instance('translator', new Translator(new ArrayLoader(), 'en'));
        $this->app->instance('cache', new Repository(new ArrayStore()));
        $this->app->instance('validator', new Factory($this->app['translator'], $this->app));
        $this->app->instance(ValidationFactory::class, $this->app['validator']);
        $this->app->instance(RateLimiter::class, new RateLimiter($this->app['cache']));
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
        // Approximate utf8mb4_unicode_ci so a missing byte comparison is exposed.
        $db->getPdo()->sqliteCreateCollation('EMAIL_CI', static function ($left, $right) {
            $fold = static fn ($value) => strtolower(strtr($value, ['é' => 'e', 'É' => 'E']));
            return strcmp($fold($left), $fold($right));
        });
        $db->statement('CREATE TABLE v2_user (id INTEGER PRIMARY KEY, email TEXT COLLATE EMAIL_CI UNIQUE, password TEXT, password_algo TEXT, password_salt TEXT, token TEXT, uuid TEXT, is_admin INTEGER DEFAULT 0, is_staff INTEGER DEFAULT 0, banned INTEGER DEFAULT 0, plan_id INTEGER, group_id INTEGER, transfer_enable INTEGER, device_limit INTEGER, expired_at INTEGER, invite_user_id INTEGER, last_login_at INTEGER, created_at INTEGER, updated_at INTEGER)');
        $dispatcher = Mockery::mock(DispatcherContract::class);
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($job) { $this->jobs[] = $job; return null; });
        $this->app->instance(DispatcherContract::class, $dispatcher);
        $responses = Mockery::mock(ResponseFactoryContract::class);
        $responses->shouldReceive('make')->andReturnUsing(static fn ($data = '', $status = 200, $headers = []) => new \Illuminate\Http\Response($data, $status, $headers));
        $responses->shouldReceive('json')->andReturnUsing(static fn ($data = [], $status = 200, $headers = [], $options = 0) => new \Illuminate\Http\JsonResponse($data, $status, $headers, $options));
        $this->app->instance(ResponseFactoryContract::class, $responses);
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

    public static function caseVariants(): array
    {
        return array_map(static fn ($email) => [$email], [
            'Admin@example.com', 'ADMIN@example.com', 'aDmIn@example.com',
            'admin@EXAMPLE.COM', 'ADMIN@EXAMPLE.COM', " \tAdmin@Example.com\r\n",
        ]);
    }

    #[DataProvider('caseVariants')]
    public function testCaseVariantsCannotBypassThePasswordLimit(string $email): void
    {
        $this->seed('Admin@EXAMPLE.COM');
        $this->assertSame('Incorrect email or password', $this->loginFailure('admin@example.com'));
        $this->assertStringContainsString('too many password errors', $this->loginFailure($email));
        $this->assertSame(1, $this->app['cache']->get(CacheKey::get('PASSWORD_ERROR_LIMIT', 'admin@example.com')));
    }

    public function testPasswordLimitUsesTheSameDefaultWhenRecordingFailures(): void
    {
        $settings = $this->app['config']->get('v2board');
        unset($settings['password_limit_enable']);
        $this->app['config']->set('v2board', $settings);
        $this->seed();
        $this->assertSame('Incorrect email or password', $this->loginFailure('admin@example.com'));
        $this->assertStringContainsString('too many password errors', $this->loginFailure('ADMIN@example.com'));
    }

    public function testVerificationDeliveryCacheAndResetUseOneIdentityAndRevokeSessions(): void
    {
        $this->seed(' Admin@EXAMPLE.COM ');
        $user = User::find(7);
        $session = (new AuthService($user))->generateAuthData(Request::create('/'))['auth_data'];
        $request = $this->request(CommSendEmailVerify::class, ['email' => ' ADMIN@Example.com ', 'isforget' => 1]);
        (new CommController())->sendEmailVerify($request);
        $this->assertCount(1, $this->jobs);
        $property = new \ReflectionProperty($this->jobs[0], 'params');
        $mail = $property->getValue($this->jobs[0]);
        $this->assertSame('admin@example.com', $mail['email']);
        $code = $mail['template_value']['code'];
        $key = CacheKey::get('EMAIL_VERIFY_CODE', 'admin@example.com');
        $this->assertSame($code, $this->app['cache']->get($key));
        $this->reject(fn () => (new CommController())->sendEmailVerify(
            $this->request(CommSendEmailVerify::class, ['email' => 'Admin@EXAMPLE.COM', 'isforget' => 1])
        ), 'Email verification code has been sent');
        $this->assertCount(1, $this->jobs);
        $response = (new AuthController())->forget($this->request(AuthForget::class, [
            'email' => 'aDmIn@example.COM', 'email_code' => $code, 'password' => 'new-offline-password',
        ]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(password_verify('new-offline-password', User::find(7)->password));
        $this->assertNull($this->app['cache']->get($key));
        $this->assertFalse(AuthService::decryptAuthData($session));
        $this->reject(fn () => (new AuthController())->forget($this->request(AuthForget::class, [
            'email' => 'admin@example.com', 'email_code' => $code, 'password' => 'other-offline-password',
        ])), 'Incorrect email verification code');
    }

    public function testRegistrationStoresCanonicalEmailAndRejectsTheExistingCaseVariant(): void
    {
        $this->app['config']->set('v2board.email_verify', 1);
        $key = CacheKey::get('EMAIL_VERIFY_CODE', 'new@example.com');
        $this->app['cache']->put($key, '123456', 300);
        (new AuthController())->register($this->request(AuthRegister::class, [
            'email' => ' New@EXAMPLE.COM ', 'password' => 'offline-password', 'email_code' => '123456',
        ]));
        $this->assertSame('new@example.com', User::first()->email);
        $this->assertNull($this->app['cache']->get($key));
        $this->app['cache']->put($key, '234567', 300);
        $this->reject(fn () => (new AuthController())->register($this->request(AuthRegister::class, [
            'email' => 'NEW@example.COM', 'password' => 'offline-password', 'email_code' => '234567',
        ])), 'Email already exists');
        $this->assertSame(1, User::count());
    }

    public function testAccentsAreNotTheSameAccountEvenUnderAnAccentInsensitiveDatabase(): void
    {
        $this->seed();
        $email = 'admin@éxample.com';
        $this->assertSame(7, User::where('email', $email)->first()->id, 'Test collation really folds accents');
        $this->assertNull(User::findByEmail($email));
        $this->assertFalse(User::byEmail($email)->exists());
        $this->assertSame('Incorrect email or password', $this->loginFailure($email));
        $this->assertNull($this->app['cache']->get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email)));
        foreach ([AuthRegister::class, AuthForget::class, CommSendEmailVerify::class, UserUpdate::class, \App\Http\Requests\Staff\UserUpdate::class] as $class) {
            $this->invalidEmail($class, $email);
        }
        $this->assertCount(0, $this->jobs);
        $this->assertTrue(password_verify('original-offline-password', User::find(7)->password));
    }

    public function testMalformedEmailsAndBooleanCodesStayRejected(): void
    {
        foreach ([false, 2, [], ['address' => 'admin@example.com'], str_repeat('x', 65) . '@example.com'] as $email) {
            foreach ([AuthRegister::class, AuthLogin::class, AuthForget::class, CommSendEmailVerify::class] as $class) {
                $this->invalidEmail($class, $email);
            }
        }
        $this->seed();
        $this->app['cache']->put(CacheKey::get('EMAIL_VERIFY_CODE', 'admin@example.com'), '123456', 300);
        try {
            $this->request(AuthForget::class, ['email' => 'admin@example.com', 'password' => 'offline-password', 'email_code' => false]);
            $this->fail('Boolean code passed validation');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('email_code', $error->errors());
        }
        $direct = AuthForget::create('/', 'POST', ['email' => 'admin@example.com', 'password' => 'offline-password', 'email_code' => false]);
        $this->reject(fn () => (new AuthController())->forget($direct), 'Incorrect email verification code');
        $this->assertTrue(password_verify('original-offline-password', User::find(7)->password));
    }

    public function testResetAttemptLimitIsSharedAcrossCaseVariants(): void
    {
        $this->seed();
        foreach (['admin@example.com', 'ADMIN@EXAMPLE.COM', 'Admin@example.com'] as $email) {
            $this->reject(fn () => (new AuthController())->forget($this->request(AuthForget::class, [
                'email' => $email, 'password' => 'offline-password', 'email_code' => '999999',
            ])), 'Incorrect email verification code');
        }
        $this->reject(fn () => (new AuthController())->forget($this->request(AuthForget::class, [
            'email' => 'aDmIn@example.com', 'password' => 'offline-password', 'email_code' => '999999',
        ])), 'Reset failed, Please try again later');
        $this->assertSame(3, $this->app['cache']->get(CacheKey::get('FORGET_REQUEST_LIMIT', 'admin@example.com')));
    }

    public function testAdminMayCanonicalizeOwnEmailButCannotTakeAnotherUsersIdentity(): void
    {
        $this->seed('Admin@EXAMPLE.COM');
        $this->seed('other@example.com', 8);
        $params = ['id' => 7, 'email' => ' ADMIN@Example.com ', 'banned' => 0, 'is_admin' => 1, 'is_staff' => 0];
        (new AdminUserController())->update($this->request(UserUpdate::class, $params));
        $this->assertSame('admin@example.com', User::find(7)->email);
        $params['email'] = ' OTHER@EXAMPLE.COM ';
        $this->reject(fn () => (new AdminUserController())->update($this->request(UserUpdate::class, $params)), '邮箱已被使用');
        $this->assertSame('admin@example.com', User::find(7)->email);
    }

    public function testAdminSingleAndBulkGenerationAlwaysStoreCanonicalEmails(): void
    {
        (new AdminUserController())->generate($this->request(UserGenerate::class, [
            'email_prefix' => ' New ', 'email_suffix' => ' EXAMPLE.COM ', 'password' => 'offline-password',
        ]));
        $this->assertSame('new@example.com', User::first()->email);
        ob_start();
        try {
            (new AdminUserController())->generate($this->request(UserGenerate::class, [
                'generate_count' => 3, 'email_suffix' => ' EXAMPLE.COM ', 'password' => 'offline-password',
            ]));
        } finally {
            ob_end_clean();
        }
        $this->assertSame(4, User::count());
        foreach (User::all() as $user) $this->assertSame(User::normalizeEmail($user->email), $user->email);
        foreach (['éxample.com', 'example.com@evil.test', str_repeat('x', 64) . '.com'] as $suffix) {
            try {
                $this->request(UserGenerate::class, ['email_suffix' => $suffix, 'generate_count' => 2]);
                $this->fail('Invalid generated address passed validation');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('email_suffix', $error->errors());
            }
        }
    }

    public function testWhitelistAndIdentityNormalizationPreserveDistinctAddresses(): void
    {
        $this->assertTrue(Helper::emailSuffixVerify(' User@EXAMPLE.COM ', [' EXAMPLE.COM ', 'qq.com']));
        $this->assertTrue(Helper::emailSuffixVerify('User@QQ.COM', 'example.com, QQ.com '));
        $this->assertFalse(Helper::emailSuffixVerify('user@éxample.com', ['example.com']));
        $this->assertFalse(Helper::emailSuffixVerify('user@example.com.evil.test', ['example.com']));
        $this->assertSame('a.b+tag@example.com', User::normalizeEmail(' A.B+Tag@EXAMPLE.COM '));
        $this->assertNotSame(User::normalizeEmail('admin@example.com'), User::normalizeEmail('admin@éxample.com'));
    }

    public function testHistoricalNormalizationIsReadOnlyUntilAppliedAndIsIdempotent(): void
    {
        $this->seed(' Admin@EXAMPLE.COM ');
        $before = (array)$this->database->getConnection()->table('v2_user')->where('id', 7)->first();
        $service = new EmailIdentityService();
        $this->assertSame(['changed' => 1, 'conflicts' => []], $service->inspect());
        $this->assertSame($before, (array)$this->database->getConnection()->table('v2_user')->where('id', 7)->first());
        $this->assertSame(7, User::findByEmail('admin@example.com')->id);
        $this->assertSame(1, $service->apply());
        $after = (array)$this->database->getConnection()->table('v2_user')->where('id', 7)->first();
        $this->assertSame(array_replace($before, ['email' => 'admin@example.com']), $after);
        $this->assertSame(0, $service->apply());
        $this->assertSame(7, User::findByEmail('ADMIN@EXAMPLE.COM')->id);
    }

    public function testHistoricalAccountCanLoginBeforeAndAfterDataUpgrade(): void
    {
        $this->seed(' Admin@EXAMPLE.COM ');
        $this->database->getConnection()->statement('CREATE TABLE v2_insider_guard_activity (user_id INTEGER, event_type TEXT, hour_start INTEGER, ip_address TEXT, ua_hash TEXT, times INTEGER, node_count INTEGER, first_at INTEGER, last_at INTEGER, UNIQUE(user_id, event_type, hour_start, ip_address, ua_hash))');
        foreach ([false, true] as $upgrade) {
            if ($upgrade) (new EmailIdentityService())->apply();
            $response = (new AuthController())->login($this->request(AuthLogin::class, [
                'email' => ' ADMIN@Example.com ', 'password' => 'original-offline-password',
            ]));
            $data = json_decode($response->getContent(), true)['data'];
            $this->assertSame('offline-token-7', $data['token']);
            $this->assertSame(7, AuthService::decryptAuthData($data['auth_data'])['id']);
        }
        $this->assertSame('admin@example.com', User::find(7)->email);
    }

    public function testConflictingHistoricalAccountsStopBeforeAnyWrite(): void
    {
        $this->seed('Admin@example.com');
        $this->seed(' admin@example.com ', 8);
        $this->seed('Third@EXAMPLE.COM', 9);
        $before = $this->database->getConnection()->table('v2_user')->orderBy('id')->get()->toJson();
        $service = new EmailIdentityService();
        $this->assertSame([[7, 8]], $service->inspect()['conflicts']);
        try {
            $service->apply();
            $this->fail('Conflicting accounts were normalized');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('冲突', $error->getMessage());
        }
        $this->assertSame($before, $this->database->getConnection()->table('v2_user')->orderBy('id')->get()->toJson());
    }

    public function testUpgradeCommandDefaultsToReadOnlyAndRequiresMaintenanceToWrite(): void
    {
        $this->seed('Admin@EXAMPLE.COM');
        // In-memory maintenance state; no maintenance files or real services touched.
        $maintenance = Mockery::mock(\Illuminate\Contracts\Foundation\MaintenanceMode::class);
        $inMaintenance = false;
        $maintenance->shouldReceive('active')->andReturnUsing(static function () use (&$inMaintenance) { return $inMaintenance; });
        $this->app->instance(\Illuminate\Contracts\Foundation\MaintenanceMode::class, $maintenance);
        $command = new NormalizeEmail();
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $this->assertSame(0, $tester->execute([]));
        $this->assertSame('Admin@EXAMPLE.COM', User::find(7)->email);
        $this->assertSame(1, $tester->execute(['--apply' => true]));
        $this->assertStringContainsString('维护模式', $tester->getDisplay());
        $this->assertSame('Admin@EXAMPLE.COM', User::find(7)->email);
        $inMaintenance = true;
        $this->assertSame(0, $tester->execute(['--apply' => true]));
        $this->assertSame('admin@example.com', User::find(7)->email);
    }

    private function seed(string $email = 'admin@example.com', int $id = 7): void
    {
        // Raw insert deliberately preserves legacy, noncanonical spellings.
        $this->database->getConnection()->table('v2_user')->insert([
            'id' => $id, 'email' => $email, 'password' => password_hash('original-offline-password', PASSWORD_DEFAULT),
            'token' => 'offline-token-' . $id, 'uuid' => 'offline-uuid-' . $id, 'is_admin' => 1,
            'created_at' => 1000, 'updated_at' => 1000,
        ]);
    }

    private function request(string $class, array $params): FormRequest
    {
        $request = $class::create('/', 'POST', $params);
        $request->setContainer($this->app);
        $request->setRedirector(new Redirector(new UrlGenerator(new RouteCollection(), Request::create('/'))));
        $request->validateResolved();
        return $request;
    }

    private function loginFailure(string $email): string
    {
        try {
            (new AuthController())->login($this->request(AuthLogin::class, ['email' => $email, 'password' => 'wrong-offline-password']));
            $this->fail('Wrong password accepted');
        } catch (HttpException $error) {
            $this->assertSame(500, $error->getStatusCode());
            return $error->getMessage();
        }
    }

    private function invalidEmail(string $class, $email): void
    {
        try {
            $this->request($class, ['email' => $email, 'password' => 'offline-password', 'email_code' => '123456', 'banned' => 0, 'is_admin' => 0, 'is_staff' => 0]);
            $this->fail('Invalid email passed validation for ' . $class);
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('email', $error->errors());
        }
    }

    private function reject(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected rejection');
        } catch (HttpException $error) {
            $this->assertSame(500, $error->getStatusCode());
            $this->assertStringContainsString($message, $error->getMessage());
        }
    }
}
