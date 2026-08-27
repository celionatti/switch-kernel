<?php

declare(strict_types=1);

namespace Switch\Kernel\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Zero-Overhead Security Headers Middleware.
 *
 * Automatically injects industry-standard HTTP security headers to protect against
 * Clickjacking, MIME-sniffing, cross-site scripting (XSS), and data leakage.
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * @var array<string, string>
     */
    private array $headers;

    /**
     * @param array<string, string> $headers Custom security headers to override defaults.
     */
    public function __construct(array $headers = [])
    {
        $this->headers = array_merge([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ], $headers);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach ($this->headers as $name => $value) {
            if (!$response->hasHeader($name) && !empty($value)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
