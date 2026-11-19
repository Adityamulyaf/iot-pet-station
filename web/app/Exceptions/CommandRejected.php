<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A command the backend refused to send. Retryable reasons (busy, cooldown) clear on their own within seconds.
 */
class CommandRejected extends ValidationException
{
    public bool $retryable = false;

    public static function because(string $message, bool $retryable): self
    {
        $exception = static::withMessages(['command' => $message]);
        $exception->retryable = $retryable;

        return $exception;
    }
}
