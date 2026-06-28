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

---

## POC status (✅ VERIFIED ON-DEVICE — camera + gallery, Android)

> **API UPDATE (current):** the generic `then()`/`catch()` shown throughout the earlier
> narrative below were **replaced by event-named handler methods**. Each outcome event gets a
> fluent method named `lcfirst(class_basename($eventClass))`. `then()`/`catch()` no longer exist;
> a generic `on(EventClass::class, $cb)` remains as the escape hatch for custom events.
>
> ```php
> Camera::getPhoto()
>     ->photoTaken(fn ($e) => $this->images[] = ['path' => $e->path])
>     ->photoCancelled(fn () => ...)
>     ->permissionDenied(fn () => ...);
>
> Camera::pickImages('images', true)->mediaSelected(fn ($e) => ...);
> Camera::recordVideo()->videoRecorded(fn ($e) => ...);
>
> // custom event set via ->event(MyEvent::class):
> ->on(MyEvent::class, fn ($e) => ...);
> ```
>
> Method names are derived at call time from the builder's `namedEvents()` (success event +
> `failureEvents()`) via a `__call` dispatcher; `@method` PHPDoc on each builder keeps IDE
> autocomplete. The handler closure receives the event object positionally
> (`call_user_func($cb, $event)`), so — since the method name already identifies the event — the
> parameter can simply be `$event`; a type-hint (`function (PhotoTaken $e)`) is optional and only
> buys IDE autocomplete on the event's properties. Everything else below (registry,
> `$this`-rebind, delivery paths, fallback) is unchanged — named methods are pure
> registration-side sugar that funnel into the same `NativeCallbacks::register()`.

Implemented in the `nativephp/mobile` package:

- `src/Support/NativeCallbacks.php` — the two-tier registry (in-memory static + best-effort `SerializableClosure` → cache) + `resolveByEvent()` (event-class fallback, see Finding 5).
- `src/Concerns/HandlesNativeCallbacks.php` — shared trait: `on($eventClass, $cb)` primitive/escape-hatch, `namedEvents()` (default = success + `failureEvents()`), and a `__call` that maps `lcfirst(class_basename())` → `on()`. Unknown method → `BadMethodCallException`; non-callback arg → `InvalidArgumentException`.
- **All `Pending*` builders** use the trait with `@method` docblocks: PhotoCapture (`photoTaken`/`photoCancelled`/`permissionDenied`), MediaPicker (`mediaSelected`), VideoRecorder (`videoRecorded`/`videoCancelled`/`permissionDenied`), Microphone (`microphoneRecorded`/`microphoneCancelled`), Push (`tokenGenerated`), Biometric (`completed`), Geolocation (`locationReceived`/`permissionStatusReceived`/`permissionRequestResult` — action-specific), Alert (`buttonPressed`; `$eventClass` defaulted to `ButtonPressed`), Scanner (`codeScanned`/`scannerCancelled`; `getId()` now generates a uuid + sends `event`).
- `src/Edge/NativeComponent.php` — **the delivery point that matters for Edge apps.** `dispatchNativeEvent()` calls `fireNativeCallback()` *before* the `#[On]` early-return, so a callback fires even when the component declares no listener. Also rebinds `$this` and rebuilds the event object (`makeEventInstance`).
- `src/Http/Controllers/DispatchEventFromAppController.php` — same resolve+fire for WebView-mode delivery; peeks and refuses to consume `$this`-bound closures (only the Edge loop can fire those correctly).
- `resources/androidstudio/app/src/main/cpp/bridge_jni.cpp` — native event buffer 512 → 4096 (Finding 4).

Off-device harnesses pass against the real `Laravel\SerializableClosure`
(9/9 + 5/5 + 4/4 + 8/8): process-kill survival, captured vars preserved, one-shot `pull`,
`$this`-rebinding, `$this`-closures correctly memory-only, event-class fallback, and the
named-method dispatcher (correct event mapping, `on()` escape hatch, unknown-method + bad-arg
throws, callable-array/class-string accepted).

