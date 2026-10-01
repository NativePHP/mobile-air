<?php

namespace Native\Mobile\Edge\Concerns;

use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Events\Async\AsyncTaskFailed;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Exceptions\AsyncTaskException;
use Native\Mobile\PendingAsyncTask;
use Native\Mobile\Support\AsyncTaskRegistry;
use Native\Mobile\Support\NativeCallbacks;

/**
 * Background PHP work whose result comes back to the screen that asked for it.
 *
 * The async lane is a mobile shell feature: the work runs on a separate PHP
 * interpreter the native side boots, and its completion re-enters the runloop
 * as a native event. Core's runloop only asks `handlePlatformNativeEvent()`
 * whether anyone owns an event, and this answers for the two async ones.
 *
 * Also tracks which component is driving the runloop, because a completion
 * must only fire against the screen that dispatched it.
 */
trait RunsAsyncTasks
{
    /**
     * The component currently driving the runloop (rendering or handling an
     * event). Async tasks capture this at dispatch time so a completion arriving
     * after the user has navigated away can be dropped instead of firing against
     * the wrong live component. Only ever one component runs at a time.
     *
     * Held WEAKLY, and restored to its previous value when a runloop exits: a
     * strong static would keep the last screen a user ever visited — and its
     * whole state graph — alive for the life of the process.
     *
     * @var \WeakReference<self>|null
     */
    private static ?\WeakReference $nativeActiveComponent = null;

    /**
     * The component currently driving the runloop, or null when none is active
     * (or the last active one has since been freed). Used by
     * {@see PendingAsyncTask} to scope async completion callbacks to the screen
     * that dispatched them.
     */
    public static function active(): ?self
    {
        return self::$nativeActiveComponent?->get();
    }

    /**
     * Mark this component as the one driving the runloop, returning whatever was
     * active before so a nested runloop can hand the baton back on the way out.
     *
     * @internal Public only so {@see NativeRouter} can cover the mount() it runs
     *           before handing over to runLoop() — a task dispatched from mount()
     *           must scope to the screen being mounted, not the one it replaced.
     *
     * @return \WeakReference<self>|null
     */
    public static function markActive(self $component): ?\WeakReference
    {
        $previous = self::$nativeActiveComponent;
        self::$nativeActiveComponent = \WeakReference::create($component);

        return $previous;
    }

    /**
     * @internal Pairs with {@see markActive()}.
     *
     * @param  \WeakReference<self>|null  $previous
     */
    public static function restoreActive(?\WeakReference $previous): void
    {
        self::$nativeActiveComponent = $previous;
    }

    /**
     * Dispatch background work on a separate PHP thread and handle the result
     * back on this component — sugar for {@see AsyncTask::dispatch()} that reads
     * naturally inside a handler:
     *
     *     $this->async(static fn () => ExpensiveReport::build()->toArray())
     *         ->finished(fn ($result) => $this->report = $result);
     *
     * The work MUST be a static closure — it runs in another interpreter and
     * cannot capture `$this`. Mutate state from `->finished()`/`->failed()`.
     * `->failed()` also covers a task that never started or outran its timeout,
     * so a spinner started here always has something to switch it off.
     */
    protected function async(\Closure $work): PendingAsyncTask
    {
        return AsyncTask::dispatch($work);
    }

    /**
     * Async task completion (AsyncTask::dispatch()->finished()/failed()).
     * Claimed before the generic callback path because it carries its own
     * screen-scoping and shared-alias delivery policy.
     */
    protected function handleAsyncCompletionEvent(string $eventName, mixed $payload): bool
    {
        $asyncClass = str_starts_with($eventName, 'native:') ? substr($eventName, 7) : $eventName;

        if ($asyncClass !== AsyncTaskFinished::class && $asyncClass !== AsyncTaskFailed::class) {
            return false;
        }

        $this->handleAsyncCompletion($asyncClass, is_array($payload) ? $payload : []);

        return true;
    }

    /**
     * Handle an async task completion (finished or failed).
     *
     * Delivery policy:
     *   - shared('alias')  → re-dispatch as the named event so any active
     *     component's #[On('alias')] / ->on('alias') handles it, no matter which
     *     screen is showing. No origin-screen check.
     *   - otherwise (scoped) → DROP if the screen that dispatched the task is no
     *     longer topmost (firing a component-bound callback against the wrong
     *     live component is a footgun). Else run the finished()/failed() callback,
     *     rebound to this live component, with the raw result / an
     *     AsyncTaskException.
     */
    private function handleAsyncCompletion(string $eventClass, array $payload): void
    {
        $id = $payload['id'] ?? null;
        if (! is_string($id) || $id === '') {
            return;
        }

        $failed = $eventClass === AsyncTaskFailed::class;
        $scope = AsyncTaskRegistry::scope($id);

        // Shared: deliver as a named event to whatever component is active.
        if ($scope !== null && $scope['shared'] !== null) {
            $status = $failed ? 'failed' : 'finished';
            $this->dispatchNativeEvent([
                'event' => $scope['shared'],
                'payload' => $payload + ['status' => $status],
            ]);
            AsyncTaskRegistry::forget($id);
            NativeCallbacks::forget($id);

            return;
        }

        // Scoped: drop if the originating screen has been left. The weak
        // reference reads back as null once that component has been freed, which
        // is never === $this — so a recycled object id can't smuggle a callback
        // onto an unrelated screen that happens to sit at the same address.
        if ($scope !== null && $scope['origin'] !== null && $scope['origin']->get() !== $this) {
            NativeRouter::debugLog("async {$id}: dropped completion — origin screen no longer topmost");
            AsyncTaskRegistry::forget($id);
            NativeCallbacks::forget($id);

            return;
        }

        $callback = NativeCallbacks::resolve($id, $eventClass);

        try {
            if ($callback === null) {
                return;
            }

            if (is_string($callback) && class_exists($callback)) {
                $callback = app($callback);
            }

            // Bind the closure to this live component so finished()/failed() can
            // mutate state via $this. Static closures run without $this.
            if ($callback instanceof \Closure && ! (new \ReflectionFunction($callback))->isStatic()) {
                $callback = \Closure::bind($callback, $this, static::class);
            }

            if ($failed) {
                $callback(new AsyncTaskException(
                    (string) ($payload['message'] ?? 'Async task failed.'),
                    (string) ($payload['exceptionClass'] ?? 'Exception'),
                    $payload['trace'] ?? null,
                ));
            } else {
                $callback($payload['result'] ?? null);
            }
        } finally {
            // One outcome per task — drop the finished/failed sibling too.
            NativeCallbacks::forget($id);
            AsyncTaskRegistry::forget($id);
        }
    }
}
