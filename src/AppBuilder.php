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
     * @var array<int, string|object>
     */
    private array $providers = [];

    /**
     * Configure application exception & error handling.
     */
    public function withExceptions(callable $callback): self
    {
        $this->exceptionConfigurator = $callback;
        return $this;
    }

    /**
     * Register Service Providers for the application.
     *
     * @param array<int, string|object> $providers
     */
    public function withProviders(array $providers): self
    {
        $this->providers = array_merge($this->providers, $providers);
        return $this;
    }

    /**
     * Build and return the configured App instance.
     */
    public function create(): App
    {
        // 0. Ensure Application PSR-4 autoloader is active for $basePath/app
        $appDir = $this->basePath . '/app';
        if (is_dir($appDir)) {
            spl_autoload_register(function (string $class) use ($appDir): void {
                if (str_starts_with($class, 'App\\')) {
                    $relative = substr($class, 4);
                    $file = $appDir . '/' . str_replace('\\', '/', $relative) . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                    }
                }
            });
        }

        // 0.1. Load .env environment variables
        if (!function_exists('env') && class_exists(\Switch\Config\Env::class)) {
            $configHelpers = dirname((new \ReflectionClass(\Switch\Config\Env::class))->getFileName()) . '/helpers.php';
            if (file_exists($configHelpers)) {
                require_once $configHelpers;
            }
        }

        $envFile = $this->basePath . '/.env';
        if (file_exists($envFile)) {
            if (class_exists(\Dotenv\Dotenv::class)) {
                try {
                    \Dotenv\Dotenv::createMutable($this->basePath)->safeLoad();
                } catch (\Throwable) {
                    // Ignored
                }
            }

            if (class_exists(\Switch\Config\Env::class)) {
                \Switch\Config\Env::load($envFile);
            }
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
        if (class_exists(\Switch\Database\Connection\Connection::class)) {
            $connection = null;
            $dbConfigFile = $this->basePath . '/config/database.php';

            if (file_exists($dbConfigFile)) {
                $dbConfig = require $dbConfigFile;
                $defaultDriver = $dbConfig['default'] ?? 'sqlite';
                $connOpts = $dbConfig['connections'][$defaultDriver] ?? null;

                if ($connOpts !== null) {
                    if ($defaultDriver === 'sqlite' && isset($connOpts['database'])) {
                        $dbPath = $connOpts['database'];
                        if ($dbPath !== ':memory:' && !str_contains($dbPath, '::memory::')) {
                            $dir = dirname($dbPath);
                            if (!is_dir($dir)) {
                                @mkdir($dir, 0777, true);
                            }
                        }
                    }
                    $connection = \Switch\Database\Connection\Connection::fromArray(
                        array_merge(['driver' => $defaultDriver], $connOpts)
                    );
                }
            }

            if ($connection === null) {
                $dbFile = $this->basePath . '/database/database.sqlite';
                if (!is_dir(dirname($dbFile))) {
                    @mkdir(dirname($dbFile), 0777, true);
                }
                $connection = \Switch\Database\Connection\Connection::sqlite($dbFile);
            }

            if (class_exists(\Switch\Database\ORM\Model::class)) {
                \Switch\Database\ORM\Model::setConnection($connection);
            }
        }

        // 3. Initialize View Engine
        $viewsPath = $this->basePath . '/resources/views';
        $cachePath = $this->basePath . '/storage/views';
        if (is_dir($viewsPath) && class_exists(\Switch\View\Engine\ViewEngine::class)) {
            $viewEngine = new \Switch\View\Engine\ViewEngine($viewsPath, $cachePath);
            $appEnv = function_exists('env') ? (string) env('APP_ENV', 'development') : 'development';
            $appDebug = function_exists('env') ? (bool) env('APP_DEBUG', true) : true;
            $viewEngine->setDebug($appDebug && $appEnv !== 'production');
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

        // 5. Initialize Service Container & Service Providers
        $container = null;
        if (class_exists(\Switch\Container\Container::class)) {
            $container = new \Switch\Container\Container();

            // Load providers from config/app.php if defined
            $appConfigFile = $this->basePath . '/config/app.php';
            if (file_exists($appConfigFile)) {
                $appConfig = require $appConfigFile;
                if (isset($appConfig['providers']) && is_array($appConfig['providers'])) {
                    $this->providers = array_merge($this->providers, $appConfig['providers']);
                }
            }

            foreach ($this->providers as $provider) {
                if (is_string($provider) && class_exists($provider)) {
                    $instance = new $provider();
                    if ($instance instanceof \Switch\Container\ServiceProviderInterface) {
                        $container->register($instance);
                    }
                    if (method_exists($instance, 'boot')) {
                        $instance->boot($container);
                    }
                } elseif (is_object($provider)) {
                    if ($provider instanceof \Switch\Container\ServiceProviderInterface) {
                        $container->register($provider);
                    }
                    if (method_exists($provider, 'boot')) {
                        $provider->boot($container);
                    }
                }
            }
        }

        // 6. Create Router & App Kernel
        $router = class_exists(\Switch\Router\Facade\Route::class)
            ? \Switch\Router\Facade\Route::getRouter()
            : null;

        $app = new App(container: $container, router: $router);
        $app->setBasePath($this->basePath);

        if ($this->middlewareConfigurator !== null) {
            $middlewareCollector = new Config\MiddlewareCollector();
            ($this->middlewareConfigurator)($middlewareCollector);
            foreach ($middlewareCollector->getGlobalMiddleware() as $middleware) {
                $app->use($middleware);
            }

            $groups = $middlewareCollector->getGroups();
            if (!empty($groups['web']) || !empty($groups['api'])) {
                $app->configureRoutes(function ($loader) use ($groups) {
                    if ($loader instanceof \Switch\Router\RouteLoader) {
                        if (!empty($groups['web'])) {
                            $loader->webMiddleware($groups['web']);
                        }
                        if (!empty($groups['api'])) {
                            $loader->apiMiddleware($groups['api']);
                        }
                    }
                });
            }
        }

        return $app;
    }
}
