<?php

namespace Native\Mobile\DevTools;

use Illuminate\Container\Container;
use Native\Mobile\Contracts\ExceptionReporter;

/**
 * Inert relay between the runtime's catch sites and an optional
 * ExceptionReporter binding. Reporting must never be able to break the
 * error path that invokes it: no binding means no-op, a throwing reporter
 * is swallowed, and re-entrant reports are dropped.
 */
class CrashRelay
{
    protected static bool $reporting = false;

    public static function report(\Throwable $e, array $context = []): void
    {
        if (static::$reporting) {
            return;
        }

        try {
            $container = Container::getInstance();

            if (! $container || ! $container->bound(ExceptionReporter::class)) {
                return;
            }

            static::$reporting = true;

            $container->make(ExceptionReporter::class)->report($e, $context);
        } catch (\Throwable) {
            // Never let reporting failures cascade into the error path.
        } finally {
            static::$reporting = false;
        }
    }
}
