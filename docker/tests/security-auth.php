<?php

/** A02: real Linux/MySQL/Redis authentication checks, including independent PHP process races. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// Never print exception SQL, command output, JWTs, quick codes or password material.
set_exception_handler(static function (Throwable $error): void {
    $message = $error instanceof AuthAcceptanceFailure
        ? $error->getMessage()
        : 'A02 bootstrap failed (' . get_class($error) . ')';
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
});
if (PHP_OS_FAMILY !== 'Linux' || !is_file('/app/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL: Run this CLI script in the installed acceptance Compose test container.\n");
    exit(1);
}

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Illuminate\Cache\RedisStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AuthAcceptanceFailure extends RuntimeException {}

function authRequire(bool $condition, string $message): void
{
    if (!$condition) throw new AuthAcceptanceFailure($message);
}

function authPass(string $message): void
{
    echo "PASS: $message\n";
}

function authRequest(string $class, array $params): FormRequest
{
    $request = $class::create('https://a02.example.test/', 'POST', $params);
    $request->setContainer(app());
    $request->setRedirector(new Redirector(new UrlGenerator(new RouteCollection(), Request::create('/'))));
    $request->validateResolved();
    return $request;
}

function authDenied(callable $action, int $status, string $message): void
{
    try {
        $action();
    } catch (HttpException $error) {
        authRequire($error->getStatusCode() === $status, $message . ' (unexpected HTTP status)');
        return;
    }
    throw new AuthAcceptanceFailure($message . ' (credentials were accepted)');
}

function authAdminUpdate(int $id, array $changes): void
{
    $user = User::useWritePdo()->findOrFail($id);
    $response = (new AdminUserController())->update(authRequest(AdminUserUpdate::class, array_replace([
        'id' => $id, 'email' => $user->email, 'banned' => (int)$user->banned,
        'is_admin' => (int)$user->is_admin, 'is_staff' => (int)$user->is_staff,
    ], $changes)));
    authRequire($response->getStatusCode() === 200, 'Administrator update failed');
}

function authConsume(string $code): array
{
    return AuthService::loginWithQuickLoginCode($code, Request::create('https://a02.example.test/'));
}

function authLogin(string $email, string $password): array
{
    $response = (new AuthController())->login(authRequest(AuthLogin::class, ['email' => $email, 'password' => $password]));
    $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'] ?? null;
    authRequire(is_array($data) && is_string($data['auth_data'] ?? null), 'Password login did not return a session');
    return $data;
}

// All settings are process-local. No .env, config files, real email delivery or external calls are changed.
config([
    'v2board.password_limit_enable' => 0,
    'v2board.login_captcha_enable' => 0,
    'v2board.app_url' => 'https://a02.example.test',
]);
authRequire(DB::connection()->getDriverName() === 'mysql', 'A02 requires actual MySQL');
authRequire(config('database.connections.' . config('database.default') . '.host') === 'mysql', 'A02 is restricted to the acceptance Compose MySQL host');
authRequire(config('database.redis.cache.host') === 'redis', 'A02 is restricted to the acceptance Compose Redis host');
authRequire(Cache::getStore() instanceof RedisStore, 'A02 requires the actual Redis cache store');
authRequire(Schema::hasColumn('v2_user', 'auth_version'), 'Install the A02 auth_version schema before running this script');

function authWait(callable $ready, string $message, float $seconds = 20): void
{
    $deadline = microtime(true) + $seconds;
    while (!$ready()) {
        if (microtime(true) >= $deadline) throw new AuthAcceptanceFailure($message);
        usleep(10000);
    }
}

// Workers bootstrap the actual app independently, with their own MySQL and Redis connections.
if (($argv[1] ?? '') === '--worker') {
    $run = $argv[2] ?? '';
    $task = $argv[3] ?? '';
    $index = $argv[4] ?? '';
    authRequire((bool)preg_match('/^[a-f0-9]{16}$/D', $run), 'Invalid worker run');
    authRequire(in_array($task, ['replay', 'stale-session', 'exchange-reset'], true), 'Invalid worker task');
    authRequire((bool)preg_match('/^[0-7]$/D', $index), 'Invalid worker index');
    $prefix = 'A02_ACCEPTANCE:' . $run;
    try {
        $context = Cache::get($prefix . ':context:' . $task);
        authRequire(is_array($context), 'Worker context missing');
        $service = null;
        if ($task === 'stale-session') {
            $user = User::useWritePdo()->findOrFail($context['id']);
            authRequire(Helper::multiPasswordVerify($user->password_algo, $user->password_salt, $context['password'], $user->password), 'Worker old password preparation failed');
            $service = new AuthService($user);
        }
        authRequire(Cache::put($prefix . ':ready:' . $task . ':' . $index, true, 120), 'Worker ready barrier failed');
        authWait(static fn () => Cache::get($prefix . ':go:' . $task) === true, 'Worker barrier timed out');
        authRequire(Cache::put($prefix . ':attempt:' . $task . ':' . $index, true, 120), 'Worker attempt marker failed');
        $data = $task === 'stale-session'
            ? $service->generateAuthData(Request::create('https://a02.example.test/'))
            : authConsume($context['code']);
        $authenticated = AuthService::decryptAuthData($data['auth_data'] ?? null);
        authRequire(is_array($authenticated) && (int)$authenticated['id'] === (int)$context['id'], 'Worker returned an invalid session');
        echo json_encode(['ok' => true, 'status' => 200], JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    } catch (HttpException $error) {
        echo json_encode(['ok' => false, 'status' => $error->getStatusCode()], JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, 'A02 worker failed (' . get_class($error) . ")\n");
        exit(1);
    }
}

$run = bin2hex(random_bytes(8));
$prefix = 'A02_ACCEPTANCE:' . $run;
$ownedUsers = [];
$ownedKeys = [];
$children = [];
$heldLocks = [];
$failed = false;

function authOwnKey(string $key): string
{
    global $ownedKeys;
    $ownedKeys[$key] = true;
    return $key;
}

function authOwnCode(string $code): string
{
    $key = authOwnKey(CacheKey::get('TEMP_TOKEN', $code));
    authOwnKey($key . ':lock');
    return $code;
}

function authSeed(int $admin = 0, int $staff = 0): User
{
    global $run, $ownedUsers;
    do {
        $id = random_int(900000000, 999999999);
    } while (DB::table('v2_user')->where('id', $id)->exists());
    $email = 'a02-' . $run . '-' . $id . '@example.test';
    DB::table('v2_user')->insert([
        'id' => $id, 'email' => $email, 'password' => password_hash('original-offline-password', PASSWORD_DEFAULT),
        'uuid' => Helper::guid(true), 'token' => Helper::guid(), 'is_admin' => $admin, 'is_staff' => $staff,
        'created_at' => time(), 'updated_at' => time(),
    ]);
    $ownedUsers[$id] = $email;
    authOwnKey(CacheKey::get('USER_SESSIONS', $id));
    authOwnKey(CacheKey::get('USER_SESSIONS', $id) . ':lock');
    return User::useWritePdo()->findOrFail($id);
}

function authCredentials(User $user): array
{
    $service = new AuthService($user);
    $jwt = $service->generateAuthData(Request::create('https://a02.example.test/'))['auth_data'];
    $code = authOwnCode($service->generateQuickLoginCode());
    return [$jwt, $code, $service->getSessions()];
}

function authStartWorker(string $task, int $index): void
{
    global $run, $prefix, $children;
    foreach (['ready', 'attempt'] as $marker) authOwnKey($prefix . ':' . $marker . ':' . $task . ':' . $index);
    $process = proc_open([PHP_BINARY, __FILE__, '--worker', $run, $task, (string)$index], [
        0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, '/app', null, ['bypass_shell' => true]);
    authRequire(is_resource($process), 'Unable to start an independent PHP worker');
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $children[] = ['process' => $process, 'pipes' => $pipes, 'closed' => false, 'exit' => null, 'out' => '', 'err' => ''];
}

function authBarrier(string $task, int $workers, array $context): void
{
    global $prefix;
    authRequire(Cache::put(authOwnKey($prefix . ':context:' . $task), $context, 120), 'Unable to persist worker context');
    authOwnKey($prefix . ':go:' . $task);
    for ($index = 0; $index < $workers; $index++) authStartWorker($task, $index);
    authWait(static function () use ($prefix, $task, $workers) {
        for ($index = 0; $index < $workers; $index++) {
            if (Cache::get($prefix . ':ready:' . $task . ':' . $index) !== true) return false;
        }
        return true;
    }, 'Independent workers did not all reach the Redis ready barrier');
}

function authReleaseBarrier(string $task): void
{
    global $prefix;
    authRequire(Cache::put($prefix . ':go:' . $task, true, 120), 'Unable to release the Redis worker barrier');
}

function authCollect(): array
{
    global $children;
    $results = [];
    $deadline = microtime(true) + 25;
    foreach ($children as &$child) {
        if ($child['closed']) continue;
        do {
            $child['out'] .= stream_get_contents($child['pipes'][1]);
            $child['err'] .= stream_get_contents($child['pipes'][2]);
            $state = proc_get_status($child['process']);
            if (!$state['running']) {
                $child['exit'] = $state['exitcode'];
                break;
            }
            authRequire(microtime(true) < $deadline, 'Independent PHP worker timed out');
            usleep(10000);
        } while (true);
        stream_set_blocking($child['pipes'][1], true);
        stream_set_blocking($child['pipes'][2], true);
        $child['out'] .= stream_get_contents($child['pipes'][1]);
        $child['err'] .= stream_get_contents($child['pipes'][2]);
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        $closedExit = proc_close($child['process']);
        $child['closed'] = true;
        authRequire(($child['exit'] ?? $closedExit) === 0 && $child['err'] === '', 'Independent PHP worker failed');
        $results[] = json_decode(trim($child['out']), true, 512, JSON_THROW_ON_ERROR);
    }
    unset($child);
    return $results;
}

try {
    authPass('Actual MySQL and Redis connections selected; all fixture IDs and cache keys are isolated');

    foreach (['admin', 'staff', 'user', 'forget', 'cli'] as $writer) {
        $user = authSeed();
        [$jwt, $code, $sessions] = authCredentials($user);
        $password = 'replacement-offline-password';
        if ($writer === 'admin') authAdminUpdate($user->id, ['password' => $password]);
        elseif ($writer === 'staff') {
            (new StaffUserController())->update(authRequest(StaffUserUpdate::class, [
                'id' => $user->id, 'email' => $user->email, 'banned' => 0, 'password' => $password,
            ]));
        } elseif ($writer === 'user') {
            (new UserController())->changePassword(authRequest(UserChangePassword::class, [
                'user' => ['id' => $user->id], 'old_password' => 'original-offline-password', 'new_password' => $password,
            ]));
        } elseif ($writer === 'forget') {
            $emailKey = authOwnKey(CacheKey::get('EMAIL_VERIFY_CODE', $user->email));
            authOwnKey(CacheKey::get('FORGET_REQUEST_LIMIT', $user->email));
            Cache::put($emailKey, '123456', 300);
            (new AuthController())->forget(authRequest(AuthForget::class, ['email' => $user->email, 'email_code' => '123456', 'password' => $password]));
        } else {
            $command = new ResetPassword();
            $command->setLaravel($app);
            $tester = new CommandTester($command);
            authRequire($tester->execute(['email' => $user->email]) === 0, 'CLI password reset failed');
            authRequire(preg_match('/新密码为：(.+)，请尽快修改密码。/u', $tester->getDisplay(), $matches) === 1, 'CLI password output could not be captured');
            $password = $matches[1];
        }
        $fresh = User::useWritePdo()->findOrFail($user->id);
        authRequire($fresh->auth_version !== '' && password_verify($password, $fresh->password), 'Password writer did not rotate and persist credentials');
        // Restore only this synthetic user's old map to prove revocation survives stale/failed cache cleanup.
        authRequire(Cache::forever(CacheKey::get('USER_SESSIONS', $user->id), $sessions), 'Unable to restore the stale test session map');
        authRequire(AuthService::decryptAuthData($jwt) === false, 'Old session survived a password reset');
        authDenied(static fn () => authConsume($code), 500, 'Old quick code survived a password reset');
        authDenied(static fn () => (new AuthService($user))->generateAuthData(Request::create('/')), 403, 'Stale user snapshot minted a new session');
        authDenied(static fn () => (new AuthService($user))->generateQuickLoginCode(), 403, 'Stale user snapshot minted a new quick code');
        $newAuth = authLogin($user->email, $password);
        authRequire((int)AuthService::decryptAuthData($newAuth['auth_data'])['id'] === $user->id, 'New password login failed');
        $freshCode = authOwnCode((new AuthService($fresh))->generateQuickLoginCode());
        authRequire((int)AuthService::decryptAuthData(authConsume($freshCode)['auth_data'])['id'] === $user->id, 'Fresh quick code login failed');
        authDenied(static fn () => authConsume($freshCode), 500, 'Fresh quick code was replayed');
        authPass("Real $writer password reset rejects old sessions and quick codes; new credentials work");
    }

    $legacyUser = authSeed();
    authRequire($legacyUser->auth_version === '', 'New SQL rows do not inherit the migration-compatible empty version');
    $legacySession = 'legacy-' . $run;
    Cache::forever(CacheKey::get('USER_SESSIONS', $legacyUser->id), [$legacySession => ['ip' => '192.0.2.1']]);
    $legacyJwt = JWT::encode(['id' => $legacyUser->id, 'session' => $legacySession], config('app.key'), 'HS256');
    authRequire((int)AuthService::decryptAuthData($legacyJwt)['id'] === $legacyUser->id, 'Existing unversioned session is incompatible with the empty SQL default');
    $legacyCode = authOwnCode(Helper::guid());
    Cache::put(CacheKey::get('TEMP_TOKEN', $legacyCode), $legacyUser->id, 60);
    authDenied(static fn () => authConsume($legacyCode), 500, 'Unversioned integer quick code was accepted');
    $legacyUser->password_salt = 'new-salt';
    $legacyUser->save();
    authRequire(AuthService::decryptAuthData($legacyJwt) === false, 'Legacy session survived a sensitive update');
    authPass('Legacy sessions work only at the empty version; old integer quick codes fail closed');

    foreach (['banned' => [1, 0], 'is_admin' => [0, 1], 'is_staff' => [0, 1]] as $field => [$changed, $restored]) {
        $user = authSeed(1, 1);
        [$jwt, $code, $sessions] = authCredentials($user);
        authAdminUpdate($user->id, [$field => $changed]);
        $changedVersion = User::useWritePdo()->findOrFail($user->id)->auth_version;
        authRequire(AuthService::decryptAuthData($jwt) === false, 'Account downgrade left an old session valid');
        authAdminUpdate($user->id, [$field => $restored]);
        authRequire(User::useWritePdo()->findOrFail($user->id)->auth_version !== $changedVersion, 'Restoration reused the revoked credential version');
        Cache::forever(CacheKey::get('USER_SESSIONS', $user->id), $sessions);
        authRequire(AuthService::decryptAuthData($jwt) === false, 'Restored account resurrected an old session');
        authDenied(static fn () => authConsume($code), 500, 'Restored account resurrected an old quick code');
        authPass("Real $field change and restoration cannot resurrect old credentials");
    }

    $user = authSeed();
    $bystander = authSeed();
    [$jwt, $code, $sessions] = authCredentials($user);
    (new AdminUserController())->ban(Request::create('/', 'POST', ['filter' => [['key' => 'id', 'condition' => '=', 'value' => $user->id]]]));
    $banned = User::useWritePdo()->findOrFail($user->id);
    authRequire((int)$banned->banned === 1 && $banned->auth_version !== '', 'Bulk ban did not persist a revoked version');
    authRequire((int)$bystander->fresh()->banned === 0 && $bystander->fresh()->auth_version === '', 'Bulk ban affected the unselected fixture');
    authAdminUpdate($user->id, ['banned' => 0]);
    Cache::forever(CacheKey::get('USER_SESSIONS', $user->id), $sessions);
    authRequire(AuthService::decryptAuthData($jwt) === false, 'Bulk ban restoration resurrected a session');
    authDenied(static fn () => authConsume($code), 500, 'Bulk ban restoration resurrected a quick code');
    authPass('Actual filtered MySQL bulk ban rotates the version and preserves an unselected user');

    $user = authSeed();
    $service = new AuthService($user);
    $code = authOwnCode($service->generateQuickLoginCode());
    authRequire($service->getSessions() === [], 'Replay fixture unexpectedly has an existing session');
    authBarrier('replay', 8, ['id' => $user->id, 'code' => $code]);
    authReleaseBarrier('replay');
    $results = authCollect();
    authRequire(count($results) === 8, 'Replay did not collect eight independent process results');
    authRequire(count(array_filter($results, static fn ($result) => ($result['ok'] ?? false) === true && ($result['status'] ?? null) === 200)) === 1, 'Concurrent replay did not produce exactly one successful login');
    authRequire(count(array_filter($results, static fn ($result) => ($result['ok'] ?? null) === false && ($result['status'] ?? null) === 500)) === 7, 'Concurrent replay did not reject all seven other exchanges');
    $sessions = $service->getSessions();
    authRequire(count($sessions) === 1, 'Concurrent replay created more than one session');
    authRequire((int)AuthService::decryptAuthData(reset($sessions)['auth_data'])['id'] === $user->id, 'The single replay winner session is invalid');
    authRequire(Cache::get(CacheKey::get('TEMP_TOKEN', $code)) === null, 'Concurrent replay left its quick code reusable');
    authPass('Eight independently bootstrapped PHP processes, released by a Redis barrier: one login, seven rejections, one session');

    $user = authSeed();
    $lock = Cache::lock(CacheKey::get('USER_SESSIONS', $user->id) . ':lock', 30);
    authRequire($lock->get(), 'Unable to hold the real Redis session lock');
    $heldLocks[] = $lock;
    authBarrier('stale-session', 1, ['id' => $user->id, 'password' => 'original-offline-password']);
    authReleaseBarrier('stale-session');
    authWait(static fn () => Cache::get($prefix . ':attempt:stale-session:0') === true, 'Stale password worker did not attempt session issuance');
    $fresh = User::useWritePdo()->findOrFail($user->id);
    $fresh->password = password_hash('concurrent-replacement-password', PASSWORD_DEFAULT);
    $fresh->save();
    $lock->release();
    $results = authCollect();
    authRequire(count($results) === 1 && $results[0] === ['ok' => false, 'status' => 403], 'Password verified before reset minted a session after reset');
    authRequire((new AuthService($fresh))->getSessions() === [], 'Stale password race persisted a session');
    authPass('A process holding a verified old-password snapshot cannot issue a session after a concurrent MySQL reset');

    $user = authSeed();
    $service = new AuthService($user);
    $code = authOwnCode($service->generateQuickLoginCode());
    $lock = Cache::lock(CacheKey::get('USER_SESSIONS', $user->id) . ':lock', 30);
    authRequire($lock->get(), 'Unable to hold the real Redis exchange session lock');
    $heldLocks[] = $lock;
    authBarrier('exchange-reset', 1, ['id' => $user->id, 'code' => $code]);
    authReleaseBarrier('exchange-reset');
    authWait(static fn () => Cache::get(CacheKey::get('TEMP_TOKEN', $code)) === null, 'Quick-code worker did not pass its initial version check and consume the code');
    $fresh = User::useWritePdo()->findOrFail($user->id);
    $fresh->password = password_hash('concurrent-code-reset-password', PASSWORD_DEFAULT);
    $fresh->save();
    $lock->release();
    $results = authCollect();
    authRequire(count($results) === 1 && $results[0] === ['ok' => false, 'status' => 403], 'Quick code checked before reset minted a session after reset');
    authRequire($service->getSessions() === [], 'Quick-code/reset race persisted a session');
    authPass('A quick exchange paused after its version check cannot issue a session after a concurrent MySQL reset; no database row locks used');
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, 'FAIL: ' . ($error instanceof AuthAcceptanceFailure ? $error->getMessage() : 'A02 integration failed (' . get_class($error) . ')') . "\n");
} finally {
    try {
        foreach ($children as $child) {
            if ($child['closed']) continue;
            proc_terminate($child['process']);
            foreach ($child['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($child['process']);
        }
        foreach ($heldLocks as $lock) $lock->release();
        foreach (array_keys($ownedKeys) as $key) Cache::forget($key);
        foreach ($ownedUsers as $id => $email) {
            // Only IDs successfully inserted by this invocation are eligible for cleanup.
            DB::table('v2_insider_guard_activity')->where('user_id', $id)->delete();
            DB::table('v2_user')->where('id', $id)->where('email', $email)->delete();
        }
        authPass('Only this invocation\'s synthetic users, activity rows and Redis keys were cleaned up');
    } catch (Throwable $error) {
        $failed = true;
        fwrite(STDERR, 'FAIL: A02 fixture cleanup failed (' . get_class($error) . ")\n");
    }
}

if ($failed) exit(1);
echo "ALL A02 MYSQL/REDIS MULTIPROCESS INTEGRATION CHECKS PASSED\n";
