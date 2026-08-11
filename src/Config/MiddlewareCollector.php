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
     * @return array<int, mixed>
     */
    public function getGlobalMiddleware(): array
    {
        return $this->globalMiddleware;
    }

    /**
     * @return array<string, string>
     */
    public function getAliases(): array
    {
        return $this->aliases;
    }
}
