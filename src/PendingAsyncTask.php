<?php

namespace Native\Mobile;

use Closure;
use Illuminate\Support\Str;
use Laravel\SerializableClosure\SerializableClosure;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Async\AsyncTaskFailed;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Exceptions\AsyncTaskException;
use Native\Mobile\Support\AsyncTaskRegistry;
use Native\Mobile\Support\AsyncTaskRunner;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\Support\NativeCallbacks;
use ReflectionFunction;
use Throwable;

/**
 * Fluent handle for a dispatched {@see AsyncTask}. Collects the completion
 * callbacks, then starts the task — the task begins when this object is
 * destroyed at the end of the dispatch statement (mirroring the Pending*
 * builders), or when `start()` is called explicitly.
 *
 * ```php
 * AsyncTask::dispatch(static fn () => slow())
 *     ->finished(fn ($result) => $this->value = $result)   // component-scoped
 *     ->failed(fn (\Throwable $e) => $this->error = $e->getMessage());
 *
 * // Or, to react no matter which screen is showing (shared chrome, mini-player):
 * AsyncTask::dispatch(static fn () => slow())->shared('value-ready');
 * // ...handled anywhere via #[On('value-ready')] or ->on('value-ready', ...).
 * ```
 */
class PendingAsyncTask
{
    /** Default watchdog window for a dispatched task, in seconds. */
    public const DEFAULT_TIMEOUT = 60;

    protected string $id;

    /** @var array{kind: string, ...} */
    protected array $work;

    protected ?Closure $finishedCallback = null;

    protected ?Closure $failedCallback = null;

    protected ?string $sharedAlias = null;

    protected bool $started = false;

    /**
     * Seconds the task may run before the background lane is declared hung and
     * `->failed()` fires with a timeout. 0 disables the watchdog.
     */
    protected int $timeout = self::DEFAULT_TIMEOUT;

    protected function __construct(array $work)
    {
        $this->id = (string) Str::uuid();
        $this->work = $work;
    }

    /**
     * Build a pending task from a user closure. The closure must be static —
     * it runs in a separate interpreter, so a `$this`-bound closure can't cross
     * and is rejected here, in the handler, rather than failing silently later.
     */
    public static function forClosure(Closure $work): self
    {
        if ((new ReflectionFunction($work))->getClosureThis() !== null) {
            throw new \InvalidArgumentException(
                'The async work closure must be static (declare it `static function () { ... }`). '
                .'It runs on a separate PHP thread and cannot capture $this or share component state. '
                .'Use ->finished() to update state back on the UI thread.'
            );
        }

        return new self(['kind' => 'closure', 'closure' => new SerializableClosure($work)]);
    }

    /**
     * Build a pending task from an {@see AsyncTask} subclass. The subclass is
     * instantiated fresh in the background context and its `handle(...$args)`
     * invoked there; `$args` must be serializable.
     *
     * @param  class-string  $taskClass
     */
    public static function forTask(string $taskClass, array $args): self
    {
        // Same reasoning as the static-closure guard: fail in the handler where
        // the developer can see it, not as a generic ->failed() from a
        // background thread whose only symptom is "Call to undefined method".
        if (! method_exists($taskClass, 'handle')) {
            throw new \InvalidArgumentException(
                "[{$taskClass}] must declare a handle() method to be dispatched as an async task. "
                .'Its arguments are forwarded to handle() in the background context.'
            );
        }

        return new self(['kind' => 'task', 'task' => $taskClass, 'args' => array_values($args)]);
    }

    public function getId(): string
    {
        return $this->id;
    }

    // ── Completion callbacks ────────────────────────

    /**
     * Run `$callback($result)` on the UI thread when the task completes,
     * rebound to the live component. Dropped if the originating screen is no
     * longer topmost — see `shared()` to opt out of that.
     */
    public function finished(Closure $callback): static
    {
        $this->finishedCallback = $callback;

        return $this;
    }