### Finding 1 — delivery path is mode-specific (this was the "nothing happens" bug)

NativePHP has **two** native-event delivery paths and the callback must hook both:

- **Edge / `NativeComponent`** (native UI): events arrive as type-20 events pulled by the
  component loop → `dispatchNativeEvent()` → `#[On]`. The HTTP route is **not** used. This is the
  path kitchensink3 uses; hooking only the controller meant the callback never fired.
- **WebView**: native injects a `fetch('/_native/api/events')` → `DispatchEventFromAppController`.

Both now resolve callbacks by `(id, eventClass)`.

### Finding 2 — `$this` access, and the static-vs-regular dichotomy

Because `fireNativeCallback()` runs *on the live component instance*, it rebinds the callback to
`$this` (`Closure::bind($cb, $this, static::class)`) before invoking. So `then()`/`catch()`
closures can mutate the live component directly — `$this->images[] = …` — exactly like a `#[On]`
handler, and the component re-renders right after.

The catch: a closure that uses `$this` **cannot be made durable**. PHP forbids unbinding `$this`
from a closure that uses it (`Closure::bind(..., null)` returns `null`), and the bound component
isn't serializable. So `register()` detects an instance-bound closure (`getClosureThis() !== null`)
and quietly skips the durable copy. This yields a clean, teachable rule:

| You write | Gets `$this` (live component) | Survives an app kill |
|---|---|---|
| `function (...) { $this->… }` | ✅ | ❌ in-memory only |
| `static function (...) { … }` | ❌ | ✅ durable side-effect |

Use a **regular** closure to update component state (the common UI case); use a **`static`**
closure for a detached side-effect (save/upload/notify) that must survive the OS reclaiming the
app while the camera Activity is foregrounded.

### Finding 3 — `serialize()` does not fail-loud on a bad capture

A `static` closure capturing a resource serializes *without throwing* and stores a copy that's
broken only on restore — same footgun as Laravel queued closures. The `try/catch` in `register()`
is a backstop, not a guarantee: keep `static` callbacks to scalar / serializable captures.

---

## The gallery saga — three more findings (all pre-existing native limits, not callback-API bugs)

Camera worked first try. Gallery (`Camera::pickImages(...)->then(...)`) did **not** — and chasing it
surfaced three issues that also affect the old `#[On]` path. Worth remembering because the next
person to wire a multi-result native call will hit them.

### Finding 4 — the Edge native-event channel had a ~491-byte cap

`bridge_jni.cpp`'s `element_write_event` built events in a **512-byte stack buffer** (`event_buf[512]`)
while the shared-memory destination (`region->event_buffer`) was already **4096**. A gallery
`MediaSelected` with several file paths overflowed 512 and was **truncated**, corrupting the JSON
before PHP could decode it (`Element: Event data too large (604 bytes, max 491) — truncating`).
Fix: size the stack buffer to match the region (`event_buf[4096]`). One line, no ABI change —
the destination was always 4096. **This affected any large native event, `#[On]` included.**

### Finding 5 — native can drop the correlation `id`; fix it in PHP, not native

The gallery result came back to PHP with **no `id`** (`{"success":true,"files":[…],"count":N}`),
so id-based correlation found nothing. The native cause is the camera plugin losing `pendingGalleryId`
across the photopicker's activity lifecycle. The **wrong** fix is patching native lifecycle
(`onSaveInstanceState` / `SharedPreferences`) — it's fragile and lives in the wrong layer.

The **right** fix is in the PHP registry: a native operation (a photo, a gallery pick) is **modal
and single-in-flight**, so when an event arrives with no usable `id`, fire *the one pending callback
for that event class*. `NativeCallbacks::resolveByEvent($eventClass)` does this; `fireNativeCallback()`
tries exact `id` first (camera), then falls back to it (gallery). The native `id` becomes an
optimization, not a requirement — **zero native changes for correlation.**

