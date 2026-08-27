<?php

declare(strict_types=1);

namespace Switch\Kernel\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Switch\Http\Response;
use Switch\Http\ServerRequest;
use Switch\Http\Stream;
use Switch\Kernel\Middleware\SecurityHeadersMiddleware;

class SecurityHeadersTest extends TestCase
{
    public function testDefaultSecurityHeadersInjected(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = new ServerRequest('GET', '/');

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], Stream::create('OK'));
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertEquals('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        $this->assertEquals('1; mode=block', $response->getHeaderLine('X-XSS-Protection'));
        $this->assertEquals('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        $this->assertStringContainsString('camera=()', $response->getHeaderLine('Permissions-Policy'));
    }

    public function testCustomSecurityHeadersOverride(): void
    {
        $middleware = new SecurityHeadersMiddleware([
            'X-Frame-Options' => 'DENY',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        ]);
        $request = new ServerRequest('GET', '/');

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], Stream::create('OK'));
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertEquals('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertEquals('max-age=31536000; includeSubDomains', $response->getHeaderLine('Strict-Transport-Security'));
        $this->assertEquals('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }
}
