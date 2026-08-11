<?php

declare(strict_types=1);

namespace Switch\Kernel\Config;

use Throwable;
use Switch\ErrorHandler\ErrorHandler;
use Switch\ErrorHandler\Reporter\ReporterInterface;

class ExceptionsCollector
{
    /**
     * @var array<int, string>
     */
    private array $dontReport = [];

    public function __construct(
        private readonly ?ErrorHandler $errorHandler = null
    ) {
    }

    /**
     * Register a custom exception reporter callback.
     */
    public function report(callable $callback): self
    {
        if ($this->errorHandler !== null) {
            $this->errorHandler->addReporter(new class($callback) implements ReporterInterface {
                private $cb;

                public function __construct(callable $cb)
                {
                    $this->cb = $cb;
                }

                public function report(Throwable $e): void
                {
                    ($this->cb)($e);
                }
            });
        }
        return $this;
    }

    /**
     * Specify exception classes that should NOT be reported to logs.
     *
     * @param array<int, string> $exceptions
     */
    public function dontReport(array $exceptions): self
    {
        $this->dontReport = array_merge($this->dontReport, $exceptions);
        return $this;
    }

    /**
     * Add a custom reporter object instance.
     */
    public function addReporter(object $reporter): self
    {
        if ($this->errorHandler !== null && method_exists($this->errorHandler, 'addReporter')) {
            $this->errorHandler->addReporter($reporter);
        }
        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getDontReport(): array
    {
        return $this->dontReport;
    }
}
