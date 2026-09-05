<?php

namespace Native\Mobile\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Laravel\SerializableClosure\SerializableClosure;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
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
 *      persistent PHP interpreter keeps statics alive between requests.
 *      Closures can capture anything. Lost if the OS kills the app.
 *
 *   2. Serialized cache — survives process death. $this-bound closures are
 *      rebound to a throwaway carrier before serializing (fire-time rebinds
 *      to the live component anyway), so the dominant fluent shape survives
 *      a kill too. Durable entries carry the registering component's class
 *      so a cold-started app on a different screen skips them instead of
 *      firing them against the wrong component.
 *
 * Durability is best-effort with explicit bail-outs (each debug-logged, not
 * report()-spammed): unserializable or resource-carrying captures, payloads
 * over the size cap, and two closures registered from the same file:line —
 * serializable-closure extracts code by start line, so one-line chains like
 * `->photoTaken(fn () => a())->photoCancelled(fn () => b())` would serialize
 * BOTH with the first closure's body; running a() on cancel after a process
 * kill is strictly worse than losing durability.
 *
 * Cache keys are session-scoped off-device: the stateless web target shares
 * one app-wide cache across users, and the documented fixed-id API
 * (->id('avatar')) would otherwise collide across sessions — user B's
 * registration overwriting user A's closure (with B's captured values) is
 * both a correctness bug and a data-leak vector. On device there is one
 * user; keys stay unscoped.
 *
 * Callbacks are correlated by the `id` the Pending* builder passes to
 * native. Some native paths drop the id across a lifecycle bounce, so
 * register() also maintains a per-event "latest id" index — the durable
 * tier's stand-in for scanning the (empty, post-kill) memory tier.
 */
class NativeCallbacks
{
    /**
     * id => [ eventClass => Closure|callable-array|class-string ]
     *
     * @var array<string, array<string, Closure|array|string>>
     */
    protected static array $memory = [];

    /**
     * file:line => [id, eventClass, registeredAt] for closures that went
     * through the same-line check, used to detect same-line registrations
     * (see class docblock). Per-process, which is sufficient: the
     * colliding registrations of a one-line chain always happen in one
     * request. The timestamp bounds detection: an entry is a conflation
     * suspect while its durable copy exists OR while it is younger than
     * the TTL — so a line-mate whose durable copy is ABSENT (size-capped,
     * or forgotten by an earlier collision) is still detected, while an
     * abandoned capture stops blocking the line once the TTL passes.
     *
     * @var array<string, array{0: string, 1: string, 2: int}>
     */
    protected static array $closureLines = [];

    /** How long a pending callback may wait for its result before it's considered abandoned. */
    protected static int $ttlMinutes = 5;

    /** Serialized payloads above this skip the durable tier (memory still fires). */
    protected static int $maxDurableBytes = 131072;

