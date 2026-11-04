<?php

// Only the isolated Compose network's Nginx peer is trusted, not its whole subnet.
$proxy = gethostbyname('nginx');
if (!filter_var($proxy, FILTER_VALIDATE_IP)) {
    fwrite(STDERR, "Unable to resolve the test Nginx proxy.\n");
    exit(1);
}
$_ENV['WEBMAN_TRUSTED_PROXIES'] = $_SERVER['WEBMAN_TRUSTED_PROXIES'] = '127.0.0.1,::1,' . $proxy;
putenv('WEBMAN_TRUSTED_PROXIES=' . $_ENV['WEBMAN_TRUSTED_PROXIES']);

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$status = Illuminate\Support\Facades\Artisan::call('config:cache');
echo Illuminate\Support\Facades\Artisan::output();
exit($status);
