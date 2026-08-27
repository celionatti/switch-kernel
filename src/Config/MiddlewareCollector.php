<?php

declare(strict_types=1);

namespace Switch\Kernel\Config;

class MiddlewareCollector
{
    /**
     * @var array<int, mixed>
     */
    private array $globalMiddleware = [];

    /**
     * @var array<string, string>
     */
    private array $aliases = [];

    /**
     * Append global middleware to the end of the stack.
     */
    public function append(mixed ...$middleware): self
    {
        foreach ($middleware as $item) {
            if (is_array($item)) {
                $this->globalMiddleware = array_merge($this->globalMiddleware, $item);
            } else {
                $this->globalMiddleware[] = $item;
            }
        }
        return $this;
    }

    /**
     * Prepend global middleware to the top of the stack.
     */
    public function prepend(mixed ...$middleware): self
    {
        $items = [];
        foreach ($middleware as $item) {
            if (is_array($item)) {
                $items = array_merge($items, $item);
            } else {
                $items[] = $item;
            }
        }
        $this->globalMiddleware = array_merge($items, $this->globalMiddleware);
        return $this;
    }

    /**
     * Register route middleware aliases (e.g. ['auth' => AuthMiddleware::class]).
     */
    public function alias(array $aliases): self
    {
        $this->aliases = array_merge($this->aliases, $aliases);
        return $this;
    }

    /**
     * @var array<string, array<int, mixed>>
     */
    private array $groups = [
        'web' => [],
        'api' => [],
    ];

    /**
     * Define or append middleware to the 'web' route group.
     */
    public function web(mixed ...$middleware): self
    {
        return $this->group('web', ...$middleware);
    }

    /**
     * Define or append middleware to the 'api' route group.
     */
    public function api(mixed ...$middleware): self
    {
        return $this->group('api', ...$middleware);
    }

    /**
     * Define or append middleware to a named route group.
     */
    public function group(string $name, mixed ...$middleware): self
    {
        if (!isset($this->groups[$name])) {
            $this->groups[$name] = [];
        }

        foreach ($middleware as $item) {
            if (is_array($item)) {
                $this->groups[$name] = array_merge($this->groups[$name], $item);
            } else {
                $this->groups[$name][] = $item;
            }
        }

        return $this;
    }

    /**
     * @return array<int, mixed>
     */
    public function getGlobalMiddleware(): array
    {
        return $this->globalMiddleware;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * @return array<string, string>
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }
}
