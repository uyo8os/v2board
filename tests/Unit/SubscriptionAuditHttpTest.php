<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\SubscriptionAuditController;
use App\Http\Middleware\SubscriptionAudit;
use App\Http\Requests\Admin\SubscriptionAuditDetail;
use App\Http\Requests\Admin\SubscriptionAuditFetch;
use App\Http\Routes\V1\ClientRoute;
use App\Services\SubscriptionAuditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SubscriptionAuditTestCase;

class SubscriptionAuditHttpTest extends SubscriptionAuditTestCase
{
    private $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new Factory(new Translator(new ArrayLoader(), 'en'), $this->container);
        $this->database->schema()->create('v2_user', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('email');
            $table->string('token');
        });
        $this->database->table('v2_user')->insert([
            ['id' => 7, 'email' => 'current7@example.test', 'token' => 'current-secret'],
            ['id' => 8, 'email' => 'current8@example.test', 'token' => 'other-current-secret'],
        ]);
    }

    public function testPaginationDoesNotLimitStatisticsAndFreshBypassesOnlyTheStatsCache(): void
    {
        for ($i = 0; $i < 13; $i++) $this->insert();
        $controller = new SubscriptionAuditController();
        $list = $this->data($controller->fetch($this->auditRequest(['pageSize' => 2, 'current' => 2])));
        $this->assertCount(2, $list['data']);
        $this->assertSame(13, $list['total']);
        $this->assertArrayNotHasKey('request_details', $list['data'][0]);
        $this->assertArrayNotHasKey('event_id', $list['data'][0]);
        $stats = $this->data($controller->stats($this->auditRequest(['pageSize' => 2, 'current' => 2])))['data'];
        $this->assertSame(13, $stats['total']);
        $this->assertSame(13, $stats['ranking'][0]['count']);
        $this->insert();
        $cached = $this->data($controller->stats($this->auditRequest()))['data'];
        $this->assertSame(13, $cached['total']);
        $fresh = $this->data($controller->stats($this->auditRequest(['fresh' => 1])))['data'];
        $this->assertSame(14, $fresh['total']);
        $this->assertSame(14, $this->data($controller->stats($this->auditRequest()))['data']['total']);
    }

    public function testFiltersMatchLiteralTextAndNormalizedIpWithInclusiveTimes(): void
    {
        $now = time();
        $matching = [
            'email' => 'name%_!@example.test', 'ua' => 'Agent%_!', 'ip_address' => '2001:db8::1',
            'source' => 'app', 'format' => 'Clash', 'result' => 'error', 'status' => 503, 'requested_at' => $now,
        ];
        $id = $this->insert($matching);
        $this->insert(array_merge($matching, ['ua' => 'AgentXY!']));
        $this->insert(array_merge($matching, ['ua' => 'agent%_!']));
        $this->insert(array_merge($matching, ['email' => 'nameAB!@example.test']));
        $this->insert(array_merge($matching, ['format' => 'ClashMeta']));
        $filters = [
            'email' => '%_!', 'ua' => 'Agent%_!', 'ip' => '2001:0db8:0:0:0:0:0:1',
            'source' => 'app', 'format' => 'Clash', 'result' => 'error', 'status' => 503,
            'user_id' => 7, 'time_from' => $now, 'time_to' => $now,
        ];
        $controller = new SubscriptionAuditController();
        $list = $this->data($controller->fetch($this->auditRequest($filters)));
        $this->assertSame(1, $list['total']);
        $this->assertSame($id, $list['data'][0]['id']);
        $stats = $this->data($controller->stats($this->auditRequest($filters)))['data'];
        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['uas']);
    }

    public function testStatisticsCacheDoesNotShareDataAcrossApplicationIdentities(): void
    {
        $controller = new SubscriptionAuditController();
        $this->insert();
        $this->assertSame(1, $this->data($controller->stats($this->auditRequest()))['data']['total']);
        $this->insert();
        $this->assertSame(1, $this->data($controller->stats($this->auditRequest()))['data']['total']);
        config(['app.key' => 'different-site-key']);
        $this->assertSame(2, $this->data($controller->stats($this->auditRequest()))['data']['total']);
    }

    public function testUaRankingIsCaseSensitiveAndUsersAreGroupedById(): void
    {
        $this->insert(['ua' => 'Client', 'user_id' => 7, 'email' => 'same-snapshot@example.test']);
        $this->insert(['ua' => 'client', 'user_id' => 8, 'email' => 'same-snapshot@example.test']);
        $controller = new SubscriptionAuditController();
        $ua = $this->data($controller->stats($this->auditRequest(['dimension' => 'ua'])))['data'];
        $this->assertSame(2, $ua['uas']);
        $this->assertSame(['Client', 'client'], array_column($ua['ranking'], 'value'));
        $users = $this->data($controller->stats($this->auditRequest(['dimension' => 'user'])))['data'];
        $this->assertSame(2, $users['users']);
        $this->assertSame([7, 8], array_column($users['ranking'], 'value'));
        $this->assertSame(['current7@example.test', 'current8@example.test'], array_column($users['ranking'], 'label'));
    }

    public function testDetailReturnsCurrentLinkWithoutPersistingIt(): void
    {
        config(['v2board.show_subscribe_method' => 0, 'v2board.subscribe_url' => 'https://current.example.test']);
        $id = $this->insert(['request_details' => '{"method":"GET","path":"/safe","query":{"flag":"meta"}}']);
        $request = $this->validatedRequest(SubscriptionAuditDetail::class, ['id' => $id]);
        $row = $this->data((new SubscriptionAuditController())->detail($request))['data'];
        $this->assertSame('meta', $row['request_details']['query']['flag']);
        $this->assertSame('https://current.example.test/api/v1/client/subscribe?token=current-secret', $row['current_subscribe_url']);
        $this->assertArrayNotHasKey('event_id', $row);
        $stored = $this->database->table(SubscriptionAuditService::TABLE)->where('id', $id)->first();
        $this->assertStringNotContainsString('current-secret', json_encode($stored));
    }

    /** @dataProvider missingDetailCases */
    public function testMissingOrMalformedDetailsReturnNullWithTheCurrentLink($details): void
    {
        config(['v2board.show_subscribe_method' => 0, 'v2board.subscribe_url' => 'https://current.example.test']);
        $id = $this->insert(['request_details' => $details]);
        $row = $this->data((new SubscriptionAuditController())->detail(
            $this->validatedRequest(SubscriptionAuditDetail::class, ['id' => $id])
        ))['data'];

        $this->assertNull($row['request_details']);
        $this->assertSame('https://current.example.test/api/v1/client/subscribe?token=current-secret', $row['current_subscribe_url']);
    }

    public static function missingDetailCases(): array
    {
        return [['request_details' => null], ['request_details' => 'invalid-json'], ['request_details' => '{}']];
    }

    public function testValidDetailsNormalizeAnEmptyQueryToAnObject(): void
    {
        config(['v2board.show_subscribe_method' => 0, 'v2board.subscribe_url' => 'https://current.example.test']);
        $id = $this->insert(['request_details' => '{"method":"GET","path":"/safe","query":[]}']);
        $response = (new SubscriptionAuditController())->detail(
            $this->validatedRequest(SubscriptionAuditDetail::class, ['id' => $id])
        );
        $details = json_decode($response->getContent())->data->request_details;

        $this->assertSame('GET', $details->method);
        $this->assertSame('/safe', $details->path);
        $this->assertInstanceOf(\stdClass::class, $details->query);
    }

    /** @dataProvider invalidFilters */
    public function testInvalidFiltersAreRejectedBeforeQueries(array $filters): void
    {
        $this->expectException(ValidationException::class);
        $this->auditRequest($filters);
    }

    public static function invalidFilters(): array
    {
        return [
            'oversized page' => [['pageSize' => 101]],
            'array user id' => [['user_id' => [7]]],
            'reversed interval' => [['time_from' => 20, 'time_to' => 10]],
            'invalid ip' => [['ip' => 'not-an-ip']],
            'invalid status' => [['status' => 99]],
            'unknown source' => [['source' => 'login']],
            'unknown result' => [['result' => 'blocked']],
            'unknown dimension' => [['dimension' => 'email']],
        ];
    }

    public function testMiddlewareRecordsHttp200UnavailableAndRedactsCredentials(): void
    {
        $request = $this->clientRequest();
        $original = response('unavailable body', 200);
        $response = (new SubscriptionAudit())->handle($request, function ($request) use ($original) {
            $request->attributes->set('subscription_audit_format', 'Clash');
            $request->attributes->set('subscription_audit_result', 'unavailable');
            $request->attributes->set('subscription_audit_decoy', true);
            $request->attributes->set('subscription_audit_node_count', 0);
            return $original;
        }, 'app');
        $this->assertSame($original, $response);
        $this->assertCount(1, $this->jobs);
        $event = $this->jobs[0]->event;
        $this->assertSame('app', $event['source']);
        $this->assertSame('unavailable', $event['result']);
        $this->assertSame(200, $event['status']);
        $this->assertTrue(json_decode($event['request_details'], true)['decoy']);
        foreach (['temporary-secret', 'persistent-secret', 'user-secret-uuid', 'authorization-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($event));
        }
    }

    public function testMiddlewareRecordsErrorStatusAndRethrowsTheOriginalException(): void
    {
        $failure = new HttpException(503, 'Do not persist this exception message');
        try {
            (new SubscriptionAudit())->handle($this->clientRequest(), function () use ($failure) {
                throw $failure;
            });
            $this->fail('Expected the original subscription exception');
        } catch (HttpException $caught) {
            $this->assertSame($failure, $caught);
        }
        $this->assertCount(1, $this->jobs);
        $event = $this->jobs[0]->event;
        $this->assertSame('error', $event['result']);
        $this->assertSame(503, $event['status']);
        $this->assertStringNotContainsString($failure->getMessage(), json_encode($event));
    }

    public function testDisabledMiddlewareCallsTheBusinessHandlerExactlyOnce(): void
    {
        config(['v2board.insider_guard_subscribe_audit_enabled' => 0]);
        $calls = 0;
        try {
            (new SubscriptionAudit())->handle($this->clientRequest(), function () use (&$calls) {
                $calls++;
                throw new RuntimeException('Business failure');
            });
        } catch (RuntimeException $expected) {
            $this->assertSame('Business failure', $expected->getMessage());
        }
        $this->assertSame(1, $calls);
        $this->assertCount(0, $this->jobs);
    }

    public function testOnlyConfigurationRoutesHaveAuditAfterClientAuthentication(): void
    {
        config(['v2board.subscribe_path' => '']);
        $router = new Router(new Dispatcher($this->container), $this->container);
        $router->group(['namespace' => 'App\\Http\\Controllers'], function ($router) {
            (new ClientRoute())->map($router);
        });
        $middlewares = [];
        foreach ($router->getRoutes() as $route) $middlewares[$route->uri()] = $route->gatherMiddleware();
        $this->assertSame(['client', 'subscription.audit:subscription'], $middlewares['client/subscribe']);
        $this->assertSame(['client', 'subscription.audit:app'], $middlewares['client/app/getConfig']);
        $this->assertSame(['client'], $middlewares['client/app/getVersion']);
    }

    private function auditRequest(array $filters = []): SubscriptionAuditFetch
    {
        return $this->validatedRequest(SubscriptionAuditFetch::class, $filters);
    }

    private function validatedRequest(string $class, array $values)
    {
        $request = $class::create('/audit', 'GET', $values);
        $request->setContainer($this->container);
        $validator = $this->validator->make($request->all(), $request->rules());
        if (method_exists($request, 'withValidator')) $request->withValidator($validator);
        $validator->validate();
        $request->setValidator($validator);
        return $request;
    }

    private function insert(array $values = []): int
    {
        return $this->database->table(SubscriptionAuditService::TABLE)->insertGetId($this->event($values));
    }

    private function data($response): array
    {
        return json_decode($response->getContent(), true);
    }

    private function clientRequest(): Request
    {
        $request = Request::create('/safe-subscription?token=temporary-secret&flag=meta', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => 'Client persistent-secret user-secret-uuid',
            'HTTP_AUTHORIZATION' => 'authorization-secret',
        ]);
        $request->merge(['user' => (object)[
            'id' => 7, 'email' => 'audit@example.test', 'token' => 'persistent-secret', 'uuid' => 'user-secret-uuid',
        ]]);
        return $request;
    }
}
