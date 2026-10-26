<?php

namespace Native\Mobile\Support;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
 * the app's filesystem, so no native RAM courier is needed. The file is sealed
 * with the app key ({@see seal()}), because the runner unserializes what it
 * reads and anything that can write to the spool, or call the bridge with an
 * id of its choosing, could otherwise have the app run it. Completion, though,
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
    /**
     * Written in front of a work envelope before it is encrypted, and checked
     * after it is decrypted. The app key encrypts other things too (cookies,
     * `encrypted` model casts), so a ciphertext made for one of those must not
     * open as a task. The task id goes in as well, so a sealed payload copied
     * to another task's file doesn't open either.
     */
    protected const SEAL_PURPOSE = 'nativephp.async-task:';

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
     *
     * @throws \RuntimeException when the app has no usable app key to seal the payload with
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
        static::writeSpoolFile($dir.DIRECTORY_SEPARATOR.$id.'.task', static::seal($id, $payload));
    }

    /**
     * Encrypt a work envelope under the app key with Laravel's encrypter, which
     * also signs it (a MAC, or the GCM tag), so {@see unseal()} can tell the
     * file was written by this app and hasn't been touched since.
     *
     * Every lane boots with the same key: on a device the shell hands it over
     * from the Keychain/Keystore, and under Jump both processes read it from
     * the project's .env.
     */
    protected static function seal(string $id, string $payload): string
    {
        return static::encrypter()->encryptString(static::SEAL_PURPOSE.$id."\n".$payload);
    }

    /**
     * Decrypt a sealed work envelope, or return null when it doesn't open: not
     * encrypted under this app's key, changed since, made for something else,
     * or sealed for a different task.
     */
    protected static function unseal(string $id, string $sealed): ?string
    {
        try {
            $plain = static::encrypter()->decryptString($sealed);
        } catch (\Throwable) {
            return null;
        }

        $prefix = static::SEAL_PURPOSE.$id."\n";

        if (! str_starts_with($plain, $prefix)) {
            return null;
        }

        return substr($plain, strlen($prefix));
    }

    /**
     * An encrypter for the current app key only. The app's own encrypter also
     * accepts previous keys when decrypting (Laravel 11+), and a task is always
     * sealed and opened within one run of the app, so it never needs them.
     *
     * @throws \RuntimeException when the app has no usable app key
     */
    protected static function encrypter(): Encrypter
    {
        try {
            $key = app('encrypter')->getKey();

            return new Encrypter($key, (string) config('app.cipher', 'AES-256-CBC'));
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Async tasks need an app key: the task payload is sealed with it, and the background lane '
                .'runs nothing it cannot open. ('.$e->getMessage().')',
                0,
                $e,
            );
        }
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
            // Output stays enabled (this is the dev machine, and the volume is
            // a task's own echo at most): when a runner dies without spooling,
            // its stderr is the only thing that says why. disableOutput() would
            // also make getErrorOutput() throw at exactly that moment.
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
            // loop ticking until the spool is actually empty. A runner that
            // died without spooling gets its failure written here, so dropping
            // the entry can't drop the task's only chance of an outcome.
            unset(static::$jumpProcesses[$id]);
            static::failIfDiedSilently($id, $entry);
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
                static::failIfDiedSilently($id, $entry);

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

    /**
     * A runner that exited without spooling anything delivered no outcome, and
     * dropping its entry drops its deadline with it — so nothing would ever
     * report on the task. That's the dev loop's most likely failure: a parse
     * error from a half-saved file, a fatal during bootstrap, an OOM, a SIGKILL.
     * Synthesize the failure here instead, carrying the exit code so the cause
     * is visible rather than a generic timeout 60 seconds later.
     *
     * @param  array{process: Process, deadline: float|null, event: string, payload: array}  $entry
     */
    protected static function failIfDiedSilently(string $id, array $entry): void
    {
        // It completed normally — its own outcome is already spooled (or has
        // been drained already), so there's nothing to report.
        if (is_file(static::directory('complete').DIRECTORY_SEPARATOR.$id.'.json')) {
            return;
        }

        // A payload still on disk means the runner never got as far as reading
        // it; if it's gone, the runner read it and then died before completing.
        // Either way no completion is coming.
        static::forgetPayload($id);

        $exitCode = $entry['process']->getExitCode();

        // Whatever the runner managed to say on its way down — a parse error, a
        // fatal, an OOM notice — is the actual diagnosis.
        $stderr = '';
        try {
            $stderr = trim($entry['process']->getErrorOutput() ?: $entry['process']->getOutput());
        } catch (\Throwable) {
            // Output was disabled or the pipes are gone; the exit code stands alone.
        }

        if ($stderr !== '') {
            $stderr = ' '.mb_substr(preg_replace('/\s+/', ' ', $stderr) ?? '', -500);
        }

        static::writeCompletionSpool($id, AsyncTaskFailed::class, [
            'id' => $id,
            'exceptionClass' => 'RuntimeException',
            'message' => 'The async task runner exited without reporting a result'
                .($exitCode === null ? '.' : " (exit code {$exitCode}).")
                .$stderr,
            'trace' => null,
        ]);
    }

    // ── Runner side (background context) ────────────

    /**
     * Read and remove the work payload for a task id. Null when there is none,
     * or when the file there wasn't sealed by this app for this task: the
     * runner unserializes what this returns, and unserialize() runs the code of
     * whatever classes the bytes name, so nothing unverified gets that far.
     */
    public static function readPayload(string $id): ?string
    {
        // The id becomes a path, and native takes it from any bridge call, so
        // only the ids PendingAsyncTask makes are looked up at all.
        if (! Str::isUuid($id)) {
            return null;
        }

        $path = static::directory('payload').DIRECTORY_SEPARATOR.$id.'.task';

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        @unlink($path);

        if ($contents === false) {
            return null;
        }

        $payload = static::unseal($id, $contents);

        if ($payload === null) {
            Log::warning("An async task payload for [{$id}] was not sealed by this app for that task, and was dropped unread.");
        }

        return $payload;
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

        if (! function_exists('nativephp_call')) {
            return;
        }

        $result = nativephp_call('AsyncTask.Complete', static::encodeCompletion([
            'id' => $id,
            'event' => $event,
            'payload' => $payload,
        ], $id));

        // Native takes this call only from a thread of the async lane, as a
        // page in a web view can make it too. A refusal here means no UI
        // hears of the task until its watchdog fires, if it has one.
        $decoded = is_string($result) ? json_decode($result, true) : null;

        if (is_array($decoded) && ($decoded['success'] ?? null) === false) {
            Log::warning("Native refused the outcome of async task [{$id}]: ".($decoded['error'] ?? 'no reason given').'. The screen that dispatched it hears nothing until the task times out, if it has a timeout.');
        }
    }

    /**
     * Spool an event that a Jump runner fired, for the runloop of the dev
     * machine to drain as a native event, and answer whether it is in
     * the spool. On false nothing of it is left there for the loop.
     *
     * The loop takes the oldest file first, by its modification time, so
     * an event leaves ahead of the completion of its task, which is
     * written after it. Only within one second the name decides:
     * it sorts ahead of a task id, and in the order of firing.
     *
     * @internal
     */
    public static function spoolJumpEvent(string $event, array $payload): bool
    {
        $json = json_encode(['event' => $event, 'payload' => $payload]);

        if ($json === false) {
            return false;
        }

        $dir = static::directory('complete');
        static::ensureDir($dir);

        $path = $dir.DIRECTORY_SEPARATOR.sprintf('0-%020d-%s.json', hrtime(true), bin2hex(random_bytes(4)));

        // The loop may drain the spool at any moment, and it takes every
        // `*.json` there. So the event is written under a name that
        // it does not take, and gets its own once it is whole.
        $temporary = $path.'.tmp';

        // A write that fails says so in a warning, which Laravel throws
        // as an exception. A file that is not there with every byte
        // of the event, for whichever reason, was not written.
        try {
            static::writeSpoolFile($temporary, $json);

            clearstatcache(true, $temporary);

            $whole = is_file($temporary) && filesize($temporary) === strlen($json);
        } catch (\Throwable) {
            $whole = false;
        }

        // A rename puts the whole file in the spool in one step. From then
        // on the answer stands, whether the loop took the file or not.
        if ($whole && @rename($temporary, $path)) {
            return true;
        }

        @unlink($temporary);

        return false;
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
        // Task spool names are random UUIDs, so a plain sort() would order them
        // arbitrarily — the modification time is the only thing that actually
        // tracks completion order. Names break ties, since filemtime has
        // one-second granularity on some filesystems and two tasks can easily
        // land inside the same second. So can an event and the completion of
        // the task that fired it. That is why spoolJumpEvent() names an event
        // `0-<hrtime>-<random>`: it sorts ahead of every UUID, so the event
        // surfaces first, and events keep the order they were fired in.
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
     * The payload is sealed, so a planted or edited one is refused, but the
     * completion spool carries raw result data. On device this lives in
     * app-private storage anyway; under Jump it sits in the project's
     * storage/, so keep it off the group/other bits.
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
