# Fluent Callback API for Native Calls — Design Notes

## Exploring `Camera::getPhoto()->then($closure)` as a better DevEx alternative to `#[On(PhotoTaken::class)]`

> Status: **design / exploration** — not yet built. Captured from a working session
> between Shane and Simon. Companion to
> [`persistent-php-runtime-architecture.md`](./persistent-php-runtime-architecture.md).

---

## Part 1 — The mental model this builds on

### PHP is a persistent, warm interpreter (not PHP-FPM)

PHP is embedded (`php_embed`) and booted **exactly once** at app launch on a dedicated
pthread. `bootstrap/.../persistent.php` runs the autoloader, boots Laravel, and calls
`Runtime::boot($app)`, which stores the `$app` container and HTTP `$kernel` in **static**
properties. After that the interpreter stays alive — OPcache warm, container built, kernel
bootstrapped. Think **Laravel Octane on-device**, not classic per-request boot/shutdown.

Each interaction (WebView navigation, event POST, etc.) becomes one HTTP request dispatched
into that warm runtime via `zend_eval_string()` → `Runtime::dispatch()` →
`$kernel->handle()` → `terminate()`. Per-request state (router, Livewire) is flushed between
requests, but `$app`/`$kernel` — and any **static** property on our own classes — survive for
the life of the process. Requests are **serialized** (Android mutex / iOS serial
`DispatchQueue`); a separate worker runtime with its own TSRM context handles queue jobs.

### Two kinds of native call

When PHP calls a native method, the extension function `nativephp_call(method, json)`
(`build-scripts/shared/nativephp/nativephp.c`) calls the external symbol `NativePHPCall()`,
resolved at link time to `bridge_jni.cpp` (Android) or `@_cdecl("NativePHPCall")` (iOS). The C
call is **synchronous** — it blocks the *PHP thread* (not the UI thread) until native returns a
JSON string. But there are two behaviours behind that:

- **Kind A — fast/synchronous** (read a value, write a pref): native does the work in
  milliseconds and returns the result inline. Normal function-call semantics.
- **Kind B — long/interactive** (camera, biometrics, dialogs): native **returns immediately**
  with an empty map and kicks off the UI. The result comes back **later, as a separate
  request.**

### What actually happens during `Camera::getPhoto()`

1. PHP request calls `getPhoto()` → `nativephp_call` → native launches the camera
   Intent/picker and returns `{}` in ~1 ms.
2. `nativephp_call` returns, the PHP code finishes, **the HTTP request completes**,
   `Runtime::dispatch()` terminates it.
3. **PHP goes back to idle/warm.** The camera UI is owned entirely by the OS. PHP is not in the
   call stack, not blocked, not waiting — just resident and parked. From PHP's perspective the
   operation is *already over*.
4. User snaps the photo. Native saves the file, then fires an event: HTTP
   `POST /_native/api/events` (plus a JS `CustomEvent` / Livewire dispatch).
5. That POST is a **fresh request** into the same persistent interpreter →
   `DispatchEventFromAppController` → `event(new PhotoTaken(...))` → your listeners.

So `getPhoto()` is **fire-and-forget** from PHP's side. The result arrives decoupled, matched
back up via an `id`.

**PHP is never shut down or held hostage waiting on the device.** It's warm and idle the whole
time the camera is open.

---

## Part 2 — The callback design

### The goal

Replace (or sit alongside) the attribute-listener pattern:

```php
// today
Camera::getPhoto();          // launches camera

#[On(PhotoTaken::class)]
public function photoTaken(string $path, string $mimeType, ?string $id): void { /* ... */ }
```

with a fluent callback:

```php
// proposed
Camera::getPhoto()
    ->then(fn (PhotoTaken $photo) => /* $photo->path ... */)
    ->catch(fn () => /* cancelled / denied */);
```

### The one hard problem: the callback must cross boundaries

The closure is born in **request A** (`getPhoto()->then($cb)`) but must fire in **request B**
(the event POST). It may have to survive **two** boundaries:

1. **The request boundary — always crossed.** Request A terminates before the camera even
   opens. Request B is a fresh dispatch. A normal PHP variable is long gone.
2. **The process boundary — sometimes crossed.** On Android the system camera is a separate
   full-screen Activity; while it's up, the app is backgrounded and the OS can reclaim it under
   memory pressure. The persistent interpreter then dies and **reboots fresh** on return —
   anything in memory is gone. (The iOS in-process picker survives more often, but not
   guaranteed.)

"Holding onto the callback" = choosing where to put it so it survives A→B, ideally even across
a process restart. That choice *is* the design.

### What's already in place (no new infra needed)