    /**
     * Run `$callback($exception)` on the UI thread if the task throws. The
     * argument is an {@see AsyncTaskException} carrying the original message and
     * class. Same screen-scoping as `finished()`.
     */
    public function failed(Closure $callback): static
    {
        $this->failedCallback = $callback;

        return $this;
    }

    /**
     * Deliver the result as a named global event instead of a component-scoped
     * callback, so it's handled no matter which screen is active (useful for a
     * component whose UI is shared across screens). Handle it anywhere with
     * `#[On($alias)]` or `->on($alias, ...)`. The event payload carries the
     * task `id`, a `status` ('finished'|'failed'), and either `result` or the
     * failure fields.
     */
    public function shared(string $alias): static
    {
        $this->sharedAlias = $alias;

        return $this;
    }

    /**
     * How long the task may run before it's treated as hung, in seconds
     * (default {@see DEFAULT_TIMEOUT}). When the deadline passes with nothing
     * delivered, `->failed()` fires with a timeout {@see AsyncTaskException} so
     * the UI can stop waiting. Pass 0 for a task that may legitimately run for
     * as long as it likes — nothing will ever un-stick its spinner.
     *
     * The work itself is NOT killed on device (a PHP interpreter mid-task can't
     * be interrupted safely); the timeout unblocks the UI, and a late completion
     * for a task already reported as failed is discarded.
     */
    public function timeout(int $seconds): static
    {
        $this->timeout = max(0, $seconds);

        return $this;
    }

    // ── Lifecycle ───────────────────────────────────

    /**
     * Start the task. Idempotent. Normally called automatically on destruct so
     * chained `finished()`/`failed()` are registered first.
     */
    public function start(): bool
    {
        if ($this->started) {
            return false;
        }

        $this->started = true;

        if (AsyncTask::isFaked()) {
            return $this->startFaked();
        }

        // Scope this task to the component that dispatched it, unless shared.
        $origin = $this->sharedAlias === null ? NativeComponent::active() : null;
        AsyncTaskRegistry::register($this->id, $origin, $this->sharedAlias);

        // Register the completion callbacks so they can be resolved when the
        // event arrives. Memory-only (durable: false): the task payload and its
        // scope metadata are RAM/temp-file bound anyway, so a process kill loses
        // the task regardless — a durable cache copy would only buy a SQLite
        // write per dispatch on the UI thread with nothing to survive to.
        if ($this->finishedCallback !== null) {
            NativeCallbacks::register($this->id, AsyncTaskFinished::class, $this->finishedCallback, durable: false);
        }
        if ($this->failedCallback !== null) {
            NativeCallbacks::register($this->id, AsyncTaskFailed::class, $this->failedCallback, durable: false);
        }

        try {
            $payload = serialize($this->work);
        } catch (Throwable $e) {
            // A capture PHP refuses outright — an unserializable object, a
            // Closure that isn't wrapped. Note this does NOT catch a captured
            // resource: PHP serializes those to i:0 without complaint, so a
            // task capturing a file or PDO handle fails inside the work with a
            // type error rather than here.
            AsyncTaskRegistry::forget($this->id);
            NativeCallbacks::forget($this->id);
            throw new \InvalidArgumentException(
                'The async work could not be serialized: '.$e->getMessage().' '
                .'Static closures may only capture serializable values.',
                0,
                $e
            );
        }

        // A refused dispatch (no executor, no free slot, no bridge) has to fail
        // HERE — nothing else will ever report on a task that never started.
        if (! AsyncTaskTransport::dispatch($this->id, $payload, $this->watchdog())) {
            $this->failLocally(
                'The async task could not be started — the background lane is not available.',
                'RuntimeException',
            );

            return false;
        }

        return true;
    }

    /**
     * The timeout contract handed to the background lane's watchdog: the
     * deadline plus the exact completion to post if it passes. Native holds
     * these verbatim and posts them as-is, so it never has to know how a
     * failure event is shaped.
     *
     * @return array{timeout: int, timeoutEvent: string, timeoutPayload: array}|array{}
     */
    protected function watchdog(): array
    {
        if ($this->timeout <= 0) {
            return [];
        }

        return [
            'timeout' => $this->timeout,
            'timeoutEvent' => AsyncTaskFailed::class,
            'timeoutPayload' => [
                'id' => $this->id,
                'exceptionClass' => 'RuntimeException',
                'message' => "The async task did not complete within {$this->timeout} seconds.",
                'trace' => null,
            ],
        ];
    }

