<?php

namespace Native\Mobile;

use Closure;
use Native\Mobile\Testing\FakeAsyncTask;

/**
 * Run a static closure (or a subclass's `handle()`) on a background PHP thread
 * and receive the result in a component callback that can update UI state.
 *
 * ```php
 * AsyncTask::dispatch(static fn () => ExpensiveReport::build()->toArray())
 *     ->finished(fn (array $result) => $this->report = $result)
 *     ->failed(fn (\Throwable $e) => $this->error = $e->getMessage());
 * ```
 *
 * Or subclass it (Job-like, but NOT a queued job — this never touches the
 * standard queue or SQLite):
 *
 * ```php
 * class BuildReport extends AsyncTask
 * {
 *     public function handle(int $month): array { ... }
 * }
 *
 * BuildReport::dispatch($month)->finished(fn ($result) => $this->report = $result);
 * ```
 *
 * @see PendingAsyncTask
 * @see docs/async-task-design.md
 */
class AsyncTask
{
    protected static ?FakeAsyncTask $fake = null;

    /**
     * Dispatch a background task.
     *
     * On the base class, the first argument is the work: a **static** closure
     * (enforced — a closure bound to `$this` throws, since it can't cross the
     * thread boundary). On a subclass, the arguments are forwarded to the
     * subclass's `handle()` in the background context.
     */
    public static function dispatch(mixed ...$args): PendingAsyncTask
    {
        if (static::class === self::class) {
            $work = $args[0] ?? null;

            if (! $work instanceof Closure) {
                throw new \InvalidArgumentException(
                    'AsyncTask::dispatch() expects a static closure. To dispatch a task class, '
                    .'extend AsyncTask and call YourTask::dispatch(...$args).'
                );
            }

            return PendingAsyncTask::forClosure($work);
        }

        return PendingAsyncTask::forTask(static::class, $args);
    }

    // ── Testing ─────────────────────────────────────

    /**
     * Swap the async lane for an in-process fake that runs tasks inline and
     * records every dispatch. Returns the fake for assertions.
     */
    public static function fake(): FakeAsyncTask
    {
        return static::$fake = new FakeAsyncTask;
    }

    /** The active fake, or null when tasks run for real. */
    public static function fakeInstance(): ?FakeAsyncTask
    {
        return static::$fake;
    }

    public static function isFaked(): bool
    {
        return static::$fake !== null;
    }

    /** Restore real dispatch (test teardown). */
    public static function clearFake(): void
    {
        static::$fake = null;
    }
}