    public static function register(string $id, string $eventClass, Closure|array|string $callback): void
    {
        // Tier 1: always available for this process, no serialization constraints.
        static::$memory[$id][$eventClass] = $callback;

        // Tier 2: best-effort durable copy so the callback survives a process kill.
        try {
            $serializable = $callback;

            // Owner class rides the durable entry so a post-kill cold
            // start on a DIFFERENT screen skips it (resolve(for:)). For
            // closures it comes from the binding; for method-name strings
            // and [$component, 'method'] arrays — which would otherwise
            // fire a same-named method on whatever screen is live — from
            // the registering call stack.
            $ownerClass = null;

            if (is_string($callback)) {
                // ALL strings, including ones that shadow (or genuinely
                // name) loadable classes: 'error' must carry its owner so
                // a post-kill wrong-screen resolve skips it, and a real
                // invokable registered inside a component is still fired
                // by the Edge loop — the owner tag just routes it away
                // from the WebView controller.
                $ownerClass = static::callingComponentClass();
            } elseif (is_array($callback) && ($callback[0] ?? null) instanceof NativeComponent) {
                $ownerClass = get_class($callback[0]);
            }

            if ($serializable instanceof Closure) {
                $reflection = new \ReflectionFunction($serializable);
                $boundThis = $reflection->getClosureThis();

                if ($boundThis instanceof NativeComponent) {
                    $ownerClass = get_class($boundThis);
                }

                // Captured resources don't make serialize() throw — they
                // silently become ints, poisoning the durable copy with a
                // TypeError that only detonates after a process kill. A
                // capture graph too big to verify also skips durability
                // (fail closed), with an honest log for each case.
                if (($blocker = static::durabilityBlocker($reflection)) !== null) {
                    NativeRouter::debugLog("NativeCallbacks: '{$eventClass}' {$blocker} — durable copy skipped");

                    return;
                }

                if ($reflection->isAnonymous()) {
                    // serializable-closure extracts code by START LINE, so
                    // two anonymous closures registered from one line get
                    // the SAME body durably — running the success body on
                    // cancel after a kill. Drop durability for both — but
                    // ONLY while the other registration is still pending:
                    // builders default to fresh UUID ids, so a completed
                    // capture's mapping is stale, not a conflation, and
                    // treating it as one would permanently kill durability
                    // for that code line after its first use.
                    $line = $reflection->getFileName().':'.$reflection->getStartLine();
                    $existing = static::$closureLines[$line] ?? null;

                    // A line-mate is a conflation suspect while its durable
                    // copy exists OR while its registration is younger than
                    // the TTL — the timestamp half catches mates whose
                    // durable copy is absent (size-capped, or forgotten by
                    // an earlier collision on the same line). Completed
                    // captures are pruned by forget(); abandoned ones age
                    // out with the TTL instead of blocking forever.
                    if ($existing !== null && [$existing[0], $existing[1]] !== [$id, $eventClass]) {
                        [$otherId, $otherEvent, $registeredAt] = $existing;

                        if (Cache::has(static::key($otherId, $otherEvent)) || (now()->getTimestamp() - $registeredAt) < static::$ttlMinutes * 60) {
                            Cache::forget(static::key($otherId, $otherEvent));
                            NativeRouter::debugLog("NativeCallbacks: two pending closures share {$line} — durable copies dropped for both (split the chain across lines to restore durability)");

                            return;
                        }
                    }

                    static::$closureLines[$line] = [$id, $eventClass, now()->getTimestamp()];

                    if ($boundThis !== null) {
                        // The binding never needs to survive — fire-time
                        // rebinds to the live component. Rebind to a
                        // serializable carrier so only code + use-vars
                        // must cross.
                        $serializable = Closure::bind($serializable, new \stdClass);
                    }
                }
                // First-class callables ($this->onPicked(...)) are FAKE
                // closures: Closure::bind() on them returns null, but
                // serializable-closure stores them as a method reference,
                // so they serialize fine as-is — no carrier needed.

                $serializable = new SerializableClosure($serializable);
            }

            $payload = serialize(['o' => $ownerClass, 'c' => $serializable]);

            if (strlen($payload) > static::$maxDurableBytes) {
                NativeRouter::debugLog("NativeCallbacks: '{$eventClass}' durable payload is ".strlen($payload).' bytes (cap '.static::$maxDurableBytes.') — skipped; callback fires from memory only');

                return;
            }

            Cache::put(static::key($id, $eventClass), $payload, now()->addMinutes(static::$ttlMinutes));

            // Per-event latest-id index: after a kill the memory tier is
            // empty AND some native paths lose the id, so this is the only
            // way an id-less result event can find its durable copy.
            Cache::put(static::latestKey($eventClass), $id, now()->addMinutes(static::$ttlMinutes));
        } catch (Throwable $e) {
            // Closure captured something unserializable (a PDO handle, ...).
            // Keep the in-memory copy; just won't survive a kill. debugLog,
            // not report(): this fires per registration on hot paths.
            NativeRouter::debugLog("NativeCallbacks: '{$eventClass}' durable copy failed (".$e->getMessage().') — callback fires from memory only');
        }
    }

