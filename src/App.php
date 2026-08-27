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

    private ?string $basePath = null;

    private bool $routesLoaded = false;

    /**
     * Callback to configure the RouteLoader before loading.
     *
     * @var callable|null
     */
    private $routeConfigurator = null;

    public function __construct(
        private readonly ?ContainerInterface $container = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?object $router = null
    ) {
    }

    /**
     * Fluent application builder entry point.
     */
    public static function configure(string $basePath): AppBuilder
    {
        return new AppBuilder($basePath);
    }

    /**
     * Set the application base path (project root directory).
     * Route files will be loaded from {basePath}/routes/
     */
    public function setBasePath(string $basePath): self
    {
        $this->basePath = rtrim($basePath, '/\\');
        return $this;
    }

    public function getBasePath(): ?string
    {
        return $this->basePath;
    }

    /**
     * Register a callback to configure the RouteLoader before route files are loaded.
     *
     * The callback receives the RouteLoader instance:
     *   $app->configureRoutes(function (RouteLoader $loader) {
     *       $loader->register('admin', ['prefix' => '/admin', 'middleware' => ['auth']]);
     *       $loader->apiMiddleware(['throttle:60']);
     *   });
     */
    public function configureRoutes(callable $callback): self
    {
        $this->routeConfigurator = $callback;
        return $this;
    }

    /**
     * Load route files from the routes/ directory.
     * Automatically loads web.php and api.php if they exist.
     * Called automatically before handling a request.
     */
    public function loadRoutes(): self
    {
        if ($this->routesLoaded) {
            return $this;
        }

        if ($this->router === null || !class_exists(\Switch\Router\RouteLoader::class)) {
            return $this;
        }

        $routesPath = $this->resolveRoutesPath();
        if ($routesPath === null || !is_dir($routesPath)) {
            return $this;
        }

        /** @var \Switch\Router\Router $router */
        $router = $this->router;
        $loader = new \Switch\Router\RouteLoader($router, $routesPath);

        // Allow user to configure the loader (register extra files, set middleware, etc.)
        if ($this->routeConfigurator !== null) {
            ($this->routeConfigurator)($loader);
        }

        $loader->load();
        $this->routesLoaded = true;

        return $this;
    }

    /**
     * Resolve the routes directory path.
     */
    private function resolveRoutesPath(): ?string
    {
        if ($this->basePath !== null) {
            return $this->basePath . DIRECTORY_SEPARATOR . 'routes';
        }

        // Auto-detect: try common locations relative to working directory
        $cwd = getcwd();
        if ($cwd !== false && is_dir($cwd . DIRECTORY_SEPARATOR . 'routes')) {
            return $cwd . DIRECTORY_SEPARATOR . 'routes';
        }

        return null;
    }

    public function use(MiddlewareInterface|callable|string $middleware): self
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
        // Auto-load route files before handling request
        $this->loadRoutes();

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

        // Auto-detect and register switch/debugbar if installed
        if (class_exists(\Switch\DebugBar\Http\Middleware\DebugBarMiddleware::class)) {
            $debug = true;
            if ($this->container !== null && $this->container->has(\Switch\Config\Config::class)) {
                /** @var \Switch\Config\Config $config */
                $config = $this->container->get(\Switch\Config\Config::class);
                $debug = (bool) $config->get('app.debug', true);
            } elseif (function_exists('env')) {
                $debug = (bool) env('APP_DEBUG', true);
            } elseif (defined('APP_DEBUG')) {
                $debug = (bool) APP_DEBUG;
            }

            if ($debug) {
                \Switch\DebugBar\DebugBar::getInstance()->enable();
                $stack[] = new \Switch\DebugBar\Http\Middleware\DebugBarMiddleware();
            } else {
                \Switch\DebugBar\DebugBar::getInstance()->disable();
            }
        }

        // Auto-detect and register switch/diagram if installed
        if (class_exists(\Switch\Diagram\Http\Middleware\DiagramMiddleware::class)) {
            $diagramEnabled = true;
            if ($this->container !== null && $this->container->has(\Switch\Config\Config::class)) {
                /** @var \Switch\Config\Config $config */
                $config = $this->container->get(\Switch\Config\Config::class);
                $diagramEnabled = (bool) $config->get('diagram.enabled', (bool) $config->get('app.debug', true));
            } elseif (function_exists('env')) {
                $diagramEnabled = (bool) env('DIAGRAM_ENABLED', env('APP_ENV', 'development') !== 'production');
            }

            if ($diagramEnabled) {
                \Switch\Diagram\Diagram::getInstance()->enable();
                $stack[] = new \Switch\Diagram\Http\Middleware\DiagramMiddleware();
            } else {
                \Switch\Diagram\Diagram::getInstance()->disable();
            }
        }

        foreach ($this->middlewareStack as $middleware) {
            if (is_string($middleware)) {
                if ($this->container !== null && $this->container->has($middleware)) {
                    $middleware = $this->container->get($middleware);
                } elseif (class_exists($middleware)) {
                    $middleware = new $middleware();
                }
            }
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
