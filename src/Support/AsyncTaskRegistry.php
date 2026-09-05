<?php

namespace Native\Mobile\Support;

/**
 * Per-task scope metadata for async tasks, held in the persistent runtime's
 * static memory (survives between dispatches, like {@see NativeCallbacks}).
 *
 * Records, keyed by task id:
 *   - `origin`  — a WEAK reference to the component that dispatched the task,
 *                 used to DROP a `finished()`/`failed()` callback when the user
 *                 has navigated away and that screen is no longer topmost. Weak
 *                 rather than an object id, because ids are recycled: a popped
 *                 screen's id can be handed to the next component allocated, and
 *                 an id comparison would then deliver the callback to a
 *                 completely unrelated screen. A weak reference to a freed
 *                 component reads back as null, which is never `=== $this`.
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
    /** @var array<string, array{origin: \WeakReference<object>|null, shared: string|null}> */
    protected static array $scopes = [];

    public static function register(string $id, ?object $origin, ?string $shared = null): void
    {
        static::$scopes[$id] = [
            'origin' => $origin !== null ? \WeakReference::create($origin) : null,
            'shared' => $shared,
        ];
    }

    /**
     * @return array{origin: \WeakReference<object>|null, shared: string|null}|null
     */
    public static function scope(string $id): ?array
    {
        return static::$scopes[$id] ?? null;
    }

    /**
     * The component that dispatched a task, or null when it was dispatched
     * outside a component, is unknown, or has since been freed.
     */
    public static function origin(string $id): ?object
    {
        return (static::$scopes[$id]['origin'] ?? null)?->get();
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
