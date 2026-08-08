<?php

declare(strict_types=1);

namespace Switch\Kernel\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Switch\Http\Response;
use Switch\Http\ServerRequest;
use Switch\Http\Stream;
use Switch\Kernel\App;

class DummyMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        return $response->withHeader('X-Custom-Middleware', 'Executed');
    }
}

class AppTest extends TestCase
{
    public function testAppMiddlewarePipelineExecution(): void
    {
        $app = new App();
        $app->use(new DummyMiddleware());
        $app->use(function (ServerRequestInterface $request, RequestHandlerInterface $handler) {
            return new Response(200, [], Stream::create('Pipeline OK'));
        });

        $request = new ServerRequest('GET', 'http://localhost/test');
        $response = $app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Pipeline OK', (string) $response->getBody());
        $this->assertEquals('Executed', $response->getHeaderLine('X-Custom-Middleware'));
    }

    public function testAppFallbackFourOhFour(): void
    {
        $app = new App();
        $request = new ServerRequest('GET', 'http://localhost/not-found');
        $response = $app->handle($request);

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertStringContainsString('404 Not Found', (string) $response->getBody());
    }
}
