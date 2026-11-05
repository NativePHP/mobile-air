<?php

namespace Native\Mobile\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
 *      The durable copy is signed with the app key before it is written and
 *      verified before it is read back. resolve() runs the result event that
 *      reads this copy, and JavaScript in a web view can reach that event, so
 *      the serialized bytes are never handed to unserialize() — which runs the
 *      code of whatever class they name — until an HMAC the app key made over
 *      them has matched. A value that is not a signed envelope this app wrote
 *      is dropped; the in-memory copy still stands for this process. This
 *      mirrors how {@see GlobalEventTransport} signs an event across lanes.
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

    /**
     * Written in front of the serialized bytes before they are signed, and
     * checked after. The app key signs other things too (cookies, the
     * `encrypted` model cast, events that cross lanes), so a signature made
     * for one of those must not pass as a callback, and vice versa.
     */
    protected const SIGNATURE_PURPOSE = 'nativephp.native-callback:';

    /** @var array<string, true> What this interpreter has warned about, each of which it logs once. */
    protected static array $warned = [];

    /**
     * @param  bool  $durable  Whether to keep the tier-2 copy. Pass false for a
     *                         callback whose pending operation cannot itself
     *                         survive a process kill (async tasks hold their work
     *                         in RAM and a temp file), so the durable copy would
     *                         cost a cache write with nothing to survive to.
     */
    public static function register(string $id, string $eventClass, Closure|array|string $callback, bool $durable = true): void
    {
        // Tier 1: always available for this process, no serialization constraints.
        static::$memory[$id][$eventClass] = $callback;

        if (! $durable) {
            return;
        }

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

            $key = static::signingKey();

            // resolve() reads this copy back with unserialize(), so nothing is
            // written that the app key cannot later prove this app wrote. With
            // no key to sign with, the durable copy is skipped; the in-memory
            // copy still stands for this process.
            if ($key === null) {
                static::warnOnce('key', 'A native callback was not kept in the durable cache: the app has no usable app key to sign it with, and an unsigned copy is never read back. It survives in memory for this process only. This is logged once.');

                return;
            }

            $serialized = serialize($serializable);

            // Store the serialized bytes next to an HMAC the app key made over
            // them. base64 keeps the bytes out of a text column unharmed and
            // mirrors the envelope GlobalEventTransport sends across lanes.
            Cache::put(
                static::key($id, $eventClass),
                ['data' => base64_encode($serialized), 'sig' => static::sign($serialized, $key)],
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

        $cacheKey = static::key($id, $eventClass);
        $envelope = $consume ? Cache::pull($cacheKey) : Cache::get($cacheKey);

        if ($envelope === null) {
            return null;
        }

        $serialized = static::verified($envelope);

        if ($serialized === null) {
            return null;
        }

        $restored = unserialize($serialized);

        return $restored instanceof SerializableClosure
            ? $restored->getClosure()
            : $restored;
    }

    /**
     * Take the serialized callback bytes out of a durable envelope, once the
     * app key has proven this app wrote them. Null — and the bytes are never
     * unserialized — when the value is not a signed envelope (a legacy bare
     * string lands here too), when its signature was not made with this app
     * key, or when there is no app key to check it with.
     */
    protected static function verified(mixed $envelope): ?string
    {
        $data = is_array($envelope) ? ($envelope['data'] ?? null) : null;
        $sig = is_array($envelope) ? ($envelope['sig'] ?? null) : null;

        if (! is_string($data) || ! is_string($sig)) {
            static::warnOnce('shape', 'A durable native callback copy was dropped unread: it was not a signed envelope this app wrote. Any in-memory copy still stands for this process. This is logged once.');

            return null;
        }

        $serialized = base64_decode($data, true);

        if ($serialized === false) {
            return null;
        }

        $key = static::signingKey();

        if ($key === null) {
            static::warnOnce('unchecked', 'A durable native callback copy was dropped unread: the app has no usable app key to check its signature with. This is logged once.');

            return null;
        }

        // JavaScript in a web view can reach the result event that reads this
        // copy, and unserialize() runs the code of whatever class the bytes
        // name. So the bytes are returned only once the signature matches.
        if (! hash_equals(static::sign($serialized, $key), $sig)) {
            static::warnOnce('signature', 'A durable native callback copy was dropped unread: its signature was not made with this app key. This is logged once.');

            return null;
        }

        return $serialized;
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
     * Drop every in-memory pending callback, and forget what this interpreter
     * has warned about. The static tier survives across tests in one process,
     * so the testing harness flushes it at the start of each test for
     * isolation. Durable cache copies belong to the per-test application and
     * reset with it.
     */
    public static function flush(): void
    {
        static::$memory = [];
        static::$warned = [];
    }

    protected static function key(string $id, string $eventClass): string
    {
        return 'native_cb:'.$id.':'.$eventClass;
    }

    /**
     * The app key as Laravel's encrypter holds it, so the `base64:` form is
     * read by the framework. The current key alone, never a previous one.
     * Null when the app has no usable key. Named apart from key() above,
     * which builds the cache key for an entry.
     */
    protected static function signingKey(): ?string
    {
        try {
            $key = app('encrypter')->getKey();
        } catch (Throwable) {
            return null;
        }

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** The signature of the serialized callback bytes: their HMAC-SHA256 under the app key, in hex, bound to this transport's purpose. */
    protected static function sign(string $serialized, string $key): string
    {
        return hash_hmac('sha256', static::SIGNATURE_PURPOSE.$serialized, $key);
    }

    /**
     * Log a warning the first time this interpreter has it to give, and never
     * again after that. A screen that keeps asking native for a result, or a
     * page that keeps posting one, would otherwise fill the log with one line
     * for every attempt.
     */
    protected static function warnOnce(string $about, string $message): void
    {
        if (isset(static::$warned[$about])) {
            return;
        }

        static::$warned[$about] = true;

        Log::warning($message);
    }
}