- `Camera::getPhoto()` already returns a fluent builder, `PendingPhotoCapture`, which already
  **generates a UUID `id`** and passes `id` + `event` (the event class FQCN) to native.
- The matching event comes back carrying the **same `id`** (`PhotoTaken`, `PhotoCancelled`,
  `PermissionDenied` all have `?string $id`). The correlation key already exists.
- `laravel/serializable-closure` is already present (transitive dependency in
  `composer.lock`) — the same machinery queued closures use.
- `DispatchEventFromAppController` is the single chokepoint where every native event is turned
  into a dispatched Laravel event — the natural place to also fire callbacks.

### API shape

`then()` registers the callback keyed by the builder's existing `id`, then fires `start()`.
Register-before-launch is race-free (the camera needs human interaction, so B can't beat A).

```php
// PendingPhotoCapture
public function then(Closure|string|array $callback): self
{
    NativeCallbacks::register($this->getId(), $this->eventClass, $callback);
    $this->start();
    return $this;
}

public function catch(Closure|string|array $callback): self
{
    // failure events share the same id
    foreach ([PhotoCancelled::class, PermissionDenied::class] as $failEvent) {
        NativeCallbacks::register($this->getId(), $failEvent, $callback);
    }
    return $this;
}
```

```php
// DispatchEventFromAppController — add AFTER the existing event() dispatch.
// #[On] keeps working unchanged; this is additive.
$event = new $eventClass(...$payload);
event($event);

if ($id = ($payload['id'] ?? null)) {
    if ($cb = NativeCallbacks::resolve($id, $eventClass)) {
        app()->call($cb, ['event' => $event, ...$payload]);
        NativeCallbacks::forget($id);          // one-shot
    }
}
```

### Where to store the callback — three tiers

From simplest/best-DX to most-robust:

1. **In-memory static registry.** Store the closure in a `static array` keyed by `id`. Survives
   the request boundary for free (same warm process; our static isn't touched by the per-request
   flush). Closures can capture *anything* — no serialization constraints.
   - ✅ Best DevEx, zero constraints, trivial.
   - ❌ Dies if the OS kills the app while the camera is open. Callback silently never fires.

2. **Serialized to a durable store.** Wrap in `SerializableClosure`, persist the blob keyed by
   `id` with a TTL.
   - ✅ Survives process death — the robust path for Android camera.
   - ❌ The closure must be serializable (bound vars + `$this` serializable, no resources/PDO).
     Same rules devs already know from `dispatch(fn () => ...)`. Needs
     `SerializableClosure::setSecretKey(config('app.key'))`, which Laravel wires up.

3. **Named callables instead of closures.** Accept a class-string / `[$obj, 'method']`
   (`->then(SavePhoto::class)`). Serializes trivially, survives everything, slightly less
   "closurey."

### Recommended: hybrid (Tier 1 + graceful Tier 2)

Always register in-memory; *try* to also serialize. If serialization fails, keep the in-memory
entry and log a notice. Net effect: the API "just works" with no constraints in the common
(app-stays-alive) case, and *also* survives an Android process kill whenever the closure is
serializable. Devs only hit the constraint if they both (a) capture something unserializable and
(b) get killed mid-camera — and they get a log line explaining it.

```php
class NativeCallbacks
{
    protected static array $memory = [];

    public static function register(string $id, string $event, Closure|string|array $cb): void
    {
        static::$memory[$id][$event] = $cb;                       // Tier 1: fast path
        try {                                                      // Tier 2: durable fallback
            $blob = serialize($cb instanceof Closure ? new SerializableClosure($cb) : $cb);
            Cache::store('native_callbacks')->put("cb:$id:$event", $blob, now()->addMinutes(2));
        } catch (\Throwable $e) {
            // not serializable — keep in-memory only; log so the dev knows restart won't survive
        }
    }

    public static function resolve(string $id, string $event): Closure|string|array|null
    {
        if (isset(static::$memory[$id][$event])) return static::$memory[$id][$event];
        if ($blob = Cache::store('native_callbacks')->pull("cb:$id:$event")) {  // pull = get+forget
            $cb = unserialize($blob);
            return $cb instanceof SerializableClosure ? $cb->getClosure() : $cb;
        }
        return null;
    }

    public static function forget(string $id): void { /* unset memory + forget keys */ }
}
```

---

## Part 3 — The cache backend decision

The app already standardizes on **SQLite for everything**: `CACHE_STORE=database`,
`DB_CONNECTION=sqlite`, `SESSION_DRIVER=database`. That anchors the decision.

### Rule out the wrong answers

- **Redis / Memcached — not available.** Present in `config/cache.php` only because it's stock
  Laravel; both need a *server daemon* that doesn't exist on a phone. Nothing "redis-y" worth
  inventing over the bridge — that just means native SharedPreferences/UserDefaults, slower and
  buys nothing SQLite doesn't already give. Skip entirely.
- **`array` driver = Tier 1.** In-process memory tied to `$app`. Dies with the process. You
  already get that from a `static` property — no reason to route it through the cache layer.

So the only real question is what backs the **durable tier — file or database?**

| | file | database (SQLite) |
|---|---|---|
| Survives kill | ✅ | ✅ |
| TTL + lazy evict on read | ✅ | ✅ |
| Bulk prune of stale entries | ❌ iterate files / `cache:clear` only | ✅ one `DELETE … WHERE expiration <= ?` |
| New moving parts on device | filesystem dir | none — already the default |

**Use the database (SQLite) store.** Already the default, transactional, and the only cleanup
you'd ever write is a single SQL statement. A few-hundred-byte SQLite write is sub-millisecond —
fine at camera-press frequency.

### What auto-clears it (so cleanup isn't babysat)

Three layers, in order of how much work they do for you:

1. **`pull()` on the happy path — self-cleaning, atomic.** `resolve()` uses `Cache::pull()`
   (get + forget). When the callback fires — the overwhelmingly common case — the entry deletes
   itself. No leak.

2. **TTL + lazy eviction — the safety net.** Store with a short TTL (`now()->addMinutes(2)`; a
   camera round-trip is seconds). An expired entry is treated as **absent** on read, so a stale
   callback can *never* fire late (correctness guarantee, free). And Laravel's
   `DatabaseStore::get()` **deletes the expired row** the moment its key is next touched.

3. **Abandoned captures — the only real residue** (user opens camera, walks away, key never read
   again). Logically dead via TTL, but the SQLite row physically lingers because nothing reads
   it. Laravel has **no built-in prune command** for plain expired cache rows
   (`cache:prune-stale-tags` is tags-only), so the scheduler won't handle it. Clean fix:
   **opportunistic prune** — piggyback one bulk delete on `register()`:

   ```php
   DB::connection('sqlite')->table('native_callbacks')
       ->where('expiration', '<=', time())->delete();
   ```

   Every new capture sweeps the corpses of old ones. Amortized, bounded, no cron.

### Structural detail: give callbacks their own store

Don't dump these into the app's general cache — isolate them so prune/flush can't collateral-
damage real cached data:

```php
// config/cache.php
'native_callbacks' => [
    'driver' => 'database',
    'connection' => 'sqlite',
    'table' => 'native_callbacks',   // its own table, separate from `cache`
],
```

`Cache::store('native_callbacks')` is now isolated and safe to prune/`flush()`.
**Don't flush it on app boot** — that's exactly the data meant to survive a kill.

### Per-platform nuance that simplifies things

The durable tier exists *because* of the Android external-camera Activity getting reclaimed. The
iOS in-process picker rarely kills the app, so on iOS **Tier 1 (in-memory) usually suffices** —
and that tier has *nothing to clean up*; it dies with the process and that's fine. Reasonable
stance: **memory tier always; SQLite durable tier as the Android-focused fallback.** Keeps the
common iOS path zero-I/O and zero-cleanup.

---

## Summary / recommendation

- Add `then()` / `catch()` to `PendingPhotoCapture` (and eventually the other `Pending*`
  builders). Coexists with `#[On]` — additive, nothing to migrate.
- A small `NativeCallbacks` registry: **in-memory always**, **graceful SerializableClosure
  fallback** to a **dedicated `native_callbacks` SQLite cache store**, 2-minute TTL.
- `resolve()` via `pull()`; opportunistic bulk-delete in `register()`.
- Zero scheduled jobs, zero manual cleanup, survives process death where it matters (Android).

### Footprint when we build it

- `PendingPhotoCapture::then()/catch()` (~15 lines)
- New `NativeCallbacks` class
- ~5 lines added to `DispatchEventFromAppController`
- `native_callbacks` store in `config/cache.php` + a migration for the table
- Generalize to the other `Pending*` builders once proven on camera

### Key files

- `vendor/nativephp/mobile/src/PendingPhotoCapture.php` — the builder
- `vendor/nativephp/mobile/src/Http/Controllers/DispatchEventFromAppController.php` — dispatch chokepoint
- `vendor/nativephp/mobile/src/Events/Camera/*.php` — `PhotoTaken` / `PhotoCancelled` / `PermissionDenied` (all carry `?string $id`)
- `vendor/nativephp/mobile/src/Attributes/On.php` + `NativeComponent.php` — existing `#[On]` mechanism
- `build-scripts/shared/nativephp/nativephp.c` — `nativephp_call` extension entry
- `config/cache.php`, `config/database.php` — SQLite-backed defaults
