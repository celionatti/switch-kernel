<?php

declare(strict_types=1);

namespace Switch\Kernel\Tests;

use PHPUnit\Framework\TestCase;
use Switch\Kernel\App;
use Switch\Kernel\Config\MiddlewareCollector;
use Switch\Kernel\Config\ExceptionsCollector;

class AppBuilderTest extends TestCase
{
    public function testMiddlewareCollectorAppendsAndPrepends(): void
    {
        $collector = new MiddlewareCollector();
        $collector->append('MiddlewareA', 'MiddlewareB');
        $collector->prepend('MiddlewareFirst');

        $this->assertEquals(['MiddlewareFirst', 'MiddlewareA', 'MiddlewareB'], $collector->getGlobalMiddleware());
    }

    public function testExceptionsCollectorRegistersReporter(): void
    {
        $reported = false;
        $collector = new ExceptionsCollector();
        $collector->dontReport([\InvalidArgumentException::class]);

        $this->assertEquals([\InvalidArgumentException::class], $collector->getDontReport());
    }

    public function testMiddlewareCollectorGroups(): void
    {
        $collector = new MiddlewareCollector();
        $collector->web('WebMiddleware1', 'WebMiddleware2');
        $collector->api('ApiThrottleMiddleware');

        $groups = $collector->getGroups();
        $this->assertEquals(['WebMiddleware1', 'WebMiddleware2'], $groups['web']);
        $this->assertEquals(['ApiThrottleMiddleware'], $groups['api']);
    }

    public function testAppConfigureCreatesAppInstance(): void
    {
        $app = App::configure(__DIR__ . '/../')
            ->withMiddleware(function (MiddlewareCollector $middleware) {
                $middleware->append(fn($request, $handler) => $handler->handle($request));
                $middleware->web('SessionMiddleware');
                $middleware->api('ThrottleMiddleware');
            })
            ->withExceptions(function (ExceptionsCollector $exceptions) {
                $exceptions->dontReport([\RuntimeException::class]);
            })
            ->create();

        $this->assertInstanceOf(App::class, $app);
        $this->assertInstanceOf(\Psr\Container\ContainerInterface::class, $app->getContainer());
    }
}
