<?php

namespace Tests\Unit;

use App\Console\Commands\ResetPassword;
use App\Http\Controllers\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Controllers\V1\Staff\UserController as StaffUserController;
use App\Http\Controllers\V1\User\UserController;
use App\Http\Requests\Admin\UserUpdate as AdminUserUpdate;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Staff\UserUpdate as StaffUserUpdate;
use App\Http\Requests\User\UserChangePassword;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\CacheKey;
use Firebase\JWT\JWT;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Real controllers, credential writes, SQL and cache; no .env or external services. */
class AuthCredentialSecurityTest extends TestCase
{
    private $previousContainer;
    private $previousFacadeApplication;
    private $previousResolver;
    private $previousTestNow;
    private ?Application $app = null;
    private Manager $database;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) $this->markTestSkipped('Requires PDO SQLite');
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousTestNow = Carbon::getTestNow();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Config([
            'app' => ['key' => str_repeat('offline-auth-credential-test-', 3)],
            'v2board' => ['app_url' => 'https://offline.example.test', 'password_limit_enable' => 0],
        ]));
        $this->app->instance('request', Request::create('https://offline.example.test/'));
        $this->app->instance('cache', new Repository(new ArrayStore()));
        $this->app->instance('translator', new Translator(new ArrayLoader(), 'en'));
        $this->app->instance('validator', new Factory($this->app['translator'], $this->app));
        $this->app->instance(ValidationFactory::class, $this->app['validator']);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->database = new Manager($this->app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->bootEloquent();
        $this->app->instance('db', $this->database->getDatabaseManager());
        $db = $this->database->getConnection();
        $db->statement("CREATE TABLE v2_user (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password TEXT, password_algo TEXT, password_salt TEXT, auth_version TEXT NOT NULL DEFAULT '', token TEXT, uuid TEXT, is_admin INTEGER DEFAULT 0, is_staff INTEGER DEFAULT 0, banned INTEGER DEFAULT 0, plan_id INTEGER, group_id INTEGER, invite_user_id INTEGER, last_login_at INTEGER, created_at INTEGER, updated_at INTEGER)");
        $db->statement('CREATE TABLE v2_insider_guard_activity (user_id INTEGER, event_type TEXT, hour_start INTEGER, ip_address TEXT, ua_hash TEXT, times INTEGER, node_count INTEGER, first_at INTEGER, last_at INTEGER, UNIQUE(user_id, event_type, hour_start, ip_address, ua_hash))');
        $responses = Mockery::mock(ResponseFactoryContract::class);
        $responses->shouldReceive('make')->andReturnUsing(static fn ($data = '', $status = 200, $headers = []) => new \Illuminate\Http\Response($data, $status, $headers));
        $responses->shouldReceive('json')->andReturnUsing(static fn ($data = [], $status = 200, $headers = [], $options = 0) => new \Illuminate\Http\JsonResponse($data, $status, $headers, $options));
        $this->app->instance(ResponseFactoryContract::class, $responses);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            Carbon::setTestNow($this->previousTestNow);
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

    public static function passwordWriters(): array
    {
        return ['admin' => ['admin'], 'staff' => ['staff'], 'self service' => ['user'], 'forgotten password' => ['forget'], 'CLI reset' => ['cli']];
    }

    #[DataProvider('passwordWriters')]
    public function testEveryPasswordResetRevokesSessionsAndOutstandingQuickCodesEvenWhenCacheCleanupFails(string $writer): void
    {
        $service = $this->seed();
        $jwt = $this->token($service);
        $code = $service->generateQuickLoginCode();
        $sessions = $service->getSessions();
        // Revocation must survive an unavailable session cleanup, rather than depend on logout success.
        $cache = $this->partialCache();
        $cache->shouldReceive('forget')->with(CacheKey::get('USER_SESSIONS', 7))->andReturn(false);
        $password = $this->changePassword($writer);
        $this->assertNotSame('', User::find(7)->auth_version);
        $this->assertTrue(password_verify($password, User::find(7)->password));
        $this->assertSame($sessions, $service->getSessions());
        $this->assertFalse(AuthService::decryptAuthData($jwt));
        $this->reject(fn () => $this->consume($code), 500);

        $data = $this->login($password);
        $this->assertSame(7, AuthService::decryptAuthData($data['auth_data'])['id']);
        $freshCode = (new AuthService(User::find(7)))->generateQuickLoginCode();
        $this->assertSame(7, AuthService::decryptAuthData($this->consume($freshCode)['auth_data'])['id']);
        $this->reject(fn () => $this->consume($freshCode), 500);
    }

    public static function restoredChanges(): array
    {
        return ['ban then unban' => ['banned', 1, 0], 'admin removed then restored' => ['is_admin', 0, 1], 'staff removed then restored' => ['is_staff', 0, 1]];
    }

    #[DataProvider('restoredChanges')]
    public function testRestoringAccountStateCannotResurrectOldTokensOrQuickCodes(string $field, int $changed, int $restored): void
    {
        $service = $this->seed(1, 1);
        $jwt = $this->token($service);
        $code = $service->generateQuickLoginCode();
        $this->adminUpdate([$field => $changed]);
        $firstVersion = User::find(7)->auth_version;
        $this->assertNotSame('', $firstVersion);
        $this->assertFalse(AuthService::decryptAuthData($jwt));
        $this->adminUpdate([$field => $restored]);
        $this->assertNotSame($firstVersion, User::find(7)->auth_version);
        $this->assertFalse(AuthService::decryptAuthData($jwt));
        $this->reject(fn () => $this->consume($code), 500);
        $this->assertSame(7, AuthService::decryptAuthData($this->token(new AuthService(User::find(7))))['id']);
    }

    public function testLegacySessionsOnlyRemainValidUntilTheFirstSensitiveChange(): void
    {
        $this->seed();
        $key = CacheKey::get('USER_SESSIONS', 7);
        $legacy = ['legacy-session' => ['ip' => '192.0.2.1']];
        $jwt = JWT::encode(['id' => 7, 'session' => 'legacy-session'], config('app.key'), 'HS256');
        $this->app['cache']->put($key, $legacy);
        $this->assertSame(7, AuthService::decryptAuthData($jwt)['id']);
        $user = User::find(7);
        $user->password_salt = 'new-salt';
        $user->save();
        $this->assertSame($legacy, $this->app['cache']->get($key));
        $this->assertFalse(AuthService::decryptAuthData($jwt));
        // Returning the password fields to their old values cannot restore an old version.
        $user->password_salt = null;
        $user->save();
        $this->assertFalse(AuthService::decryptAuthData($jwt));
    }

    public function testUnrelatedAccountChangesPreserveExistingSessionsAndQuickCodes(): void
    {
        $service = $this->seed();
        $jwt = $this->token($service);
        $code = $service->generateQuickLoginCode();
        $user = User::find(7);
        $user->email = 'renamed@example.test';
        $user->last_login_at = time();
        $user->save();
        $this->assertSame('', User::find(7)->auth_version);
        $this->assertSame('renamed@example.test', AuthService::decryptAuthData($jwt)['email']);
        $this->assertSame(7, AuthService::decryptAuthData($this->consume($code)['auth_data'])['id']);
    }

    public function testUnrelatedUpdatePreservesAnAlreadyRotatedVersion(): void
    {
        $this->seed();
        $user = User::find(7);
        $user->password_salt = 'new-offline-salt';
        $user->save();
        $version = $user->auth_version;
        $this->assertNotSame('', $version);
        $service = new AuthService($user);
        $jwt = $this->token($service);
        $code = $service->generateQuickLoginCode();
        $user->email = 'renamed@example.test';
        $user->save();
        $this->assertSame($version, User::find(7)->auth_version);
        $this->assertSame(7, AuthService::decryptAuthData($jwt)['id']);
        $this->assertSame(7, AuthService::decryptAuthData($this->consume($code)['auth_data'])['id']);
    }

    public function testTwoStaleSensitiveWritersCannotReuseARevocationVersion(): void
    {
        $this->seed();
        $first = User::find(7);
        $second = User::find(7);
        $unrelated = User::find(7);
        $first->password = password_hash('first-offline-password', PASSWORD_DEFAULT);
        $first->save();
        $version = $first->auth_version;
        $service = new AuthService($first);
        $jwt = $this->token($service);
        $code = $service->generateQuickLoginCode();
        $second->password = password_hash('second-offline-password', PASSWORD_DEFAULT);
        $second->save();
        $this->assertNotSame($version, $second->auth_version);
        $this->assertSame($second->auth_version, User::find(7)->auth_version);
        $this->assertFalse(AuthService::decryptAuthData($jwt));
        $this->reject(fn () => $this->consume($code), 500);
        $unrelated->email = 'renamed@example.test';
        $unrelated->save();
        $this->assertSame($second->auth_version, User::find(7)->auth_version);
    }

    public function testAdminBulkBanRotatesCredentialVersionAndUnbanCannotRestoreOldCredentials(): void
    {
        $service = $this->seed();
        $this->database->getConnection()->table('v2_user')->insert([
            'id' => 8, 'email' => 'other@example.test', 'password' => password_hash('other-offline-password', PASSWORD_DEFAULT),
        ]);
        $jwt = $this->token($service);
        $code = $service->generateQuickLoginCode();
        $sessions = $service->getSessions();
        $cache = $this->partialCache();
        $cache->shouldReceive('forget')->with(CacheKey::get('USER_SESSIONS', 7))->andReturn(false);
        $response = (new AdminUserController())->ban(Request::create('/', 'POST', [
            'filter' => [['key' => 'id', 'condition' => '=', 'value' => 7]],
        ]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, User::find(7)->banned);
        $this->assertNotSame('', User::find(7)->auth_version);
        $this->assertSame(0, User::find(8)->banned);
        $this->assertSame('', User::find(8)->auth_version);
        $this->assertSame($sessions, $service->getSessions());
        $this->assertFalse(AuthService::decryptAuthData($jwt));
        $this->adminUpdate(['banned' => 0]);
        $this->assertFalse(AuthService::decryptAuthData($jwt));
        $this->reject(fn () => $this->consume($code), 500);
        $this->assertSame(7, AuthService::decryptAuthData($this->token(new AuthService(User::find(7))))['id']);
    }

    public function testStaleUserSnapshotCannotMintSessionsOrQuickCodesAfterAReset(): void
    {
        $stale = $this->seed();
        $this->adminUpdate(['password' => 'replacement-offline-password']);
        $this->reject(fn () => $this->token($stale), 403);
        $this->reject(fn () => $stale->generateQuickLoginCode(), 403);
        $this->assertSame([], $stale->getSessions());
    }

    public function testFreshUserCannotUpgradeAnOldAuthorizationToANewQuickCode(): void
    {
        $service = $this->seed();
        $jwt = $this->token($service);
        $this->adminUpdate(['password' => 'replacement-offline-password']);
        $fresh = new AuthService(User::find(7));
        $this->reject(fn () => $fresh->generateQuickLoginCode($jwt), 403);
        $this->assertNotSame('', $fresh->generateQuickLoginCode());
    }

    public function testBannedOrDeletedUsersCannotMintCredentialsFromOldOrFreshModels(): void
    {
        $service = $this->seed();
        User::find(7)->update(['banned' => 1]);
        foreach ([$service, new AuthService(User::find(7))] as $candidate) {
            $this->reject(fn () => $this->token($candidate), 403);
            $this->reject(fn () => $candidate->generateQuickLoginCode(), 403);
        }
        User::find(7)->delete();
        $this->reject(fn () => $this->token($service), 403);
        $this->reject(fn () => $service->generateQuickLoginCode(), 403);
    }

    public function testQuickLoginCodeIsConsumedOnceThroughTheRealPassportController(): void
    {
        $service = $this->seed();
        $code = $service->generateQuickLoginCode();
        $this->assertIsArray($this->app['cache']->get(CacheKey::get('TEMP_TOKEN', $code)));
        $controller = new AuthController();
        $response = $controller->token2Login(Request::create('/', 'POST', ['verify' => $code]));
        $data = json_decode($response->getContent(), true)['data'];
        $this->assertSame(7, AuthService::decryptAuthData($data['auth_data'])['id']);
        $this->assertNull($this->app['cache']->get(CacheKey::get('TEMP_TOKEN', $code)));
        $this->reject(fn () => $controller->token2Login(Request::create('/', 'POST', ['verify' => $code])), 500);
        $this->assertCount(1, $service->getSessions());
    }

    public function testLegacyIntegerQuickCodeAndMalformedPayloadsCannotLogin(): void
    {
        $service = $this->seed();
        foreach ([
            7, '7', ['id' => 7], null,
            ['user_id' => 7, 'auth_version' => false],
            ['user_id' => '7', 'auth_version' => ''],
            ['user_id' => true, 'auth_version' => ''],
            ['user_id' => 0, 'auth_version' => ''],
            ['user_id' => -7, 'auth_version' => ''],
            ['user_id' => 7, 'auth_version' => []],
            ['user_id' => 7, 'auth_version' => str_repeat('a', 32)],
        ] as $index => $payload) {
            // A valid code shape ensures these cases reach cache payload validation.
            $code = sprintf('%032x', $index + 1);
            $this->app['cache']->put(CacheKey::get('TEMP_TOKEN', $code), $payload, 60);
            $this->reject(fn () => $this->consume($code), 500);
        }
        foreach ([false, 7, ['code'], null, ''] as $code) $this->reject(fn () => $this->consume($code), 500);
        $this->assertSame([], $service->getSessions());
    }

    public function testQuickLoginCodesExpireAfterSixtySeconds(): void
    {
        Carbon::setTestNow(Carbon::now());
        $service = $this->seed();
        $code = $service->generateQuickLoginCode();
        Carbon::setTestNow(Carbon::now()->addSeconds(59));
        $this->assertNotNull($this->app['cache']->get(CacheKey::get('TEMP_TOKEN', $code)));
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $this->reject(fn () => $this->consume($code), 500);
        $this->assertSame([], $service->getSessions());
    }

    public function testBothQuickUrlControllersIssueVersionedCodesAndRejectOldAuthorization(): void
    {
        $service = $this->seed();
        $jwt = $this->token($service);
        foreach ([new UserController(), new AuthController()] as $controller) {
            $request = $this->authorized($jwt);
            $response = $controller->getQuickLoginUrl($request);
            $url = json_decode($response->getContent(), true)['data'];
            $this->assertMatchesRegularExpression('/[?&]verify=([^&]+)/', $url);
            preg_match('/[?&]verify=([^&]+)/', $url, $matches);
            $this->assertSame(7, AuthService::decryptAuthData($this->consume(urldecode($matches[1]))['auth_data'])['id']);
        }
        $this->adminUpdate(['password' => 'replacement-offline-password']);
        foreach ([new UserController(), new AuthController()] as $controller) {
            // The request has already passed middleware and retains the old authenticated user snapshot.
            $this->reject(fn () => $controller->getQuickLoginUrl($this->authorized($jwt)), 403);
        }
    }

    public function testQuickCodeLockTimeoutCannotConsumeCodeOrIssueSession(): void
    {
        $service = $this->seed();
        $code = $service->generateQuickLoginCode();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        $payload = $this->app['cache']->get($key);
        $lock = Mockery::mock(LockContract::class);
        $lock->shouldReceive('block')->with(5, Mockery::type('callable'))->once()->andThrow(new LockTimeoutException());
        $cache = $this->partialCache();
        $cache->shouldReceive('lock')->with($key . ':lock', 10)->once()->andReturn($lock);
        $this->reject(fn () => $this->consume($code), 503);
        $this->assertSame($payload, $cache->get($key));
        $this->assertSame([], $service->getSessions());
    }

    public function testQuickCodeDeleteFailureCannotReturnSuccessfulCredentials(): void
    {
        $service = $this->seed();
        $code = $service->generateQuickLoginCode();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        $payload = $this->app['cache']->get($key);
        $cache = $this->partialCache();
        $cache->shouldReceive('forget')->with($key)->once()->andReturn(false);
        $this->reject(fn () => $this->consume($code), 503);
        $this->assertSame($payload, $cache->get($key));
        $this->assertSame([], $service->getSessions());
    }

    public function testQuickCodeWriteFailureCannotReturnAUsableCode(): void
    {
        $service = $this->seed();
        $cache = $this->partialCache();
        $cache->shouldReceive('put')->with(Mockery::type('string'), Mockery::type('array'), 60)->once()->andReturn(false);
        $this->reject(fn () => $service->generateQuickLoginCode(), 503);
        $this->assertSame([], $service->getSessions());
    }

    public function testPasswordResetBetweenValidationAndSessionWriteCannotMintAStaleSession(): void
    {
        $service = $this->seed();
        $lock = Mockery::mock(LockContract::class);
        $lock->shouldReceive('block')->with(5, Mockery::type('callable'))->once()->andReturnUsing(function ($wait, $callback) {
            $user = User::find(7);
            $user->password = password_hash('concurrent-replacement-password', PASSWORD_DEFAULT);
            $user->save();
            return $callback();
        });
        $cache = $this->partialCache();
        $cache->shouldReceive('lock')->with(CacheKey::get('USER_SESSIONS', 7) . ':lock', 10)->once()->andReturn($lock);
        $this->reject(fn () => $this->token($service), 403);
        $this->assertSame([], $service->getSessions());
    }

    private function seed(int $admin = 0, int $staff = 0): AuthService
    {
        $this->database->getConnection()->table('v2_user')->insert([
            'id' => 7, 'email' => 'user@example.test', 'password' => password_hash('original-offline-password', PASSWORD_DEFAULT),
            'token' => 'offline-token', 'uuid' => 'offline-uuid', 'is_admin' => $admin, 'is_staff' => $staff,
            'created_at' => 1000, 'updated_at' => 1000,
        ]);
        return new AuthService(User::find(7));
    }

    private function changePassword(string $writer): string
    {
        $password = 'replacement-offline-password';
        if ($writer === 'admin') $this->adminUpdate(['password' => $password]);
        elseif ($writer === 'staff') {
            (new StaffUserController())->update($this->request(StaffUserUpdate::class, [
                'id' => 7, 'email' => 'user@example.test', 'banned' => 0, 'password' => $password,
            ]));
        } elseif ($writer === 'user') {
            (new UserController())->changePassword($this->request(UserChangePassword::class, [
                'user' => ['id' => 7], 'old_password' => 'original-offline-password', 'new_password' => $password,
            ]));
        } elseif ($writer === 'forget') {
            $this->app['cache']->put(CacheKey::get('EMAIL_VERIFY_CODE', 'user@example.test'), '123456', 300);
            (new AuthController())->forget($this->request(AuthForget::class, [
                'email' => 'user@example.test', 'email_code' => '123456', 'password' => $password,
            ]));
        } else {
            $command = new ResetPassword();
            $command->setLaravel($this->app);
            $tester = new CommandTester($command);
            $this->assertSame(0, $tester->execute(['email' => 'user@example.test']));
            $this->assertSame(1, preg_match('/新密码为：(.+)，请尽快修改密码。/u', $tester->getDisplay(), $matches));
            $password = $matches[1];
        }
        return $password;
    }

    private function adminUpdate(array $params): void
    {
        $user = User::find(7);
        (new AdminUserController())->update($this->request(AdminUserUpdate::class, array_merge([
            'id' => 7, 'email' => $user->email, 'banned' => (int)$user->banned,
            'is_admin' => (int)$user->is_admin, 'is_staff' => (int)$user->is_staff,
        ], $params)));
    }

    private function token(AuthService $service): string
    {
        return $service->generateAuthData(Request::create('/'))['auth_data'];
    }

    private function consume($code): array
    {
        return AuthService::loginWithQuickLoginCode($code, Request::create('/'));
    }

    private function login(string $password): array
    {
        $response = (new AuthController())->login($this->request(AuthLogin::class, ['email' => 'user@example.test', 'password' => $password]));
        return json_decode($response->getContent(), true)['data'];
    }

    private function authorized(string $jwt): Request
    {
        $request = Request::create('/', 'POST', ['user' => ['id' => 7]]);
        $request->headers->set('authorization', $jwt);
        return $request;
    }

    private function request(string $class, array $params): FormRequest
    {
        $request = $class::create('/', 'POST', $params);
        $request->setContainer($this->app);
        $request->setRedirector(new Redirector(new UrlGenerator(new RouteCollection(), Request::create('/'))));
        $request->validateResolved();
        return $request;
    }

    private function partialCache(): Repository
    {
        $cache = Mockery::mock(Repository::class, [$this->app['cache']->getStore()])->makePartial();
        $this->app->instance('cache', $cache);
        Facade::clearResolvedInstance('cache');
        return $cache;
    }

    private function reject(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('Expected credential rejection');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }
}
