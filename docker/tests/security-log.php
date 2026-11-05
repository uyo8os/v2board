<?php

/** A11 integration: actual MySQL and Laravel logger; a connection-local temporary table only. */
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Middleware\RequestLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string) $error . PHP_EOL);
    exit(1);
});

function checkLogSecurity(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

function noLogSecrets(string $value, array $secrets): bool
{
    foreach ($secrets as $secret) if (str_contains($value, $secret)) return false;
    return true;
}

$db = DB::connection();
checkLogSecurity($db->getDriverName() === 'mysql', 'Real MySQL connection selected');
$schema = file_get_contents('/app/database/install.sql');
checkLogSecurity((bool) preg_match('/CREATE TABLE `v2_log`\s*\(.*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s', $schema, $matches), 'Read the v2_log schema from install.sql');
$db->unprepared(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $matches[0], 1));
// MySQL resolves this temporary table before a permanent v2_log, and destroys it on disconnect.
checkLogSecurity((int) $db->table('v2_log')->count() === 0, 'Fresh connection-local MySQL log table created');

$secrets = ['a11-path-secret', 'a11-jwt-secret', 'a11-mail-password', 'a11-node-secret', 'a11-payment-secret', 'a11-captcha-secret', 'a11-exception-secret', 'a11-context-secret'];
$request = Request::create('https://audit.example.test/admin/a11-path-secret?auth_data=a11-jwt-secret', 'POST', [
    'email_password' => 'a11-mail-password', 'server_token' => 'a11-node-secret',
    'config' => ['payment' => ['secret' => 'a11-payment-secret']], 'captcha' => 'a11-captcha-secret',
    'user' => ['id' => 7], 'id' => 42,
]);
$action = 'App\\Http\\Controllers\\V1\\Admin\\ConfigController@save';
$request->setRouteResolver(static fn () => new Route('POST', '/admin/config/save', ['uses' => $action, 'controller' => $action]));
$app->instance('request', $request);
$response = (new RequestLog())->handle($request, static fn () => new Response('invalid', 422));
checkLogSecurity($response->getStatusCode() === 422, 'Admin request logs before a failed validation response');
$row = $db->table('v2_log')->where('title', 'Admin POST request')->first();
checkLogSecurity($row !== null && $row->method === 'POST' && $row->uri === $action, 'MySQL retains the event, method and trusted action');
$context = json_decode($row->context, true, 512, JSON_THROW_ON_ERROR);
checkLogSecurity(($context['actor_id'] ?? null) === 7 && ($context['target_id'] ?? null) === 42, 'MySQL retains actor and target IDs');
checkLogSecurity(noLogSecrets(json_encode($row, JSON_THROW_ON_ERROR), $secrets), 'MySQL request row excludes all credential sentinels');

$error = new RuntimeException('a11-exception-secret');
Log::error('a11-context-secret', ['exception' => $error, 'nested' => ['password' => 'a11-mail-password']]);
$row = $db->table('v2_log')->where('level', 'ERROR')->orderByDesc('id')->first();
checkLogSecurity($row !== null, 'Exception context produces a real MySQL error record');
$context = json_decode($row->context, true, 512, JSON_THROW_ON_ERROR);
checkLogSecurity(($context['exception_type'] ?? null) === RuntimeException::class && !empty($context['exception_file']) && !empty($context['exception_line']), 'MySQL retains safe exception metadata');
checkLogSecurity(noLogSecrets(json_encode($row, JSON_THROW_ON_ERROR), $secrets), 'MySQL error row excludes exception and context secrets');

$file = sys_get_temp_dir() . '/v2board-a11-' . bin2hex(random_bytes(8)) . '.log';
$app['config']->set('logging.channels.single.path', $file);
try {
    Log::channel('single')->error('a11-context-secret', ['exception' => $error]);
    $output = file_get_contents($file);
    checkLogSecurity(is_string($output) && str_contains($output, RuntimeException::class), 'Actual file log retains safe exception type');
    checkLogSecurity(noLogSecrets($output, $secrets), 'Actual file log excludes exception and context secrets');
} finally {
    foreach (Log::channel('single')->getHandlers() as $handler) $handler->close();
    if (is_file($file)) unlink($file);
}
echo "ALL A11 MYSQL INTEGRATION CHECKS PASSED\n";