> This is the layer distinction from the cache discussion: the SQLite cache is the *PHP* durable
> store for the closure; correlation is *also* a PHP concern. Kotlin can't reach Laravel's DB, so
> don't push correlation down into native — keep it in the registry.

### Finding 6 — the trait rollout, and a `@press` gotcha that looks unrelated

The callback API is a shared trait (`HandlesNativeCallbacks`); adding it to a builder is one
`use` line, a `failureEvents()` declaration (when there are distinct cancel/denied events), and an
`@method` docblock. Gallery has **no distinct cancel event** — `MediaSelected` carries
`cancelled`/`success` itself — so its only named method is `mediaSelected()`; inspect the event
inside it for cancellation. (Camera/video *do* have distinct cancel events, hence
`photoCancelled()` / `videoCancelled()`.)

Gotcha that cost real time: **every `@press="method"` in the Blade view must have a matching method
on the component.** A view referencing `@press="openVideo"` while `Home` had no `openVideo()`
*looked* like the whole tile row failed to render — but `@press` resolves at **tap time**, not
render, so a missing handler crashes on tap, it doesn't blank the row. (The actual blank-row was a
stale hot-reload state, fixed by a full relaunch.) When pruning component methods, grep the view
for `@press`/`@doubleTap`/`@change` first.

### How to test in kitchensink3 (Android)

`~/Herd/kitchensink3` symlinks `vendor/nativephp/mobile` → `mobile-air` and (via a composer `path`
repo) `vendor/nativephp/mobile-camera` → `Plugins/nativephp/camera`. The device build **bundles**
package PHP into the APK and **compiles** the native C++/Kotlin — so after a core change run `install`
+ a full rebuild (the `bridge_jni.cpp` truncation fix needs the native recompile; PHP is bundled).

`Home` uses the callback API with **no `#[On]`**, via event-named methods:
`openCamera()` → `getPhoto()->photoTaken()->photoCancelled()->permissionDenied()`,
`openVideo()` → `recordVideo()->videoRecorded()`,
`openGallery()` → `pickImages()->mediaSelected()` (handles cancel/empty inside it). Confirmed
working: photos and a multi-image gallery pick land straight into `$this->images` and render.

### Process rules learned the hard way

- **Never edit `~/Herd/native`** — it's a build target. Native-runtime changes go in
  `mobile-air/resources/androidstudio/...`; `install` copies them. Plugin changes go in
  `~/Herd/Plugins/nativephp/<plugin>` (symlinked into the app via a composer `path` repo).
- A `path` repo + `composer update` **symlinks** the package — edits are live, but Kotlin/C++ still
  needs a rebuild to reach the APK.

### Key files

- `src/Support/NativeCallbacks.php` — registry (register / resolve / resolveByEvent / forget)
- `src/Concerns/HandlesNativeCallbacks.php` — the named-method trait (`on()` + `namedEvents()` + `__call`)
- All `src/Pending*.php` builders — use the trait; each has `@method` docblocks for its events
- `src/Edge/NativeComponent.php` — `fireNativeCallback()` + `makeEventInstance()`; Edge delivery, `$this` rebind, event-class fallback
- `src/Http/Controllers/DispatchEventFromAppController.php` — WebView delivery chokepoint (skips `$this`-bound closures)
- `resources/androidstudio/app/src/main/cpp/bridge_jni.cpp` — native event buffer (512 → 4096)
- `src/Events/Camera/*.php`, `src/Events/Gallery/MediaSelected.php` — result events (carry `?string $id`; `MediaSelected` encodes cancel in-band)
- `src/Attributes/On.php` — existing `#[On]` mechanism (coexists, untouched)
- `config/cache.php`, `config/database.php` — SQLite-backed defaults

### Remaining cleanup (not blockers)

- Strip the diagnostic logging if any remains in `fireNativeCallback()`.
- Roll the trait onto the rest of the `Pending*` builders (alert, biometric, geolocation, microphone, push, scanner — scanner needs a small touch-up, see earlier note).
- Decide whether to also give the camera plugin a best-effort native `id` persistence (optional now that PHP correlates by event class).
