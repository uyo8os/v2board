<?php

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . PHP_EOL);
    exit(1);
});

use App\Services\InsiderGuardService;
use Illuminate\Support\Facades\DB;

$clientIp = gethostbyname(gethostname());
if (!filter_var($clientIp, FILTER_VALIDATE_IP)) throw new RuntimeException('Unable to identify the test client.');

foreach (['PHP-FPM' => 'http://nginx', 'Webman' => 'http://nginx:81'] as $name => $base) {
    $ua = 'Proxy-IP-Check/' . $name;
    foreach ([InsiderGuardService::EVENT_LOGIN, InsiderGuardService::EVENT_SUBSCRIPTION] as $event) {
        $query = DB::table('v2_insider_guard_activity')->where('user_id', 2)
            ->where('event_type', $event)->where('ua_hash', md5($ua))->where('ip_address', $clientIp);
        $before = (int)(clone $query)->sum('times');
        $login = $event === InsiderGuardService::EVENT_LOGIN;
        $path = $login ? '/api/v1/passport/auth/login' : '/api/v1/client/subscribe?token=acceptance-user-2-token&flag=clash';
        $curl = curl_init($base . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json', 'User-Agent: ' . $ua,
                'X-Forwarded-For: 198.51.100.66', 'CF-Connecting-IP: 198.51.100.77', 'X-Real-IP: 198.51.100.88',
            ],
        ]);
        if ($login) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query([
            'email' => 'user2@example.test', 'password' => 'acceptance-user-password',
        ])]);
        $response = curl_exec($curl);
        if ($response === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
            throw new RuntimeException("$name $event request failed.");
        }
        if ((int)(clone $query)->sum('times') !== $before + 1) {
            throw new RuntimeException("$name risk-center $event IP does not match the actual client $clientIp.");
        }
        echo "PASS: $name risk-center $event records the actual client IP despite forged headers\n";
    }
}
echo "ALL RISK-CENTER IP CHECKS PASSED\n";