    /**
     * Resolve a callback. Checks the warm in-memory copy first, then the durable
     * copy. When $consume is true (default) the durable copy is removed (pull);
     * pass false to peek without consuming (the in-memory copy is never removed
     * here either way — call forget() for that).
     *
     * $for: the live component about to fire the callback. A durable entry
     * registered by a DIFFERENT component class is skipped (forgotten +
     * logged) instead of being rebound to the wrong screen — after a real
     * OS kill the app cold-starts at '/', and firing ScreenA's closure
     * against the home screen writes silent dynamic properties at best.
     */
    public static function resolve(string $id, string $eventClass, bool $consume = true, ?object $for = null): Closure|array|string|null
    {
        if (isset(static::$memory[$id][$eventClass])) {
            return static::$memory[$id][$eventClass];
        }

        $key = static::key($id, $eventClass);
        $serialized = $consume ? Cache::pull($key) : Cache::get($key);

        if ($serialized === null) {
            return null;
        }

        [$ownerClass, $restored] = static::readEntry($serialized);

        if ($ownerClass !== null && $for !== null && ! $for instanceof $ownerClass) {
            NativeRouter::debugLog("NativeCallbacks: durable callback for '{$eventClass}' belongs to {$ownerClass}, live screen is ".get_class($for).' — skipped');
            static::forget($id, $eventClass);

            return null;
        }

        return $restored instanceof SerializableClosure
            ? $restored->getClosure()
            : $restored;
    }

    /**
     * THE decoder for durable entries — resolve() and ownerOf() both go
     * through it, so the envelope shape can't drift between them.
     *
     * @return array{0: ?string, 1: mixed} [ownerClass, callback]
     */
    protected static function readEntry(string $serialized): array
    {
        $restored = unserialize($serialized);

        if (is_array($restored) && array_key_exists('c', $restored)) {
            return [$restored['o'] ?? null, $restored['c']];
        }

        return [null, $restored];
    }

    /**
     * Fallback correlation: find the single in-flight callback for an event class
     * when no usable id came back from native (some platforms drop it across a
     * lifecycle bounce). A given native operation — a photo, a gallery pick — is
     * modal and single-in-flight, so one pending callback for the event class is
     * unambiguous. Checks the in-memory tier first, then the durable
     * latest-id index (the memory tier is empty precisely in the
     * process-death case this fallback exists for). Returns [id, callback]
     * or null when there are zero matches.
     *
     * @return array{0: string, 1: Closure|array|string}|null
     */
    public static function resolveByEvent(string $eventClass, ?object $for = null): ?array
    {
        $matchId = null;
        foreach (static::$memory as $id => $byEvent) {
            if (isset($byEvent[$eventClass])) {
                $matchId = $id; // keep scanning — last match is the most recent
            }
        }

        if ($matchId !== null) {
            return [$matchId, static::$memory[$matchId][$eventClass]];
        }

        $latestId = Cache::get(static::latestKey($eventClass));

        if (! is_string($latestId) || $latestId === '') {
            return null;
        }

        $callback = static::resolve($latestId, $eventClass, consume: false, for: $for);

        return $callback === null ? null : [$latestId, $callback];
    }

    /**
     * The owner component class recorded on a durable entry, without
     * consuming it — the WebView controller's signal that a string
     * callback is component-owned (Edge-loop-only) even when its name
     * collides with an invokable class.
     */
    public static function ownerOf(string $id, string $eventClass): ?string
    {
        $serialized = Cache::get(static::key($id, $eventClass));

        return $serialized === null ? null : static::readEntry($serialized)[0];
    }