    /**
     * Report a failure that happened on THIS side of the bridge — the task never
     * reached a background context, so no completion event is coming. Fires the
     * `failed()` callback directly (we're still on the UI thread, inside the
     * dispatching handler, so a state change here still paints).
     */
    protected function failLocally(string $message, string $exceptionClass): void
    {
        AsyncTaskRegistry::forget($this->id);
        NativeCallbacks::forget($this->id);

        $callback = $this->failedCallback;

        if ($callback === null) {
            // Nothing to tell; at least make it visible in the log rather than
            // letting the dispatch evaporate.
            error_log('[nativephp] async task '.$this->id.': '.$message);

            return;
        }

        $callback(new AsyncTaskException($message, $exceptionClass));
    }

    /**
     * Test path: run the work inline and invoke the callbacks directly, so
     * tests exercise the finished/failed flow without any threads or bridge.
     */
    protected function startFaked(): bool
    {
        $fake = AsyncTask::fakeInstance();
        $fake?->record($this->id, $this->work, $this->sharedAlias);

        try {
            // Round-trip the task ARGUMENTS the way the transport does, not just
            // the result: on a device the envelope crosses via a temp file, so
            // the task gets a deep copy of what it was handed. Running the
            // original array here would share those objects by identity and let
            // a test pass on mutations a device would never see.
            //
            // The closure is deliberately NOT round-tripped. Unserializing one
            // re-evaluates its source in the namespace ReflectionClosure reports
            // — which for a closure written in a Pest test file is Pest's
            // compiled namespace, not the global one it was written in, so an
            // unqualified `new RuntimeException` resolves somewhere that doesn't
            // exist. That's an artifact of re-evaluating a test-file closure in
            // this process, not something a device does, and reproducing it here
            // would fail tests over a problem real dispatches don't have.
            $work = $this->work;
            if (($work['kind'] ?? null) === 'task' && isset($work['args'])) {
                $work['args'] = unserialize(serialize($work['args']));
            }

            // Then round-trip the result exactly as the completion event would,
            // so a test can't pass on a value that wouldn't survive the hop.
            $result = AsyncTaskRunner::normalizeResult(AsyncTaskRunner::invoke($work));
            if ($this->finishedCallback !== null) {
                ($this->finishedCallback)($result);
            }
        } catch (Throwable $e) {
            if ($this->failedCallback !== null) {
                ($this->failedCallback)(new AsyncTaskException($e->getMessage(), $e::class, $e->getTraceAsString()));
            }
        }

        return true;
    }

    /**
     * The work envelope — exposed for the test fake and assertions.
     *
     * @return array{kind: string, ...}
     */
    public function work(): array
    {
        return $this->work;
    }

    public function sharedAlias(): ?string
    {
        return $this->sharedAlias;
    }

    /**
     * Auto-start when the fluent chain falls out of scope, matching the other
     * Pending* builders. An explicit `start()` disables this (idempotent).
     *
     * Nothing may escape here: a destructor runs at whatever point the refcount
     * drops — mid-statement, during GC, or while PHP is already unwinding
     * another exception — and a throw from there surfaces nowhere near the
     * dispatch site (and is fatal during shutdown). So a start failure is
     * reported through the task's own `failed()` channel and the error log
     * instead. Call `start()` explicitly if you want the exception.
     */
    public function __destruct()
    {
        if ($this->started) {
            return;
        }

        try {
            $this->start();
        } catch (Throwable $e) {
            try {
                $this->failLocally($e->getMessage(), $e::class);
            } catch (Throwable) {
                // Reporting the failure must not mask the original either.
            }

            error_log('[nativephp] async task '.$this->id.' failed to start: '.$e->getMessage());
        }
    }
}
