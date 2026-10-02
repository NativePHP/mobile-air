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
            $result = static::normalizeResult(static::invoke($work));

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

    /**
     * Put a result through the exact JSON round trip the completion event makes
     * on the way back to the UI context, so what `->finished()` receives is what
     * survives the hop (objects arrive as arrays, and so on).
     *
     * Doing it HERE, in the background context, is what makes a non-encodable
     * result visible: it throws, so the run reports a failure and `->failed()`
     * fires. Left to `json_encode()` in the transport it would simply return
     * false, nothing would be delivered, and the UI would wait forever.
     *
     * The test fake runs the same normalization, so a test can't pass on a value
     * a device would never deliver.
     *
     * @throws \RuntimeException when the result can't be JSON-encoded
     */
    public static function normalizeResult(mixed $result): mixed
    {
        // Strings go through the encoder too — invalid UTF-8 is exactly the kind
        // of result that would otherwise fail silently on the way out.
        if ($result === null || is_int($result) || is_float($result) || is_bool($result)) {
            return $result;
        }

        $encoded = json_encode($result);

        if ($encoded === false) {
            throw new \RuntimeException(
                'The async task result could not be encoded for delivery to the UI thread ('
                .json_last_error_msg().'). Return arrays, scalars or JsonSerializable values from async work.'
            );
        }

        return json_decode($encoded, true);
    }
}
