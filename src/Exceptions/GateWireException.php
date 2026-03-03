<?php

declare(strict_types=1);

namespace GateWire\Exceptions;

use Exception;

class GateWireException extends Exception
{
    /**
     * Create a GateWireException from an HTTP status code and message.
     *
     * @param  int    $statusCode HTTP status code (e.g. 400, 402, 429, 503).
     * @param  string $message    Human-readable error message from the API.
     * @return static
     */
    public static function fromResponse(int $statusCode, string $message): static
    {
        return new static($message, $statusCode);
    }
}
