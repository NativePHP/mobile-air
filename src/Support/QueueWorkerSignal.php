<?php

namespace Native\Mobile\Support;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lets native boot the queue worker only when there is work for it.
 *
 * The device worker is a whole Laravel boot of its own, so native keeps it
 * off until something is queued. Two things tell it:
 *
 *  - When a job is pushed to the default connection, PHP calls
 *    `QueueWorker.Wake`, which boots the worker or wakes it from its wait.
 *  - A marker file in storage says jobs may be waiting. It is written on every
 *    push and only removed by the worker once the queue is empty, so jobs left
 *    over when the app was closed are found at the next launch by a file
 *    check, without booting Laravel.
 *
 * When the worker has been idle a while it asks {@see idleStatus()} whether to
 * stop, and when the next delayed or retried job is due.
 *
 * @internal
 */
class QueueWorkerSignal
{
    /** Drivers that run a job in the same process, so no worker ever needs it. */
    public const INLINE_DRIVERS = ['sync', 'null', 'deferred', 'background'];

    /** Where the marker lives. Native reads the same path under its storage dir. */
    public static function markerPath(): string
    {
        return storage_path('framework/queue_pending');
    }

    /** Listener for {@see JobQueued}. */
    public static function jobQueued(JobQueued $event): void
    {
        if (! static::shouldSignal($event->connectionName)) {
            return;
        }

        static::markPending();
        static::wake();
    }

    /**
     * Only a push the device worker will pick up is worth a signal: one on a
     * device (not Jump, not tests), to the connection `queue:work` uses, with
     * a driver that actually waits for a worker.
     */
    public static function shouldSignal(?string $connection): bool
    {
        if (! config('nativephp-internal.running') || AsyncTaskTransport::isJump()) {
            return false;
        }

        $default = config('queue.default');

        if ($connection === null || $connection !== $default) {
            return false;
        }

        $driver = config("queue.connections.{$connection}.driver");

        return is_string($driver) && ! in_array($driver, static::INLINE_DRIVERS, true);
    }

    public static function markPending(): void
    {
        try {
            $path = static::markerPath();

            if (! is_dir(dirname($path))) {
                @mkdir(dirname($path), 0755, true);
            }

            @touch($path);
        } catch (Throwable) {
            // Bookkeeping must never fail a dispatch.
        }
    }

    public static function clearPending(): void
    {
        @unlink(static::markerPath());
    }

    public static function hasPending(): bool
    {
        return is_file(static::markerPath());
    }

    /** Ask native to boot the worker, or to stop waiting and poll now. */
    public static function wake(): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        try {
            nativephp_call('QueueWorker.Wake', '{}');
        } catch (Throwable) {
            // An older native project has no such function and polls anyway.
        }
    }

    /**
     * What the idle worker needs to decide whether to stop.
     *
     * `pending` is how many jobs the default queue holds, or null when it
     * could not be read. `next` is how many seconds until the earliest of them
     * can run (0 for now), or null when the driver can't say. When the queue
     * is empty the marker is removed, so the next launch boots nothing.
     *
     * @return array{pending: int|null, next: int|null}
     */
    public static function idleStatus(): array
    {
        $connection = config('queue.default');

        try {
            $queue = app('queue')->connection($connection);
            $pending = (int) $queue->size();

            if ($pending === 0) {
                static::clearPending();

                // A job pushed between the count and the unlink would have
                // found the marker still there. Count again so it isn't lost.
                $pending = (int) $queue->size();

                if ($pending === 0) {
                    return ['pending' => 0, 'next' => null];
                }

                static::markPending();
            }

            return ['pending' => $pending, 'next' => static::secondsUntilNextJob($connection)];
        } catch (Throwable) {
            return ['pending' => null, 'next' => null];
        }
    }

    /**
     * For the database driver, when the next job in the worker's queue can be
     * picked up: a waiting job at its `available_at`, a reserved one when its
     * `retry_after` runs out. Null for any other driver.
     */
    protected static function secondsUntilNextJob(?string $connection): ?int
    {
        $config = config("queue.connections.{$connection}");

        if (! is_array($config) || ($config['driver'] ?? null) !== 'database') {
            return null;
        }

        $table = DB::connection($config['connection'] ?? null)->table($config['table'] ?? 'jobs')
            ->where('queue', $config['queue'] ?? 'default');

        $waiting = (clone $table)->whereNull('reserved_at')->min('available_at');
        $reserved = (clone $table)->whereNotNull('reserved_at')->min('reserved_at');

        $due = array_filter([
            $waiting !== null ? (int) $waiting : null,
            $reserved !== null ? (int) $reserved + (int) ($config['retry_after'] ?? 60) : null,
        ], fn ($at) => $at !== null);

        if ($due === []) {
            return null;
        }

        return max(0, min($due) - time());
    }
}
