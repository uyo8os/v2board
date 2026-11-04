<?php

namespace Tests\Unit;

use App\Console\Commands\RouteListCommand;
use App\Http\Controllers\Controller;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as VendorController;
use Illuminate\Routing\Router;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RouteListCommandTest extends TestCase
{
    private $previousContainer;
    private Application $app;
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->router = new Router(new Dispatcher($this->app), $this->app);
        $this->app->instance('router', $this->router);
        RouteListGuardedController::$constructions = 0;
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function testListsGuardedRoutesWithoutRunningHttpValidation(): void
    {
        $this->router->aliasMiddleware('auth', 'Example\\Authentication');
        $this->router->middlewareGroup('api', ['auth:token', 'throttle:60']);
        $this->router->post('guarded', [RouteListGuardedController::class, 'handle'])
            ->middleware('api')->withoutMiddleware('throttle:60');

        $routes = $this->listRoutes();

        $this->assertCount(1, $routes);
        $this->assertSame('guarded', $routes[0]['uri']);
        $this->assertSame(['Example\\Authentication:token'], $routes[0]['middleware']);
        $this->assertSame(0, RouteListGuardedController::$constructions);
    }

    public function testHttpDispatchStillRunsTheOriginalValidationAfterListing(): void
    {
        $this->router->post('guarded', [RouteListGuardedController::class, 'handle']);
        $this->listRoutes();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Webhook token rejected');
        try {
            $this->router->dispatch(Request::create('/guarded', 'POST'));
        } finally {
            $this->assertSame(1, RouteListGuardedController::$constructions);
        }
    }

    public function testPreservesVendorControllerMiddlewareInTheListing(): void
    {
        $this->router->get('vendor', [RouteListVendorController::class, 'handle']);

        $routes = $this->listRoutes();

        $this->assertSame(['vendor-auth'], $routes[0]['middleware']);
    }

    private function listRoutes(): array
    {
        $command = new RouteListCommand($this->router);
        $command->setLaravel($this->app);
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);
        $status = $command->run(new ArrayInput(['--json' => true]), $output);
        $this->assertSame(0, $status);

        return json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);
    }
}

class RouteListGuardedController extends Controller
{
    public static int $constructions = 0;

    public function __construct()
    {
        self::$constructions++;
        throw new HttpException(401, 'Webhook token rejected');
    }

    public function handle(): void
    {
    }
}

class RouteListVendorController extends VendorController
{
    public function __construct()
    {
        $this->middleware('vendor-auth');
    }

    public function handle(): void
    {
    }
}
