<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\UserController as AdminUserController;
use App\Http\Middleware\Admin as AdminMiddleware;
use App\Http\Middleware\Staff as StaffMiddleware;
use App\Http\Middleware\User as UserMiddleware;
use App\Http\Requests\Admin\UserUpdate;
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
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Real authentication, middleware, controller, SQL and cache; no .env or external services. */
class AuthSessionSecurityTest extends TestCase
{
    private $previousContainer;
    private $previousFacadeApplication;
    private $previousResolver;
    private ?Application $app = null;
    private Manager $database;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) $this->markTestSkipped('Requires PDO SQLite');
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Config(['app' => ['key' => str_repeat('offline-auth-session-test-', 3)]]));
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
        $this->database->getConnection()->statement('CREATE TABLE v2_user (id INTEGER PRIMARY KEY, email TEXT UNIQUE, token TEXT, is_admin INTEGER DEFAULT 0, is_staff INTEGER DEFAULT 0, banned INTEGER DEFAULT 0, group_id INTEGER, invite_user_id INTEGER, created_at INTEGER, updated_at INTEGER)');
        $responses = Mockery::mock(ResponseFactoryContract::class);
        $responses->shouldReceive('make')->andReturnUsing(static fn ($data = '', $status = 200, $headers = []) => new \Illuminate\Http\Response($data, $status, $headers));
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

    public static function roles(): array
    {
        return ['user' => [0, 0], 'admin' => [1, 0], 'staff' => [0, 1], 'admin and staff' => [1, 1]];
    }

    #[DataProvider('roles')]
    public function testActiveSessionsUseCurrentDatabaseRoles(int $admin, int $staff): void
    {
        $service = $this->seed($admin, $staff);
        $jwt = $this->token($service);
        $user = $this->through(UserMiddleware::class, $jwt);
        $this->assertSame(7, $user['id']);
        $this->assertSame('user@example.test', $user['email']);
        $this->assertSame($admin, $user['is_admin']);
        $this->assertSame($staff, $user['is_staff']);
        if ($admin) $this->assertSame(7, $this->through(AdminMiddleware::class, $jwt)['id']);
        else $this->denied(AdminMiddleware::class, $jwt);
        if ($staff) $this->assertSame(7, $this->through(StaffMiddleware::class, $jwt)['id']);
        else $this->denied(StaffMiddleware::class, $jwt);
    }

    public function testSingleSessionRemovalImmediatelyRejectsItsWarmTokenAndKeepsOtherSessions(): void
    {
        $service = $this->seed(1, 1);
        $revoked = $this->token($service);
        $active = $this->token($service);
        $this->legacyCache($revoked);
        $this->legacyCache($active);
        $sessions = $service->getSessions();
        $this->assertCount(2, $sessions);
        $this->assertTrue($service->removeSession(array_key_first($sessions)));
        $this->assertNull($this->app['cache']->get($revoked), 'Single logout removes its legacy JWT cache entry');
        $this->assertFalse(AuthService::decryptAuthData($revoked));
        $this->deniedEverywhere($revoked);
        $this->assertCount(1, $service->getSessions());
        $this->assertSame(7, $this->through(AdminMiddleware::class, $active)['id']);
        $this->assertSame(7, $this->through(StaffMiddleware::class, $active)['id']);
    }

    public function testAllSessionRemovalRejectsEveryWarmTokenAndDeletesLegacyEntries(): void
    {
        $service = $this->seed(1, 1);
        $tokens = [$this->token($service), $this->token($service)];
        foreach ($tokens as $jwt) $this->legacyCache($jwt);
        $this->assertTrue($service->removeAllSession());
        $this->assertSame([], $service->getSessions());
        foreach ($tokens as $jwt) {
            $this->assertNull($this->app['cache']->get($jwt));
            $this->assertFalse(AuthService::decryptAuthData($jwt));
            $this->deniedEverywhere($jwt);
        }
    }

    public function testHistoricalOrphanCacheCannotSurviveSingleLogoutThenGlobalLogout(): void
    {
        $service = $this->seed(1, 1);
        $orphan = $this->token($service);
        $active = $this->token($service);
        $service->removeSession(array_key_first($service->getSessions()));
        // Old deployments could leave this JWT=>role snapshot after single logout.
        $this->app['cache']->put($orphan, $this->roleSnapshot(), 3600);
        $this->legacyCache($active);
        $service->removeAllSession();
        $this->assertFalse(AuthService::decryptAuthData($orphan));
        $this->assertFalse(AuthService::decryptAuthData($active));
        $this->deniedEverywhere($orphan);
    }

    public function testSessionLockTimeoutCannotMutateSessionsOrIssueSuccessfulLoginCredentials(): void
    {
        $service = $this->seed(1, 1);
        $jwt = $this->token($service);
        $this->legacyCache($jwt);
        $sessions = $service->getSessions();
        $lock = Mockery::mock(LockContract::class);
        $lock->shouldReceive('block')->with(5, Mockery::type('callable'))->times(3)->andThrow(new LockTimeoutException());
        $cache = Mockery::mock(Repository::class, [$this->app['cache']->getStore()])->makePartial();
        $cache->shouldReceive('lock')->with(CacheKey::get('USER_SESSIONS', 7) . ':lock', 10)->times(3)->andReturn($lock);
        $this->app->instance('cache', $cache);
        Facade::clearResolvedInstance('cache');

        $this->assertFalse($service->removeSession(array_key_first($sessions)));
        $this->assertFalse($service->removeAllSession());
        try {
            $service->generateAuthData(Request::create('/'));
            $this->fail('Login issued credentials without persisting its session');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->assertSame($sessions, $service->getSessions());
        $this->assertSame($this->roleSnapshot(), $cache->get($jwt));
        $this->assertSame(7, $this->through(UserMiddleware::class, $jwt)['id']);
    }

    public function testRealAdminDowngradeImmediatelyBlocksOldTokenAndCannotRestoreItsOwnRoles(): void
    {
        $this->seed(1, 1);
        $jwt = $this->token(new AuthService(User::find(7)));
        $this->legacyCache($jwt);
        $this->assertSame(7, $this->through(AdminMiddleware::class, $jwt)['id']);
        $this->assertSame(7, $this->through(StaffMiddleware::class, $jwt)['id']);

        $request = $this->updateRequest(['email' => 'changed@example.test', 'is_admin' => 0, 'is_staff' => 0]);
        $response = (new AdminMiddleware())->handle($this->authorized($jwt), static fn () => (new AdminUserController())->update($request));
        $this->assertSame(200, $response->getStatusCode());
        $current = $this->through(UserMiddleware::class, $jwt);
        $this->assertSame('changed@example.test', $current['email']);
        $this->assertSame(0, $current['is_admin']);
        $this->assertSame(0, $current['is_staff']);
        $this->denied(AdminMiddleware::class, $jwt);
        $this->denied(StaffMiddleware::class, $jwt);

        $called = false;
        try {
            (new AdminMiddleware())->handle($this->authorized($jwt), function () use (&$called) {
                $called = true;
                return (new AdminUserController())->update($this->updateRequest(['email' => 'changed@example.test', 'is_admin' => 1, 'is_staff' => 1]));
            });
            $this->fail('A downgraded administrator restored their own privileges');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertFalse($called);
        $this->assertSame(0, User::find(7)->is_admin);
        $this->assertSame(0, User::find(7)->is_staff);
    }

    public static function accountChanges(): array
    {
        return ['disabled' => [false], 'deleted' => [true]];
    }

    #[DataProvider('accountChanges')]
    public function testDatabaseDisableOrDeletionImmediatelyRejectsWarmToken(bool $deleted): void
    {
        $service = $this->seed(1, 1);
        $jwt = $this->token($service);
        $this->legacyCache($jwt);
        if ($deleted) User::where('id', 7)->delete();
        else User::where('id', 7)->update(['banned' => 1]);
        // Leave the session present to test database state independently of logout.
        $this->assertCount(1, $service->getSessions());
        $this->assertFalse(AuthService::decryptAuthData($jwt));
        $this->deniedEverywhere($jwt);
    }

    public function testCacheContentsCannotAuthenticateArbitraryKeysOrForgedTokens(): void
    {
        $service = $this->seed();
        $valid = $this->token($service);
        $session = array_key_first($service->getSessions());
        $tokens = [
            'attacker-selected-cache-key',
            JWT::encode(['id' => 7, 'session' => $session], str_repeat('incorrect-offline-key-', 3), 'HS256'),
            JWT::encode(['id' => 7, 'session' => $session], config('app.key'), 'HS384'),
            substr($valid, 0, strrpos($valid, '.') + 1) . 'forged-signature',
        ];
        foreach ($tokens as $jwt) {
            $this->app['cache']->put($jwt, $this->roleSnapshot(), 3600);
            $this->assertFalse(AuthService::decryptAuthData($jwt));
            $this->deniedEverywhere($jwt);
        }
        $this->assertSame(0, $this->through(UserMiddleware::class, $valid)['is_admin']);
        $this->denied(AdminMiddleware::class, $valid);
    }

    public static function invalidClaims(): array
    {
        return [
            'missing id' => [['session' => 'legacy-session']],
            'boolean id' => [['id' => true, 'session' => 'legacy-session']],
            'float id' => [['id' => 7.5, 'session' => 'legacy-session']],
            'array id' => [['id' => [7], 'session' => 'legacy-session']],
            'object id' => [['id' => (object)['value' => 7], 'session' => 'legacy-session']],
            'null id' => [['id' => null, 'session' => 'legacy-session']],
            'zero id' => [['id' => 0, 'session' => 'legacy-session']],
            'negative id' => [['id' => -7, 'session' => 'legacy-session']],
            'decimal string id' => [['id' => '7.0', 'session' => 'legacy-session']],
            'exponent string id' => [['id' => '7e0', 'session' => 'legacy-session']],
            'whitespace string id' => [['id' => ' 7 ', 'session' => 'legacy-session']],
            'missing session' => [['id' => 7]],
            'empty session' => [['id' => 7, 'session' => '']],
            'array session' => [['id' => 7, 'session' => ['legacy-session']]],
            'boolean session' => [['id' => 7, 'session' => true]],
            'integer session' => [['id' => 7, 'session' => 1]],
            'null session' => [['id' => 7, 'session' => null]],
        ];
    }

    #[DataProvider('invalidClaims')]
    public function testSignedMalformedClaimsCannotAuthenticateEvenWithLegacyRoleCache(array $claims): void
    {
        $this->seed(1, 1);
        $this->app['cache']->put(CacheKey::get('USER_SESSIONS', 7), ['legacy-session' => []]);
        $jwt = JWT::encode($claims, config('app.key'), 'HS256');
        $this->app['cache']->put($jwt, $this->roleSnapshot(), 3600);
        $this->assertFalse(AuthService::decryptAuthData($jwt));
        $this->deniedEverywhere($jwt);
    }

    public function testLegacySignedPayloadWithIntegerOrNumericStringIdRemainsCompatible(): void
    {
        $this->seed(0, 1);
        $this->app['cache']->put(CacheKey::get('USER_SESSIONS', 7), ['legacy-session' => ['ip' => '192.0.2.1']]);
        foreach ([7, '7'] as $id) {
            // Existing JWTs have no issued-at or expiry fields; extra role claims confer no permissions.
            $jwt = JWT::encode(['id' => $id, 'session' => 'legacy-session', 'is_admin' => 1], config('app.key'), 'HS256');
            $this->app['cache']->put($jwt, $this->roleSnapshot(), 3600);
            $this->assertSame(7, $this->through(UserMiddleware::class, $jwt)['id']);
            $this->assertSame(7, $this->through(StaffMiddleware::class, $jwt)['id']);
            $this->denied(AdminMiddleware::class, $jwt);
        }
    }

    private function seed(int $admin = 0, int $staff = 0): AuthService
    {
        $this->database->getConnection()->table('v2_user')->insert([
            'id' => 7, 'email' => 'user@example.test', 'token' => 'offline-token',
            'is_admin' => $admin, 'is_staff' => $staff, 'banned' => 0, 'created_at' => 1000, 'updated_at' => 1000,
        ]);
        return new AuthService(User::find(7));
    }

    private function token(AuthService $service): string
    {
        return $service->generateAuthData(Request::create('/'))['auth_data'];
    }

    private function roleSnapshot(): array
    {
        return ['id' => 7, 'email' => 'user@example.test', 'is_admin' => 1, 'is_staff' => 1];
    }

    private function legacyCache(string $jwt): void
    {
        $this->assertSame(7, AuthService::decryptAuthData($jwt)['id']);
        // Simulate a cache entry populated by the previous application version.
        $this->app['cache']->put($jwt, $this->roleSnapshot(), 3600);
    }

    private function authorized(string $jwt): Request
    {
        $request = Request::create('/');
        $request->headers->set('authorization', $jwt);
        return $request;
    }

    private function through(string $middleware, string $jwt): array
    {
        return (new $middleware())->handle($this->authorized($jwt), static fn (Request $request) => $request->input('user'));
    }

    private function denied(string $middleware, string $jwt): void
    {
        try {
            $this->through($middleware, $jwt);
            $this->fail('Unauthorized token passed ' . $middleware);
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    private function deniedEverywhere(string $jwt): void
    {
        foreach ([UserMiddleware::class, AdminMiddleware::class, StaffMiddleware::class] as $middleware) $this->denied($middleware, $jwt);
    }

    private function updateRequest(array $params): UserUpdate
    {
        $request = UserUpdate::create('/', 'POST', array_merge(['id' => 7, 'banned' => 0], $params));
        $request->setContainer($this->app);
        $request->setRedirector(new Redirector(new UrlGenerator(new RouteCollection(), Request::create('/'))));
        $request->validateResolved();
        return $request;
    }
}
