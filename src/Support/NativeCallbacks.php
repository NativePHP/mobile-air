<?php

namespace Native\Mobile\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;

/**
 * Registry that lets a native call register a callback in the request that
 * launches it (e.g. Camera::getPhoto()->photoTaken(...)) and have that callback fire
 * in the *separate* request that delivers the result (the POST to
 * /_native/api/events). See docs/native-callback-api-design.md.
 *
 * Two storage tiers:
 *
 *   1. In-memory static — survives the request boundary for free because the
 *      persistent PHP interpreter keeps statics alive between requests. Closures
 *      can capture anything. Lost if the OS kills the app (e.g. Android while the
 *      camera Activity is foregrounded).
 *
 *   2. Serialized cache (SQLite-backed by default) — survives process death.
 *      Requires the closure to be serializable; if it isn't we fall back to
 *      tier 1 only and report the exception so the tradeoff is visible.
 *
 * Callbacks are correlated by the `id` the Pending* builder already passes to
 * native and that comes back on the result event.
 */
class NativeCallbacks
{
    /**
     * id => [ eventClass => Closure|callable-array|class-string ]
     *
     * @var array<string, array<string, Closure|array|string>>
     */
    protected static array $memory = [];

    /** How long a pending callback may wait for its result before it's considered abandoned. */
    protected static int $ttlMinutes = 5;

    public static function register(string $id, string $eventClass, Closure|array|string $callback): void
    {
        // Tier 1: always available for this process, no serialization constraints.
        static::$memory[$id][$eventClass] = $callback;

        // Tier 2: best-effort durable copy so the callback survives a process kill.
        try {
            $serializable = $callback;

            if ($serializable instanceof Closure) {
                // A closure bound to an instance (e.g. one defined in a component
                // method that uses $this) can't be serialized: PHP won't let us
                // unbind $this, and the bound object isn't serializable. Such
                // callbacks are in-memory only — skip the durable copy quietly.
                // Use `static fn` for a callback that must survive an app kill.
                if ((new \ReflectionFunction($serializable))->getClosureThis() !== null) {
                    return;
                }

                $serializable = new SerializableClosure($serializable);
            }

            Cache::put(
                static::key($id, $eventClass),
                serialize($serializable),
                now()->addMinutes(static::$ttlMinutes),
            );
        } catch (Throwable $e) {
            // Closure captured something else unserializable (a resource, a PDO
            // handle, ...). Keep the in-memory copy; just won't survive a kill.
            report($e);
        }
    }

    /**
     * Resolve a callback. Checks the warm in-memory copy first, then the durable
     * copy. When $consume is true (default) the durable copy is removed (pull);
     * pass false to peek without consuming (the in-memory copy is never removed
     * here either way — call forget() for that).
     */
    public static function resolve(string $id, string $eventClass, bool $consume = true): Closure|array|string|null
    {
        if (isset(static::$memory[$id][$eventClass])) {
            return static::$memory[$id][$eventClass];
        }

        $key = static::key($id, $eventClass);
        $serialized = $consume ? Cache::pull($key) : Cache::get($key);

        if ($serialized === null) {
            return null;
        }

        $restored = unserialize($serialized);

        return $restored instanceof SerializableClosure
            ? $restored->getClosure()
            : $restored;
    }

    /**
     * Fallback correlation: find the single in-flight callback for an event class
     * when no usable id came back from native (some platforms drop it across a
     * lifecycle bounce). A given native operation — a photo, a gallery pick — is
     * modal and single-in-flight, so one pending callback for the event class is
     * unambiguous. Returns [id, callback] or null when there are zero matches.
     * If several are somehow pending, the most recent wins.
     *
     * @return array{0: string, 1: Closure|array|string}|null
     */
    public static function resolveByEvent(string $eventClass): ?array
    {
        $matchId = null;
        foreach (static::$memory as $id => $byEvent) {
            if (isset($byEvent[$eventClass])) {
                $matchId = $id; // keep scanning — last match is the most recent
            }
        }

        if ($matchId === null) {
            return null;
        }

        return [$matchId, static::$memory[$matchId][$eventClass]];
    }

    /**
     * Drop every pending callback for a capture. Called once an outcome fires,
     * since the success/cancel/denied events for one `id` are mutually exclusive.
     * Sibling durable entries we can't enumerate (process was killed) simply
     * expire via the TTL.
     */
    public static function forget(string $id, ?string $eventClass = null): void
    {
        foreach (array_keys(static::$memory[$id] ?? []) as $event) {
            Cache::forget(static::key($id, $event));
        }

        if ($eventClass !== null) {
            Cache::forget(static::key($id, $eventClass));
        }

        unset(static::$memory[$id]);
    }

    public static function has(string $id, string $eventClass): bool
    {
        return isset(static::$memory[$id][$eventClass])
            || Cache::has(static::key($id, $eventClass));
    }

    /**
     * Drop every in-memory pending callback. The static tier survives
     * across tests in one process, so the testing harness flushes it at
     * the start of each test for isolation. Durable cache copies belong
     * to the per-test application and reset with it.
     */
    public static function flush(): void
    {
        static::$memory = [];
    }

    protected static function key(string $id, string $eventClass): string
    {
        return 'native_cb:'.$id.':'.$eventClass;
    }
}
