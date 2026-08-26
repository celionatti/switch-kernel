<?php

declare(strict_types=1);

namespace Switch\Kernel\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Switch\Http\Response;
use Switch\Http\Stream;

class RoutingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly object $router,
        private readonly ?ContainerInterface $container = null
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!method_exists($this->router, 'match')) {
            return $handler->handle($request);
        }

        try {
            $match = $this->router->match($request->getMethod(), $request->getUri()->getPath());

            // Auto-feed RouteCollector if DebugBar is active
            if (class_exists(\Switch\DebugBar\DebugBar::class)) {
                $bar = \Switch\DebugBar\DebugBar::getInstance();
                if ($bar->isEnabled() && $bar->hasCollector('route')) {
                    $routeCollector = $bar->getCollector('route');
                    if ($routeCollector instanceof \Switch\DebugBar\Collectors\RouteCollector) {
                        $routeCollector->setRouteData(
                            uri: method_exists($match, 'getUri') ? $match->getUri() : $request->getUri()->getPath(),
                            method: $request->getMethod(),
                            action: $match->getHandler(),
                            middleware: $match->getMiddleware(),
                            parameters: $match->getParameters(),
                            name: method_exists($match, 'getName') ? $match->getName() : null
                        );
                    }
                }
            }

            // Pass route parameters as request attributes
            foreach ($match->getParameters() as $key => $value) {
                $request = $request->withAttribute($key, $value);
            }

            $routeHandler = $match->getHandler();
            $routeMiddleware = $match->getMiddleware();

            // Run route-specific middleware if any, then invoke the handler
            $pipeline = new MiddlewarePipeline([], new class($routeHandler, $this->container) implements RequestHandlerInterface {
                public function __construct(
                    private readonly mixed $handler,
                    private readonly ?ContainerInterface $container
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $handler = $this->handler;

                    // Resolve 'Controller@method' string syntax
                    if (is_string($handler) && str_contains($handler, '@')) {
                        [$class, $method] = explode('@', $handler, 2);
                        $instance = $this->container !== null && $this->container->has($class)
                            ? $this->container->get($class)
                            : new $class();
                        $handler = [$instance, $method];
                    }

                    // Resolve standalone invokable class string (e.g. Action::class)
                    if (is_string($handler) && class_exists($handler)) {
                        $handler = $this->container !== null && $this->container->has($handler)
                            ? $this->container->get($handler)
                            : new $handler();
                    }

                    // Resolve [Controller::class, 'method'] array syntax
                    if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
                        [$class, $method] = $handler;
                        $instance = $this->container !== null && $this->container->has($class)
                            ? $this->container->get($class)
                            : new $class();
                        $handler = [$instance, $method];
                    }

                    if (is_callable($handler)) {
                        $params = $request->getAttributes();
                        $args = $this->resolveCallableArguments($handler, $request, $params);
                        $result = $handler(...$args);

                        if ($result instanceof ResponseInterface) {
                            return $result;
                        }

                        if (is_string($result) || is_numeric($result)) {
                            return new Response(200, ['Content-Type' => 'text/html'], Stream::create((string) $result));
                        }

                        if (is_array($result) || is_object($result)) {
                            return new Response(200, ['Content-Type' => 'application/json'], Stream::create(json_encode($result)));
                        }
                    }

                    return new Response(500, [], Stream::create('Invalid Route Handler'));
                }

                /**
                 * Dynamically resolve arguments for controller method or closure.
                 *
                 * @param callable $callable
                 * @param ServerRequestInterface $request
                 * @param array<string, mixed> $routeParams
                 * @return array<int, mixed>
                 */
                private function resolveCallableArguments(callable $callable, ServerRequestInterface $request, array $routeParams): array
                {
                    if (is_array($callable)) {
                        $ref = new \ReflectionMethod($callable[0], $callable[1]);
                    } elseif (is_object($callable) && !$callable instanceof \Closure) {
                        $ref = new \ReflectionMethod($callable, '__invoke');
                    } else {
                        $ref = new \ReflectionFunction($callable);
                    }

                    $args = [];
                    foreach ($ref->getParameters() as $param) {
                        $name = $param->getName();
                        $type = $param->getType();
                        $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;

                        if ($typeName && (is_subclass_of($typeName, ServerRequestInterface::class) || $typeName === ServerRequestInterface::class)) {
                            $args[] = $request;
                        } elseif ($name === 'request') {
                            $args[] = $request;
                        } elseif ($name === 'params' && $typeName === 'array') {
                            $args[] = $routeParams;
                        } elseif (isset($routeParams[$name])) {
                            $val = $routeParams[$name];
                            if ($typeName === 'int') {
                                $val = (int) $val;
                            } elseif ($typeName === 'float') {
                                $val = (float) $val;
                            } elseif ($typeName === 'bool') {
                                $val = (bool) $val;
                            }
                            $args[] = $val;
                        } elseif ($param->isDefaultValueAvailable()) {
                            $args[] = $param->getDefaultValue();
                        } else {
                            $args[] = $request;
                        }
                    }

                    return $args;
                }
            });

            foreach ($routeMiddleware as $mw) {
                if (is_string($mw) && $this->container !== null && $this->container->has($mw)) {
                    $mw = $this->container->get($mw);
                } elseif (is_string($mw) && class_exists($mw)) {
                    $mw = new $mw();
                }

                if ($mw instanceof MiddlewareInterface || is_callable($mw)) {
                    $pipeline->pipe($mw);
                }
            }

            return $pipeline->handle($request);

        } catch (\Throwable $e) {
            if ($e instanceof \Switch\Router\Exception\MethodNotAllowedException) {
                return new Response(
                    405,
                    ['Allow' => implode(', ', $e->getAllowedMethods())],
                    Stream::create('405 Method Not Allowed')
                );
            }

            if ($e instanceof \Switch\Router\Exception\RouteNotFoundException) {
                return $handler->handle($request);
            }

            throw $e;
        }
    }
}
