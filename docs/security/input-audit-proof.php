<?php
/** Defensive regression evidence. Uses an in-memory database and dummy values only. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Http\Controllers\V1\Guest\CaptchaController;
use App\Http\Middleware\RequestLog;
use App\Logging\MysqlLoggerHandler;
use App\Services\LocalCaptchaService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade;
use Monolog\Logger;

if (PHP_SAPI !== 'cli') { exit(1); }
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "Run PHP with PDO SQLite enabled.\n");
    exit(1);
}
$app = new Container();
Container::setInstance($app);
Facade::clearResolvedInstances();
Facade::setFacadeApplication($app);
// The unqualified Cache facade in the existing captcha service uses this alias.
if (!class_exists('Cache', false)) class_alias(Illuminate\Support\Facades\Cache::class, 'Cache');
$app->instance('config', new Config());
$app->instance('cache', new Repository(new ArrayStore()));
$responses = Mockery::mock(ResponseFactory::class);
$responses->shouldReceive('make')->andReturnUsing(
    static fn ($content = '', $status = 200, array $headers = []) => new Response($content, $status, $headers)
);
$app->instance(ResponseFactory::class, $responses);
$db = new Manager($app);
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setAsGlobal();
$db->bootEloquent();
$db->schema()->create('v2_log', static function (Blueprint $table) {
    $table->increments('id');
    foreach (['title', 'level', 'host', 'uri', 'method', 'ip', 'data', 'context'] as $field) $table->text($field)->nullable();
    $table->integer('created_at');
    $table->integer('updated_at');
});
$logger = new Logger('offline-audit', [new MysqlLoggerHandler()]);
$app->instance('log', $logger);
$request = Request::create('https://audit.example.test/api/v1/dummy-admin/config/save?auth_data=dummy-jwt', 'POST', [
    'email_password' => 'dummy-mail-password',
    'server_token' => 'dummy-node-token',
    'password' => 'dummy-password',
]);
$app->instance('request', $request);
(new RequestLog())->handle($request, static fn () => new Response('ok'));
$row = $db->table('v2_log')->first();
$stored = json_decode($row->data, true);
$results = [
    'default_request_log_stores_password' => ($stored['password'] ?? null) === 'dummy-password',
    'default_request_log_stores_mail_secret' => ($stored['email_password'] ?? null) === 'dummy-mail-password',
    'default_request_log_stores_node_secret' => ($stored['server_token'] ?? null) === 'dummy-node-token',
    'default_request_log_uri_stores_query_credential' => str_contains($row->uri, 'dummy-jwt'),
];
// Exercise the SVG fallback as returned by the real controller when GD is absent.
$captcha = Request::create('https://audit.example.test/api/v1/guest/captcha?key=offline-proof');
$app->instance('request', $captcha);
if (!function_exists('imagecreatetruecolor')) {
    $response = (new CaptchaController())->image($captcha);
    preg_match_all('~<text\b[^>]*>([^<]*)</text>~', $response->getContent(), $chars);
    $answer = html_entity_decode(implode('', $chars[1]));
    $results['svg_fallback_answer_readable_without_image_recognition'] = strlen($answer) === 5;
    $unrelatedCacheRequest = Request::create('/api/v1/user/comm/config', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'captcha_offline-proof']);
    $results['unrelated_cache_key_passes_user_middleware'] = (new App\Http\Middleware\User())->handle($unrelatedCacheRequest, static fn () => true) === true;
    $results['svg_fallback_text_passes_real_verifier'] = LocalCaptchaService::verify($captcha, $answer, 'offline-proof');
    $results['captcha_success_consumes_key'] = !LocalCaptchaService::verify($captcha, $answer, 'offline-proof');
} else {
    $results['svg_fallback_check'] = 'skipped: GD enabled';
}
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
Mockery::close();
foreach ($results as $result) if ($result === false) exit(1);
