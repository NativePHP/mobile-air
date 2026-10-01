<?php

namespace Native\Mobile\Events\Async;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Exceptions\AsyncTaskException;
use Native\Mobile\PendingAsyncTask;

/**
 * A background async task threw. Carries the exception's class, message and
 * (best-effort) trace string — the exception object itself can't cross the
 * thread boundary, so it's reconstructed as an {@see AsyncTaskException}
 * before the `->failed()` callback runs.
 *
 * @see PendingAsyncTask
 */
class AsyncTaskFailed
{
    use Dispatchable;

    public function __construct(
        public string $id,
        public string $exceptionClass,
        public string $message,
        public ?string $trace = null,
    ) {}
}
