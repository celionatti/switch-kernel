<?php

declare(strict_types=1);

namespace Switch\Kernel;

use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Switch\Http\ServerRequest;
use Switch\Kernel\Event\RequestReceivedEvent;
use Switch\Kernel\Event\ResponseSendingEvent;
use Switch\Kernel\Middleware\MiddlewarePipeline;
use Switch\Kernel\Middleware\RoutingMiddleware;

class App implements RequestHandlerInterface
{
    /**
     * @var array<int, MiddlewareInterface|callable>
     */
    private array $middlewareStack = [];

    public function __construct(
        private readonly ?ContainerInterface $container = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?object $router = null
    ) {
    }

    public function use(MiddlewareInterface|callable $middleware): self
    {
        $this->middlewareStack[] = $middleware;
        return $this;
    }

    public function getContainer(): ?ContainerInterface
    {
        return $this->container;
    }

    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    public function getRouter(): ?object
    {
        return $this->router;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->eventDispatcher !== null) {
            $this->eventDispatcher->dispatch(new RequestReceivedEvent($request));
        }

        $stack = [];

        // Auto-detect and register switch/error-handler if installed
        if (class_exists(\Switch\ErrorHandler\ErrorHandler::class)) {
            $debug = true;
            if ($this->container !== null && $this->container->has(\Switch\Config\Config::class)) {
                /** @var \Switch\Config\Config $config */
                $config = $this->container->get(\Switch\Config\Config::class);
                $debug = (bool) $config->get('app.debug', true);
            } elseif (defined('APP_DEBUG')) {
                $debug = (bool) APP_DEBUG;
            }

            $errorHandler = \Switch\ErrorHandler\ErrorHandler::register($debug);
            $stack[] = new \Switch\ErrorHandler\Middleware\ErrorHandlerMiddleware($errorHandler);
        }

        foreach ($this->middlewareStack as $middleware) {
            $stack[] = $middleware;
        }

        if ($this->router !== null) {
            $stack[] = new RoutingMiddleware($this->router, $this->container);
        }

        $pipeline = new MiddlewarePipeline($stack);
        $response = $pipeline->handle($request);

        if ($this->eventDispatcher !== null) {
            $this->eventDispatcher->dispatch(new ResponseSendingEvent($response));
        }

        return $response;
    }

    public function run(?ServerRequestInterface $request = null): void
    {
        $request ??= ServerRequest::fromGlobals();
        $response = $this->handle($request);
        $this->emit($response);
    }

    public function emit(ResponseInterface $response): void
    {
        if (headers_sent()) {
            return;
        }

        $statusCode = $response->getStatusCode();
        $reasonPhrase = $response->getReasonPhrase();
        $statusLine = sprintf('HTTP/%s %d %s', $response->getProtocolVersion(), $statusCode, $reasonPhrase);

        header($statusLine, true, $statusCode);

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), false);
            }
        }

        echo (string) $response->getBody();
    }
}
