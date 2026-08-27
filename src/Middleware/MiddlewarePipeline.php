<?php

declare(strict_types=1);

namespace Switch\Kernel\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Switch\Http\Response;
use Switch\Http\Stream;

class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @var array<int, MiddlewareInterface|callable>
     */
    private array $middlewareStack = [];

    private int $index = 0;

    private ?RequestHandlerInterface $fallbackHandler = null;

    /**
     * @param array<int, MiddlewareInterface|callable> $middlewareStack
     */
    public function __construct(array $middlewareStack = [], ?RequestHandlerInterface $fallbackHandler = null)
    {
        $this->middlewareStack = array_values($middlewareStack);
        $this->fallbackHandler = $fallbackHandler;
    }

    public function pipe(MiddlewareInterface|callable $middleware): self
    {
        $this->middlewareStack[] = $middleware;
        return $this;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middlewareStack[$this->index])) {
            if ($this->fallbackHandler !== null) {
                return $this->fallbackHandler->handle($request);
            }

            return new Response(
                404,
                ['Content-Type' => 'text/html'],
                Stream::create('<h1>404 Not Found</h1>')
            );
        }

        $middleware = $this->middlewareStack[$this->index];
        $this->index++;

        if (is_string($middleware) && class_exists($middleware)) {
            $middleware = new $middleware();
        }

        if ($middleware instanceof MiddlewareInterface) {
            return $middleware->process($request, $this);
        }

        if (is_callable($middleware)) {
            return $middleware($request, $this);
        }

        return $this->handle($request);
    }
}
