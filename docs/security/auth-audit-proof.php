<?php
/** Offline A01/A02 rejection regressions. Synthetic data only. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!extension_loaded('pdo_sqlite') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "This offline audit proof requires PDO SQLite.\n");
    exit(2);
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Http\Controllers\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Middleware\Admin;
use App\Http\Requests\Admin\UserUpdate;
use App\Http\Requests\Passport\AuthForget;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\CacheKey;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Symfony\Component\HttpKernel\Exception\HttpException;

$app = new Application(dirname(__DIR__, 2));
$app->instance('config', new Config(['app' => ['key' => str_repeat('audit-synthetic-key-', 3)], 'v2board' => ['app_url' => 'https://offline.example.test']]));
$app->instance('request', Request::create('https://offline.example.test/'));
$app->instance('translator', new Translator(new ArrayLoader(), 'en'));
$app->instance('cache', new Repository(new ArrayStore()));
$app->instance('validator', new Factory($app['translator'], $app));
$app->instance(ValidationFactory::class, $app['validator']);
Facade::clearResolvedInstances();
Facade::setFacadeApplication($app);
$database = new Manager($app);
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$database->bootEloquent();
$app->instance('db', $database->getDatabaseManager());
$database->getConnection()->statement('CREATE TABLE v2_user (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password TEXT, password_algo TEXT, password_salt TEXT, token TEXT, uuid TEXT, is_admin INTEGER DEFAULT 0, is_staff INTEGER DEFAULT 0, banned INTEGER DEFAULT 0, plan_id INTEGER, group_id INTEGER, invite_user_id INTEGER, last_login_at INTEGER, created_at INTEGER, updated_at INTEGER)');
$database->getConnection()->statement("ALTER TABLE v2_user ADD auth_version TEXT NOT NULL DEFAULT ''");
$responses = Mockery::mock(ResponseFactoryContract::class);
$responses->shouldReceive('make')->andReturnUsing(static fn ($data = '', $status = 200, $headers = []) => new Illuminate\Http\Response($data, $status, $headers));
$app->instance(ResponseFactoryContract::class, $responses);

function seed(int $id, bool $admin = false): User {
    return User::create(['id' => $id, 'email' => "user{$id}@example.test", 'password' => password_hash('synthetic-old-password', PASSWORD_DEFAULT), 'token' => "synthetic-token-{$id}", 'uuid' => "synthetic-uuid-{$id}", 'is_admin' => (int)$admin, 'is_staff' => 0, 'banned' => 0]);
}
function updateRequest(Application $app, int $id, array $fields): UserUpdate {
    $request = UserUpdate::create('/', 'POST', array_replace(['id' => $id, 'email' => "user{$id}@example.test", 'banned' => 0, 'is_admin' => 0, 'is_staff' => 0], $fields));
    $request->setContainer($app);
    $request->setRedirector(new Redirector(new UrlGenerator(new RouteCollection(), Request::create('/'))));
    $request->validateResolved();
    return $request;
}
function adminAccepts(string $jwt, ?Closure $next = null): bool {
    $request = Request::create('/api/v1/synthetic-admin/user/update', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => $jwt]);
    try {
        return (new Admin())->handle($request, $next ?? static fn () => true) === true;
    } catch (HttpException $error) {
        if ($error->getStatusCode() === 403) return false;
        throw $error;
    }
}

$results = [];
$user = seed(1);
$auth = new AuthService($user);
$jwt = $auth->generateAuthData(Request::create('/'))['auth_data'];
AuthService::decryptAuthData($jwt);
// Simulate an old worker's authorization cache during an upgrade.
$app['cache']->put($jwt, $user->only(['id', 'email', 'is_admin', 'is_staff']), 3600);
$sessionId = array_key_first($auth->getSessions());
$auth->removeSession($sessionId);
$results['removed_session_absent_from_session_list'] = !isset($auth->getSessions()[$sessionId]);
$results['removed_session_is_rejected_immediately'] = AuthService::decryptAuthData($jwt) === false;
$auth->removeAllSession();
$results['removed_then_remove_all_remains_rejected'] = AuthService::decryptAuthData($jwt) === false;
Carbon::setTestNow(Carbon::now()->addSeconds(3601));
$results['removed_session_rejected_after_cache_expiry'] = AuthService::decryptAuthData($jwt) === false;
Carbon::setTestNow();

$adminUser = seed(2, true);
$adminAuth = new AuthService($adminUser);
$adminJwt = $adminAuth->generateAuthData(Request::create('/'))['auth_data'];
$results['admin_initially_authorized'] = adminAccepts($adminJwt);
(new AdminUserController())->update(updateRequest($app, 2, ['is_admin' => 0]));
$results['demotion_saved_to_database'] = (int)User::find(2)->is_admin === 0;
$results['demoted_admin_is_rejected'] = !adminAccepts($adminJwt);
$results['demoted_admin_cannot_restore_admin_through_real_middleware_and_controller'] = !adminAccepts($adminJwt, static function () use ($app) {
    (new AdminUserController())->update(updateRequest($app, 2, ['is_admin' => 1]));
    return true;
}) && (int)User::find(2)->is_admin === 0;

$user = seed(3);
$oldJwt = (new AuthService($user))->generateAuthData(Request::create('/'))['auth_data'];
AuthService::decryptAuthData($oldJwt);
(new AdminUserController())->update(updateRequest($app, 3, ['password' => 'synthetic-new-password']));
$results['admin_password_reset_saved'] = password_verify('synthetic-new-password', User::find(3)->password);
Carbon::setTestNow(Carbon::now()->addSeconds(3601));
$results['old_token_rejected_after_admin_password_reset_and_cache_expiry'] = AuthService::decryptAuthData($oldJwt) === false;
Carbon::setTestNow();

$user = seed(4);
$oldJwt = (new AuthService($user))->generateAuthData(Request::create('/'))['auth_data'];
$urlResponse = (new AuthController())->getQuickLoginUrl(Request::create('/', 'POST', ['auth_data' => $oldJwt]));
$url = json_decode($urlResponse->getContent(), true)['data'];
parse_str(parse_url($url, PHP_URL_FRAGMENT), $fragmentParams);
$quickCode = $fragmentParams['/login?verify'];
$app['cache']->put(CacheKey::get('EMAIL_VERIFY_CODE', $user->email), '123456', 300);
(new AuthController())->forget(AuthForget::create('/', 'POST', ['email' => $user->email, 'email_code' => '123456', 'password' => 'synthetic-reset-password']));
$results['email_reset_revokes_original_token'] = AuthService::decryptAuthData($oldJwt) === false;
try {
    (new AuthController())->token2Login(Request::create('/', 'GET', ['verify' => $quickCode]));
    $results['pre_reset_quick_login_code_is_rejected_after_password_reset'] = false;
} catch (HttpException $error) {
    $results['pre_reset_quick_login_code_is_rejected_after_password_reset'] = $error->getStatusCode() === 500;
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
Carbon::setTestNow();
Mockery::close();
exit(in_array(false, $results, true) ? 1 : 0);
