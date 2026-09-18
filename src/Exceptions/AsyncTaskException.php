<?php

namespace Native\Mobile\Exceptions;

use RuntimeException;

/**
 * The exception handed to an async task's `->failed()` callback.
 *
 * The original throwable ran in a different PHP interpreter and can't cross the
 * thread boundary, so this stand-in carries the original class name, message and
 * trace string. `getMessage()` returns the original message, so the common
 * `->failed(fn ($e) => $e->getMessage())` reads naturally.
 */
class AsyncTaskException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $originalClass = 'Exception',
        public readonly ?string $originalTrace = null,
    ) {
        parent::__construct($message);
    }

    /** The class name of the throwable raised inside the async task. */
    public function originalClass(): string
    {
        return $this->originalClass;
    }

    /** The async task's original stack trace as a string, if captured. */
    public function originalTrace(): ?string
    {
        return $this->originalTrace;
    }
}