    /**
     * Re-key a pending registration to a different event class — the
     * ->event(Custom::class) override arriving AFTER onSuccess() already
     * registered under the builder's default event.
     */
    public static function retarget(string $id, string $fromEvent, string $toEvent): void
    {
        if ($fromEvent === $toEvent) {
            return;
        }

        if (isset(static::$memory[$id][$fromEvent])) {
            static::$memory[$id][$toEvent] = static::$memory[$id][$fromEvent];
            unset(static::$memory[$id][$fromEvent]);
        }

        $durable = Cache::pull(static::key($id, $fromEvent));

        if ($durable !== null) {
            Cache::put(static::key($id, $toEvent), $durable, now()->addMinutes(static::$ttlMinutes));
            Cache::put(static::latestKey($toEvent), $id, now()->addMinutes(static::$ttlMinutes));
        }

        // Keep the same-line bookkeeping pointing at the LIVE key, or the
        // collision handler would forget a dead key and leave the real
        // conflation-suspect durable copy intact.
        foreach (static::$closureLines as $line => $entry) {
            if ([$entry[0], $entry[1]] === [$id, $fromEvent]) {
                static::$closureLines[$line] = [$id, $toEvent, $entry[2]];
            }
        }
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

        // Prune same-line bookkeeping: a completed capture's mapping must
        // not poison durability for the next capture from that line.
        foreach (static::$closureLines as $line => $entry) {
            if ($entry[0] === $id) {
                unset(static::$closureLines[$line]);
            }
        }
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
        static::$closureLines = [];
    }

    protected static function key(string $id, string $eventClass): string
    {
        return 'native_cb:'.static::scope().$id.':'.$eventClass;
    }

    /** Per-event latest-id index key (see register()). */
    protected static function latestKey(string $eventClass): string
    {
        return 'native_cb_latest:'.static::scope().$eventClass;
    }

    /**
     * Session discriminator for cache keys OFF-DEVICE only. On device the
     * cache belongs to one user; on the stateless web target it's shared
     * app-wide, and unscoped keys would let one user's fixed-id
     * registration overwrite (and leak into) another's.
     */
    protected static function scope(): string
    {
        if (config('nativephp-internal.running')) {
            return '';
        }

        try {
            $sessionId = session()->getId();

            return is_string($sessionId) && $sessionId !== '' ? $sessionId.':' : '';
        } catch (Throwable) {
            return ''; // console / no session bound
        }
    }

    /**
     * Why a closure's captures can't be trusted in the durable tier, or
     * null when they can. Resources — open OR closed — serialize silently
     * into ints (no exception, just a TypeError detonating after the
     * process kill), so the scan walks arrays, object properties
     * ((array)-cast exposes private/protected too), and nested closures'
     * own captures.
     *
     * FAILS CLOSED when the walk is truncated (depth cap, or the visited-
     * node budget for wide graphs like loaded Eloquent relations): an
     * unverifiable graph costs durability instead of risking a poisoned
     * copy — which also makes the cycle guard order-safe. Each outcome
     * gets its own honest reason string for the log.
     */
    protected static function durabilityBlocker(\ReflectionFunction $reflection): ?string
    {
        $seen = new \SplObjectStorage;
        $budget = 500;
        $truncated = false;

        $scan = function ($value, int $depth) use (&$scan, &$budget, &$truncated, $seen): bool {
            if (is_resource($value) || gettype($value) === 'resource (closed)') {
                return true;
            }

            if (! is_array($value) && ! is_object($value)) {
                return false;
            }

            if ($depth >= 8 || --$budget <= 0) {
                $truncated = true;

                return false; // the caller fails closed on $truncated
            }

            if ($value instanceof Closure) {
                if ($seen->contains($value)) {
                    return false;
                }
                $seen->attach($value);

                $value = (new \ReflectionFunction($value))->getStaticVariables();
            } elseif (is_object($value)) {
                if ($seen->contains($value)) {
                    return false;
                }
                $seen->attach($value);

                $value = (array) $value;
            }

            foreach ($value as $item) {
                if ($scan($item, $depth + 1)) {
                    return true;
                }
            }

            return false;
        };

        foreach ($reflection->getStaticVariables() as $value) {
            if ($scan($value, 0)) {
                return 'closure captures a resource';
            }
        }

        return $truncated
            ? 'capture graph too deep/large to verify for resources'
            : null;
    }

    /**
     * The component class on the registering call stack, if any — the
     * owner signal for method-name and [$component, 'method'] callbacks,
     * whose shapes carry no binding of their own.
     */
    protected static function callingComponentClass(): ?string
    {
        $component = CallStack::component();

        return $component === null ? null : get_class($component);
    }
}
