<?php

namespace Tests\Unit;

use App\Exceptions\Handler;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\View\ViewException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExceptionHandlerCompatibilityTest extends TestCase
{
    private $previousContainer;
    private Handler $handler;
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository(['app' => ['debug' => false]]));
        $app->instance('translator', new Translator(new ArrayLoader(), 'en'));
        $app->instance(ResponseFactoryContract::class, new ResponseFactory(
            $this->createStub(ViewFactory::class),
            $this->createStub(Redirector::class)
        ));
        $this->handler = new Handler($app);
        $this->request = Request::create('/api/v1/test', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function testLaravelViewExceptionsKeepTheThemeConfigurationHint(): void
    {
        $response = $this->handler->render($this->request, new ViewException('Undefined theme setting'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([
            'message' => '主题渲染失败。如更新主题，参数可能发生变化请重新配置主题后再试。',
        ], $response->getData(true));
    }

    public function testOtherHttpExceptionsKeepTheirStatusAndMessage(): void
    {
        $response = $this->handler->render($this->request, new HttpException(401, 'Token rejected'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['message' => 'Token rejected'], $response->getData(true));
    }

    public function testOtherApplicationErrorsDoNotExposePrivateDetailsInProduction(): void
    {
        $response = $this->handler->render($this->request, new RuntimeException('Private database credentials'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([
            'message' => "Uh-oh, we've had some problems, we're working on it.",
        ], $response->getData(true));
    }
}
