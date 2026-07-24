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
    protected string $id;

    /** @var array{kind: string, ...} */
    protected array $work;

    protected ?Closure $finishedCallback = null;

    protected ?Closure $failedCallback = null;

    protected ?string $sharedAlias = null;

    protected bool $started = false;

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
        $origin = $this->sharedAlias === null
            ? (($active = NativeComponent::active()) !== null ? spl_object_id($active) : null)
            : null;
        AsyncTaskRegistry::register($this->id, $origin, $this->sharedAlias);

        // Register the completion callbacks so they survive the round trip
        // (and a process bounce) and can be resolved when the event arrives.
        if ($this->finishedCallback !== null) {
            NativeCallbacks::register($this->id, AsyncTaskFinished::class, $this->finishedCallback);
        }
        if ($this->failedCallback !== null) {
            NativeCallbacks::register($this->id, AsyncTaskFailed::class, $this->failedCallback);
        }

        try {
            $payload = serialize($this->work);
        } catch (Throwable $e) {
            // A non-serializable capture (resource, PDO handle, bound object).
            AsyncTaskRegistry::forget($this->id);
            NativeCallbacks::forget($this->id);
            throw new \InvalidArgumentException(
                'The async work could not be serialized: '.$e->getMessage().' '
                .'Static closures may only capture serializable values.',
                0,
                $e
            );
        }

        AsyncTaskTransport::dispatch($this->id, $payload);

        return true;
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
            $result = AsyncTaskRunner::invoke($this->work);
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
     */
    public function __destruct()
    {
        if (! $this->started) {
            $this->start();
        }
    }
}
