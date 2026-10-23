<?php

namespace Tests\Fixtures\Edge;

use Closure;
use Throwable;

/**
 * Work that a test runs on a lane, through the entry point of
 * that lane: as an async task, as a queued job, in a route
 * or in an artisan command. The test sets what it runs.
 */
class LaneWork
{
    public static ?Closure $work = null;

    public static mixed $answer = null;

    public static ?Throwable $failure = null;

    /**
     * Run the work and keep what it answered. What it throws is
     * kept as well, since the lane that ran the work catches
     * it, and the test would not see what the work threw.
     */
    public function handle(): void
    {
        try {
            static::$answer = (static::$work)();
        } catch (Throwable $e) {
            throw static::$failure = $e;
        }
    }
}
