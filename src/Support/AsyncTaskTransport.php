<?php

namespace Native\Mobile\Support;

use Native\Mobile\Events\Async\AsyncTaskFailed;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Symfony\Component\Process\Process;

/**
 * Moves an async task between the dispatching (UI) PHP context and the
 * background context that runs it, without a shared PHP heap and without
 * touching SQLite or the standard queue.
 *
 * The payload (a PHP-serialized work envelope) is handed off through a temp
 * file under `storage/framework/async/` — the async lane and the UI lane share
 * the app's filesystem, so no native RAM courier is needed. Completion, though,
 * must WAKE the UI runloop, which differs by environment:
 *
 *   - Device: `AsyncTask.Complete` bridge call → native `sendNativeEvent(...)` →
 *     `nphp_element_post_event` unblocks `nativephp_element_wait_event()`.
 *   - Jump (dev machine): the runloop is a plain PHP process polling over TCP;
 *     the subprocess writes a completion spool file that the Jump
 *     `nativephp_element_wait_event()` polyfill drains and returns as an event.
 *
 * @see docs/async-task-design.md
 */
class AsyncTaskTransport
{
    /** Absolute path to the async spool root (optionally a subdirectory). */
    public static function directory(string $sub = ''): string
    {
        $base = storage_path('framework/async');

        return $sub === '' ? $base : $base.DIRECTORY_SEPARATOR.$sub;
    }

    /** True when running under the Jump dev server (not a real device). */
    public static function isJump(): bool
    {
        return getenv('JUMP_BRIDGE_PORT') !== false;
    }

    /** True when running inside the Jump-spawned runner subprocess. */
    public static function isJumpRunner(): bool
    {
        return getenv('NATIVEPHP_ASYNC_JUMP') !== false;
    }

    // ── Dispatcher side (UI context) ────────────────

    /**
     * Persist the work envelope and kick off a background runner for it.
     * Returns immediately; the runner delivers completion asynchronously.
     */
    public static function dispatch(string $id, string $payload): void
    {
        static::writePayload($id, $payload);
        static::trigger($id);
    }

    protected static function writePayload(string $id, string $payload): void
    {
        $dir = static::directory('payload');
        static::ensureDir($dir);
        file_put_contents($dir.DIRECTORY_SEPARATOR.$id.'.task', $payload, LOCK_EX);
    }

    /** Ask the async lane (device) or a subprocess (Jump) to run the task. */
    protected static function trigger(string $id): void
    {
        if (static::isJump()) {
            static::spawnJumpRunner($id);

            return;
        }

        if (function_exists('nativephp_call')) {
            nativephp_call('AsyncTask.Dispatch', json_encode(['id' => $id]));
        }
    }

    /**
     * Launch `php artisan native:async:run` detached on the dev machine. The
     * parent Jump runloop is long-lived (the screen's whole session), so the
     * retained Process reference outlives the child's work.
     *
     * @var array<int, Process>
     */
    protected static array $jumpProcesses = [];

    protected static function spawnJumpRunner(string $id): void
    {
        if (! class_exists(Process::class)) {
            return;
        }

        // Symfony Process merges this over the inherited parent env, so we pass
        // only the delta that marks this as a Jump runner (completion → spool).
        $process = new Process(
            [PHP_BINARY, base_path('artisan'), 'native:async:run', '--id='.$id, '--jump'],
            base_path(),
            ['NATIVEPHP_ASYNC_JUMP' => '1'],
        );
        $process->setTimeout(null);
        $process->disableOutput();
        $process->start();

        // Keep a reference so the child isn't reaped when this method returns;
        // prune finished ones so the array doesn't grow without bound.
        static::$jumpProcesses = array_filter(
            static::$jumpProcesses,
            fn (Process $p) => $p->isRunning(),
        );
        static::$jumpProcesses[] = $process;
    }

    // ── Runner side (background context) ────────────

    /** Read and remove the work payload for a task id. */
    public static function readPayload(string $id): ?string
    {
        $path = static::directory('payload').DIRECTORY_SEPARATOR.$id.'.task';

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        @unlink($path);

        return $contents === false ? null : $contents;
    }

    /**
     * Deliver a completion back to the UI runloop. `$event` is the FQCN of the
     * outcome event ({@see AsyncTaskFinished} / {@see AsyncTaskFailed}); `$payload`
     * is the JSON-serializable event data (always carries `id`).
     */
    public static function complete(string $id, string $event, array $payload): void
    {
        if (static::isJumpRunner()) {
            static::writeCompletionSpool($id, $event, $payload);

            return;
        }

        if (function_exists('nativephp_call')) {
            nativephp_call('AsyncTask.Complete', json_encode([
                'id' => $id,
                'event' => $event,
                'payload' => $payload,
            ]));
        }
    }

    protected static function writeCompletionSpool(string $id, string $event, array $payload): void
    {
        $dir = static::directory('complete');
        static::ensureDir($dir);
        file_put_contents(
            $dir.DIRECTORY_SEPARATOR.$id.'.json',
            json_encode(['event' => $event, 'payload' => $payload]),
            LOCK_EX,
        );
    }

    /**
     * Drain any pending Jump completion spool files, returning the oldest as a
     * native-event array the runloop understands (`type` 20), or null. Called
     * from the Jump `nativephp_element_wait_event()` polyfill each tick.
     *
     * @return array{type: int, event: string, payload: array, callback_id: int, node_id: int}|null
     */
    public static function drainJumpCompletion(): ?array
    {
        $dir = static::directory('complete');
        if (! is_dir($dir)) {
            return null;
        }

        $files = glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [];
        if ($files === []) {
            return null;
        }

        // Oldest first, so completions surface in the order they finished.
        sort($files);
        $file = $files[0];

        $raw = @file_get_contents($file);
        @unlink($file);

        $decoded = $raw ? json_decode($raw, true) : null;
        if (! is_array($decoded) || ! isset($decoded['event'])) {
            return null;
        }

        return [
            'type' => 20, // NativeComponent::EVENT_NATIVE
            'event' => $decoded['event'],
            'payload' => $decoded['payload'] ?? [],
            'callback_id' => 0,
            'node_id' => 0,
        ];
    }

    protected static function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
}
