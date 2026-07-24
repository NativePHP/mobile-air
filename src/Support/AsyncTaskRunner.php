<?php

namespace Native\Mobile\Support;

use Laravel\SerializableClosure\SerializableClosure;
use Native\Mobile\Events\Async\AsyncTaskFailed;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Throwable;

/**
 * Executes an async task's work envelope in the background context, then hands
 * the outcome to {@see AsyncTaskTransport} for delivery back to the UI runloop.
 *
 * A "work envelope" is one of:
 *   - ['kind' => 'closure', 'closure' => SerializableClosure]
 *   - ['kind' => 'task',    'task' => class-string, 'args' => array]
 *
 * The envelope is PHP-serialized (not JSON) so the SerializableClosure and any
 * serializable task arguments survive the hop intact.
 */
class AsyncTaskRunner
{
    /**
     * Entry point for the `native:async:run` command. Reads the payload, runs
     * the work, and reports success or failure.
     */
    public static function run(string $id): void
    {
        $payload = AsyncTaskTransport::readPayload($id);

        if ($payload === null) {
            AsyncTaskTransport::complete($id, AsyncTaskFailed::class, [
                'id' => $id,
                'exceptionClass' => 'RuntimeException',
                'message' => "Async task payload for [{$id}] was missing.",
                'trace' => null,
            ]);

            return;
        }

        try {
            $work = unserialize($payload);
            $result = static::invoke($work);

            AsyncTaskTransport::complete($id, AsyncTaskFinished::class, [
                'id' => $id,
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            AsyncTaskTransport::complete($id, AsyncTaskFailed::class, [
                'id' => $id,
                'exceptionClass' => $e::class,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Invoke a work envelope and return its result. Shared by the background
     * runner and the inline test fake, so both execute tasks identically.
     */
    public static function invoke(array $work): mixed
    {
        $kind = $work['kind'] ?? null;

        if ($kind === 'closure') {
            $closure = $work['closure'] ?? null;
            if ($closure instanceof SerializableClosure) {
                $closure = $closure->getClosure();
            }
            if (! $closure instanceof \Closure) {
                throw new \RuntimeException('Async task closure could not be reconstructed.');
            }

            return $closure();
        }

        if ($kind === 'task') {
            $class = $work['task'] ?? null;
            if (! is_string($class) || ! class_exists($class)) {
                throw new \RuntimeException('Async task class ['.(string) $class.'] does not exist.');
            }

            $instance = function_exists('app') ? app($class) : new $class;
            $args = $work['args'] ?? [];

            return $instance->handle(...array_values($args));
        }

        throw new \RuntimeException('Unknown async task work kind ['.(string) $kind.'].');
    }
}
