<?php

declare(strict_types=1);

namespace Switch\Kernel\Event;

use Psr\Http\Message\ResponseInterface;

class ResponseSendingEvent
{
    public function __construct(
        public readonly ResponseInterface $response
    ) {
    }
}
