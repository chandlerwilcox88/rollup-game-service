<?php

namespace App\Exceptions;

/**
 * An action the Roll Up rules do not allow (wrong state, illegal selection,
 * unknown player). Carries the HTTP status and a stable machine code so the
 * controller can answer with a precise 4xx instead of a generic 500.
 */
class GameRuleException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'illegal_action',
        public readonly int $status = 409
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $message, string $code): self
    {
        return new self($message, $code, 409);
    }

    public static function invalid(string $message, string $code): self
    {
        return new self($message, $code, 422);
    }

    public static function notFound(string $message, string $code): self
    {
        return new self($message, $code, 404);
    }
}
