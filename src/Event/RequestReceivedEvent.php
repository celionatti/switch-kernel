<?php

declare(strict_types=1);

namespace Switch\Kernel\Event;

use Psr\Http\Message\ServerRequestInterface;

class RequestReceivedEvent
{
    public function __construct(
        public readonly ServerRequestInterface $request
    ) {
    }
}
