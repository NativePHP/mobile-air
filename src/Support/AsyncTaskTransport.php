<?php

namespace Native\Mobile\Support;

use Native\Mobile\Events\Async\AsyncTaskFailed;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Symfony\Component\Process\Process;

/**
 * Moves an async task between the dispatching (UI) PHP context and the
 * background context that runs it, without a shared PHP heap and without
 * touching a database or the standard queue.
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
     *
     * Returns FALSE when the background lane refused the task (no executor, no
     * free slot, no bridge, subprocess failed to launch). The caller must treat
     * that as a failed dispatch and fire `->failed()` itself — a task that never
     * started can never complete, and silently dropping it leaves the UI waiting
     * on a result that isn't coming.
     *
     * @param  array{timeout: int, timeoutEvent: string, timeoutPayload: array}|array{}  $watchdog
     */
    public static function dispatch(string $id, string $payload, array $watchdog = []): bool
    {
        static::writePayload($id, $payload);

        if (static::trigger($id, $watchdog)) {
            return true;
        }

        // Nothing will read the payload now; don't leave it on disk.
        static::forgetPayload($id);

        return false;
    }

    protected static function writePayload(string $id, string $payload): void
    {
        $dir = static::directory('payload');
        static::ensureDir($dir);
        static::writeSpoolFile($dir.DIRECTORY_SEPARATOR.$id.'.task', $payload);
    }

    /** Drop a spooled payload for a task that will never run. */
    public static function forgetPayload(string $id): void
    {
        @unlink(static::directory('payload').DIRECTORY_SEPARATOR.$id.'.task');
    }

    /**
     * Ask the async lane (device) or a subprocess (Jump) to run the task.
     * Returns whether the task was accepted.
     *
     * @param  array{timeout: int, timeoutEvent: string, timeoutPayload: array}|array{}  $watchdog
     */
    protected static function trigger(string $id, array $watchdog = []): bool
    {
        if (static::isJump()) {
            return static::spawnJumpRunner($id, $watchdog);
        }

        if (! function_exists('nativephp_call')) {
            return false;
        }

        // The watchdog travels with the dispatch: native holds the deadline and,
        // if it passes with nothing delivered, posts the pre-built failure event
        // back into the runloop. Native never builds the payload itself — it
        // stays the dumb courier the design calls for.
        $params = json_encode(['id' => $id] + $watchdog);

        if ($params === false) {
            return false;
        }

        $result = nativephp_call('AsyncTask.Dispatch', $params);

        if (! is_string($result) || $result === '') {
            return false;
        }

        $decoded = json_decode($result, true);

        return is_array($decoded) && ($decoded['success'] ?? false) === true;
    }

    /**
     * In-flight Jump runners, keyed by task id.
     *
     * The parent Jump runloop is long-lived (the screen's whole session), so a
     * retained Process reference outlives the child's work. The deadline is kept
     * alongside it because there's no native watchdog on a dev machine — see
     * {@see sweepJumpTimeouts()}.
     *
     * @var array<string, array{process: Process, deadline: float|null, event: string, payload: array}>
     */
    protected static array $jumpProcesses = [];

    /**
     * Launch `php artisan native:async:run` detached on the dev machine.
     *
     * @param  array{timeout: int, timeoutEvent: string, timeoutPayload: array}|array{}  $watchdog
     */
    protected static function spawnJumpRunner(string $id, array $watchdog = []): bool
    {
        if (! class_exists(Process::class)) {
            return false;
        }

        try {
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
        } catch (\Throwable) {
            return false;
        }

        // Prune finished runners so the map doesn't grow without bound.
        static::$jumpProcesses = array_filter(
            static::$jumpProcesses,
            fn (array $entry) => $entry['process']->isRunning(),
        );

        $timeout = (int) ($watchdog['timeout'] ?? 0);

        static::$jumpProcesses[$id] = [
            'process' => $process,
            'deadline' => $timeout > 0 ? microtime(true) + $timeout : null,
            'event' => (string) ($watchdog['timeoutEvent'] ?? AsyncTaskFailed::class),
            'payload' => (array) ($watchdog['timeoutPayload'] ?? ['id' => $id]),
        ];

        return true;
    }

    /**
     * Whether any Jump runner is still in flight.
     *
     * The runloop needs this because nothing WAKES a blocked
     * `nativephp_element_wait_event()` when a completion spools. On device the
     * bridge posts an event that unblocks the wait; under Jump the spool is
     * passive, so a screen with no `#[Poll]` (timeout -1, block forever) would
     * never come back to look at it. See jump_bridge_functions.php.
     */
    public static function hasPendingJumpRunners(): bool
    {
        foreach (static::$jumpProcesses as $id => $entry) {
            if ($entry['process']->isRunning()) {
                return true;
            }

            // Finished, but its completion may not be drained yet — keep the
            // loop ticking until the spool is actually empty.
            unset(static::$jumpProcesses[$id]);
        }

        $dir = static::directory('complete');

        return is_dir($dir) && (glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: []) !== [];
    }

    /**
     * Fail any Jump runner that has outrun its deadline: stop the subprocess and
     * spool the timeout completion so the runloop delivers `->failed()` on the
     * next tick. The device equivalent is the native executor's watchdog.
     */
    protected static function sweepJumpTimeouts(): void
    {
        if (static::$jumpProcesses === []) {
            return;
        }

        $now = microtime(true);

        foreach (static::$jumpProcesses as $id => $entry) {
            if (! $entry['process']->isRunning()) {
                unset(static::$jumpProcesses[$id]);

                continue;
            }

            if ($entry['deadline'] === null || $entry['deadline'] > $now) {
                continue;
            }

            unset(static::$jumpProcesses[$id]);
            $entry['process']->stop(0);
            static::forgetPayload($id);
            static::writeCompletionSpool($id, $entry['event'], $entry['payload']);
        }
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
            nativephp_call('AsyncTask.Complete', static::encodeCompletion([
                'id' => $id,
                'event' => $event,
                'payload' => $payload,
            ], $id));
        }
    }

    protected static function writeCompletionSpool(string $id, string $event, array $payload): void
    {
        $dir = static::directory('complete');
        static::ensureDir($dir);
        static::writeSpoolFile(
            $dir.DIRECTORY_SEPARATOR.$id.'.json',
            static::encodeCompletion(['event' => $event, 'payload' => $payload], $id),
        );
    }

    /**
     * Encode a completion envelope, falling back to a failure envelope if the
     * data won't encode. A completion that can't be encoded would otherwise be
     * an empty string on the wire: nothing delivered, no callback, and a UI
     * waiting forever on a task it can see no outcome for.
     *
     * {@see AsyncTaskRunner::normalizeResult()} already rejects a non-encodable
     * result at source, so this is the belt to that braces — a message or trace
     * carrying invalid UTF-8 can still land here.
     */
    protected static function encodeCompletion(array $envelope, string $id): string
    {
        $json = json_encode($envelope);

        if ($json !== false) {
            return $json;
        }

        $fallback = json_encode([
            'id' => $id,
            'event' => AsyncTaskFailed::class,
            'payload' => [
                'id' => $id,
                'exceptionClass' => 'RuntimeException',
                'message' => 'The async task outcome could not be encoded for delivery ('.json_last_error_msg().').',
                'trace' => null,
            ],
        ]);

        // A plain ASCII envelope always encodes; the guard keeps PHPStan honest.
        return $fallback === false ? '' : $fallback;
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
        // Runners that have outrun their deadline spool a failure first, so a
        // hung task surfaces as ->failed() here rather than never completing.
        static::sweepJumpTimeouts();

        $dir = static::directory('complete');
        if (! is_dir($dir)) {
            return null;
        }

        $files = glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [];
        if ($files === []) {
            return null;
        }

        // Oldest first, so completions surface in the order they finished.
        // Spool names are random UUIDs, so a plain sort() would order them
        // arbitrarily — the modification time is the only thing that actually
        // tracks completion order. Names break ties, since filemtime has
        // one-second granularity on some filesystems and two tasks can easily
        // land inside the same second.
        clearstatcache();
        usort($files, fn ($a, $b) => [filemtime($a), $a] <=> [filemtime($b), $b]);
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

    /**
     * Create a spool directory owner-only.
     *
     * The payload is a PHP-serialized work envelope that the runner hands
     * straight to unserialize(). A closure is signed with the app key, so a
     * tampered one fails its signature — but a task subclass's constructor
     * arguments are not, and the completion spool carries raw result data. On
     * device this lives in app-private storage anyway; under Jump it sits in
     * the project's storage/, so keep it off the group/other bits.
     */
    protected static function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);

            return;
        }

        // Tighten a directory left behind by an earlier run (or an earlier
        // version of this code) that created it world-readable.
        if ((fileperms($dir) & 0077) !== 0) {
            @chmod($dir, 0700);
        }
    }

    /** Write a spool file owner-only. */
    protected static function writeSpoolFile(string $path, string $contents): void
    {
        file_put_contents($path, $contents, LOCK_EX);
        @chmod($path, 0600);
    }
}
