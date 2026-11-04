<?php

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Standalone acceptance scripts must return a failing status for every exception.
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, (string)$exception . PHP_EOL);
    exit(1);
});

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

function verify($condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

function http(string $base, string $path, string $method = 'GET', array $data = [], ?string $auth = null, int $expected = 200): string
{
    $handle = curl_init($base . $path);
    $headers = ['Accept: application/json', 'User-Agent: V2Board-Acceptance/1.0'];
    if ($auth) $headers[] = 'Authorization: ' . $auth;
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'POST') curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    $body = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if ($body === false || $status !== $expected) {
        throw new RuntimeException("$base$path returned HTTP $status, expected $expected: " . substr((string)$body, 0, 400));
    }
    return $body;
}

$routeStatus = Artisan::call('route:list', ['--json' => true]);
$routes = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
verify($routeStatus === 0 && count($routes) > 100, 'route:list enumerates the application without HTTP request tokens');
verify(in_array('api/v1/guest/telegram/webhook', array_column($routes, 'uri'), true), 'route:list includes the Telegram webhook');
verify(count(array_filter($routes, static fn ($route) => in_array(
    Laravel\Horizon\Http\Middleware\Authenticate::class, $route['middleware'], true
))) > 0, 'route:list preserves Horizon controller authentication metadata');

foreach (['PHP-FPM' => 'http://nginx', 'Workerman' => 'http://nginx:81'] as $name => $base) {
    verify(str_contains(http($base, '/'), '<!DOCTYPE html>'), "$name renders the original user interface");
    verify(isset(json_decode(http($base, '/api/v1/guest/comm/config'), true)['data']), "$name returns guest configuration");
    http($base, '/api/v1/guest/telegram/webhook', 'POST', ['access_token' => 'acceptance-invalid-token'], expected: 401);
    verify(true, "$name still rejects invalid Telegram webhook tokens");
    http($base, '/api/v1/user/info', expected: 403);
    $tokens = [];
    foreach ([2, 3] as $id) {
        $login = json_decode(http($base, '/api/v1/passport/auth/login', 'POST', [
            'email' => "user$id@example.test", 'password' => 'acceptance-user-password',
        ]), true);
        $tokens[$id] = $login['data']['auth_data'] ?? null;
        verify(is_string($tokens[$id]), "$name password login issues the original JWT for user $id");
    }
    for ($i = 0; $i < 8; $i++) {
        $id = $i % 2 + 2;
        $info = json_decode(http($base, '/api/v1/user/info', auth: $tokens[$id]), true);
        verify(($info['data']['email'] ?? '') === "user$id@example.test", "$name isolates consecutive requests ($i)");
    }
    http($base, '/api/v1/user/info', expected: 403);
    verify(count(json_decode(http($base, '/api/v1/user/plan/fetch', auth: $tokens[2]), true)['data']) === 1, "$name serves the existing plan");
    verify(count(json_decode(http($base, '/api/v1/user/order/fetch', auth: $tokens[2]), true)['data']) >= 1, "$name serves existing orders");

    foreach (['clash', 'sing-box', 'surge', 'v2rayn'] as $format) {
        $subscription = http($base, '/api/v1/client/subscribe?token=acceptance-user-2-token&flag=' . $format);
        $decoded = base64_decode($subscription, true);
        verify(str_contains($subscription, 'node.example.test') || ($decoded !== false && str_contains($decoded, 'node.example.test')), "$name generates $format subscriptions");
    }
    $restricted = http($base, '/api/v1/client/subscribe?token=acceptance-user-3-token&flag=clash');
    verify(!str_contains($restricted, 'node.example.test'), "$name preserves InsiderGuard restrictions");
    verify(str_contains(http($base, '/api/v1/client/app/getConfig?token=acceptance-user-2-token'), 'node.example.test'), "$name generates App configuration");
    http($base, '/api/v1/client/subscribe?token=invalid-token', expected: 403);
    $captcha = http($base, '/api/v1/guest/captcha?key=acceptance-' . $name);
    verify(str_starts_with($captcha, "\x89PNG"), "$name renders PNG captcha with GD");
    $users = json_decode(http($base, '/api/v1/server/UniProxy/user?token=acceptance-server-token&node_type=shadowsocks&node_id=1'), true);
    verify(count($users['users'] ?? []) === 2, "$name serves node backend user lists");
}

// A real order travels through the original balance-payment and queue workflow.
$base = 'http://nginx';
$login = json_decode(http($base, '/api/v1/passport/auth/login', 'POST', ['email' => 'user2@example.test', 'password' => 'acceptance-user-password']), true);
$token = $login['data']['auth_data'];
$balance = DB::table('v2_user')->where('id', 2)->value('balance');
$trade = json_decode(http($base, '/api/v1/user/order/save', 'POST', ['plan_id' => 1, 'period' => 'month_price'], $token), true)['data'];
verify(DB::table('v2_user')->where('id', 2)->value('balance') === $balance - 1000, 'Order creation deducts the original balance amount');
http($base, '/api/v1/user/order/checkout', 'POST', ['trade_no' => $trade], $token);
for ($i = 0; $i < 20 && DB::table('v2_order')->where('trade_no', $trade)->value('status') !== 3; $i++) usleep(500000);
verify(DB::table('v2_order')->where('trade_no', $trade)->value('status') === 3, 'Horizon completes the paid renewal order');

App\Jobs\TrafficFetchJob::dispatch([2 => [123, 456]], ['rate' => 1], 'shadowsocks');
for ($i = 0; $i < 20 && (int)Redis::hget('v2board_upload_traffic', 2) !== 123; $i++) usleep(500000);
verify((int)Redis::hget('v2board_upload_traffic', 2) === 123 && (int)Redis::hget('v2board_download_traffic', 2) === 456, 'Horizon consumes traffic jobs with Redis 7.4');

App\Jobs\SendEmailJob::dispatch([
    'email' => 'queue@example.test', 'subject' => 'Acceptance queue test', 'template_name' => 'verify',
    'template_value' => ['name' => 'Acceptance', 'code' => '123456', 'url' => 'http://localhost'],
]);
for ($i = 0; $i < 30 && !DB::table('v2_mail_log')->where('email', 'queue@example.test')->exists(); $i++) usleep(500000);
verify(DB::table('v2_mail_log')->where('email', 'queue@example.test')->whereNull('error')->exists(), 'Horizon renders and delivers queued mail through the test log transport');
verify(DB::table('v2_insider_guard_subscribe_audit')->where('source', 'subscription')->exists(), 'Horizon writes subscription audits to MySQL');
verify(DB::table('v2_insider_guard_subscribe_audit')->where('source', 'app')->exists(), 'Horizon writes App audits to MySQL');
verify(DB::table('failed_jobs')->count() === 0, 'No queue jobs failed');

Illuminate\Support\Facades\Log::error('acceptance-mysql-logger');
verify(DB::table('v2_log')->where('title', 'acceptance-mysql-logger')->exists(), 'Monolog 3 writes application errors to the original MySQL log');
echo "ALL RUNTIME ACCEPTANCE CHECKS PASSED\n";
