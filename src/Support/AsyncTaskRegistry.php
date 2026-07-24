<?php

namespace Native\Mobile\Support;

/**
 * Per-task scope metadata for async tasks, held in the persistent runtime's
 * static memory (survives between dispatches, like {@see NativeCallbacks}).
 *
 * Records, keyed by task id:
 *   - `origin`  — spl_object_id of the component that dispatched the task, used
 *                 to DROP a `finished()`/`failed()` callback when the user has
 *                 navigated away and that screen is no longer topmost.
 *   - `shared`  — a named-event alias (from `->shared('alias')`); when set the
 *                 result is delivered as that named event to whatever component
 *                 is active, bypassing the origin-screen check entirely.
 *
 * This is intentionally RAM-only: the native side holds the task payload in a
 * temp file for the life of the task, so a process kill loses the whole task
 * anyway — there is nothing for durable scope metadata to survive to.
 */
class AsyncTaskRegistry
{
    /** @var array<string, array{origin: int|null, shared: string|null}> */
    protected static array $scopes = [];

    public static function register(string $id, ?int $origin, ?string $shared = null): void
    {
        static::$scopes[$id] = ['origin' => $origin, 'shared' => $shared];
    }

    /**
     * @return array{origin: int|null, shared: string|null}|null
     */
    public static function scope(string $id): ?array
    {
        return static::$scopes[$id] ?? null;
    }

    public static function forget(string $id): void
    {
        unset(static::$scopes[$id]);
    }

    /** Drop all scope metadata (test isolation). */
    public static function flush(): void
    {
        static::$scopes = [];
    }
}
