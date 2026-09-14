<?php

namespace Native\Mobile\Edge;

use Native\Mobile\Edge\Contracts\RuntimeObserver;
use Native\Mobile\Edge\Runtime\ComponentPublished;
use Native\Mobile\Edge\Runtime\DispatchFinished;
use Native\Mobile\Edge\Runtime\DispatchStarting;
use Native\Mobile\Edge\Runtime\RuntimeFailed;
use Throwable;

/**
 * Static fan-out for opt-in native runtime observers.
 *
 * Every hot-path call is guarded by any(), so an application without an
 * observer pays only an empty-registry check and allocates no snapshots.
 */
class RuntimeObservers
{
    /** @var array<int, RuntimeObserver> */
    protected static array $observers = [];

    protected static int $sequence = 0;

    public static function register(RuntimeObserver $observer): int
    {
        $id = ++static::$sequence;
        static::$observers[$id] = $observer;

        return $id;
    }

    public static function unregister(int $id): void
    {
        unset(static::$observers[$id]);
    }

    public static function any(): bool
    {
        return static::$observers !== [];
    }

    public static function reset(): void
    {
        static::$observers = [];
        static::$sequence = 0;
    }

    public static function componentPublished(ComponentPublished $event): void
    {
        static::notify(fn (RuntimeObserver $observer) => $observer->componentPublished($event));
    }

    public static function dispatchStarting(DispatchStarting $event): void
    {
        static::notify(fn (RuntimeObserver $observer) => $observer->dispatchStarting($event));
    }

    public static function dispatchFinished(DispatchFinished $event): void
    {
        static::notify(fn (RuntimeObserver $observer) => $observer->dispatchFinished($event));
    }

    public static function failed(RuntimeFailed $event): void
    {
        static::notify(fn (RuntimeObserver $observer) => $observer->failed($event));
    }

    protected static function notify(callable $notification): void
    {
        foreach (static::$observers as $observer) {
            try {
                $notification($observer);
            } catch (Throwable) {
                // Instrumentation must never change application behavior.
            }
        }
    }
}
