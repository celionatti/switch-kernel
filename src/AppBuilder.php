<?php

declare(strict_types=1);

namespace Switch\Kernel;

class AppBuilder
{
    private string $basePath;

    /**
     * @var array<string, string>
     */
    private array $routes = [];

    private ?object $middlewareConfigurator = null;
    private ?object $exceptionConfigurator = null;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * Define route files for the application.
     */
    public function withRouting(?string $web = null, ?string $api = null, ?string $commands = null): self
    {
        if ($web !== null) {
            $this->routes['web'] = $web;
        }
        if ($api !== null) {
            $this->routes['api'] = $api;
        }
        if ($commands !== null) {
            $this->routes['commands'] = $commands;
        }
        return $this;
    }

    /**
     * Configure application middleware stack.
     */
    public function withMiddleware(callable $callback): self
    {
        $this->middlewareConfigurator = $callback;
        return $this;
    }

    /**
     * Configure application exception & error handling.
     */
    public function withExceptions(callable $callback): self
    {
        $this->exceptionConfigurator = $callback;
        return $this;
    }

    /**
     * Bootstrap and create the App instance with engine services.
     */
    public function create(): App
    {
        // 0. Load .env environment variables
        $envFile = $this->basePath . '/.env';
        if (file_exists($envFile) && class_exists(\Switch\Config\Env::class)) {
            \Switch\Config\Env::load($envFile);
        }

        // 1. Initialize Configuration
        $configPath = $this->basePath . '/config';
        if (class_exists(\Switch\Config\Config::class)) {
            $config = new \Switch\Config\Config();
            if (is_dir($configPath)) {
                $config->loadFromDirectory($configPath);
            }
        }

        // 2. Initialize Database Connection if configured
        $dbFile = $this->basePath . '/database/database.sqlite';
        if (file_exists($dbFile) || is_dir(dirname($dbFile))) {
            if (!is_dir(dirname($dbFile))) {
                @mkdir(dirname($dbFile), 0777, true);
            }
            if (class_exists(\Switch\Database\Connection\Connection::class)) {
                $connection = \Switch\Database\Connection\Connection::sqlite($dbFile);
                if (class_exists(\Switch\Database\ORM\Model::class)) {
                    \Switch\Database\ORM\Model::setConnection($connection);
                }
            }
        }

        // 3. Initialize View Engine
        $viewsPath = $this->basePath . '/resources/views';
        $cachePath = $this->basePath . '/storage/views';
        if (is_dir($viewsPath) && class_exists(\Switch\View\Engine\ViewEngine::class)) {
            $viewEngine = new \Switch\View\Engine\ViewEngine($viewsPath, $cachePath);
            \Switch\View\View::setEngine($viewEngine);
        }

        // 4. Initialize Error Handler
        if (class_exists(\Switch\ErrorHandler\ErrorHandler::class)) {
            $errorHandler = \Switch\ErrorHandler\ErrorHandler::register();
            $errorHandler->setDebug(true);
            if ($this->exceptionConfigurator !== null) {
                $exceptionsCollector = new Config\ExceptionsCollector($errorHandler);
                ($this->exceptionConfigurator)($exceptionsCollector);
            }
        }

        // 5. Create Router & App Kernel
        $router = class_exists(\Switch\Router\Facade\Route::class)
            ? \Switch\Router\Facade\Route::getRouter()
            : null;

        $app = new App(router: $router);
        $app->setBasePath($this->basePath);

        if ($this->middlewareConfigurator !== null) {
            $middlewareCollector = new Config\MiddlewareCollector();
            ($this->middlewareConfigurator)($middlewareCollector);
            foreach ($middlewareCollector->getGlobalMiddleware() as $middleware) {
                $app->use($middleware);
            }
        }

        return $app;
    }
}
