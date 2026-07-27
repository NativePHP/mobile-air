# Async Tasks — Background PHP Work with UI Completion Callbacks

## Running PHP work off the UI runloop, then updating component state on completion.

> Status: **design + initial implementation**. Companion to
> [`persistent-php-runtime-architecture.md`](./persistent-php-runtime-architecture.md)
> and [`native-callback-api-design.md`](./native-callback-api-design.md).

---

## The developer story

```php
public function generateReport(): void
{
    $this->generating = true; // spinner shows on the next (immediate) re-render

    AsyncTask::dispatch(static function () {
        // Runs on a SEPARATE PHP thread with its OWN interpreter.
        // Must be a static closure — no $this, nothing unserializable captured.
        return ExpensiveReport::build()->toArray();
    })
        ->finished(function (array $result) {
            // Back on the UI thread, rebound to this live component.
            $this->report = $result;
            $this->generating = false;
        })
        ->failed(function (Throwable $e) {
            $this->generating = false;
            $this->error = $e->getMessage();
        });
}
```

Sugar on `NativeComponent` for the common in-component case:

```php
$this->async(static fn () => ExpensiveReport::build()->toArray())
    ->finished(fn ($result) => $this->report = $result);
```

Both forms return a `PendingAsyncTask`.

---

## Why this is mostly glue, not new infrastructure

Two halves already exist in the codebase; async tasks are the bridge between them.

1. **A real background PHP thread.** Each platform already boots independent PHP
   interpreter contexts on their own threads with their own TSRM storage — the
   queue worker (`worker_php_*`), the ephemeral lane (`ephemeral_php_*`), and the
   per-webview lanes (`webview_php_*`). Async tasks add one more lane of the same
   shape (`async_php_*`) — deliberately **not** the queue worker (see §Decisions).

2. **A completion channel into the parked UI runloop.** The fluent-callback
   machinery built for Camera (`NativeCallbacks` + `fireNativeCallback()` in
   `NativeComponent`) already: stores a callback correlated by id, receives a
   native event that wakes `nativephp_element_wait_event()`
   (`nphp_element_post_event` / `sendNativeEvent`), rebinds the callback to the
   **live** component, runs it, and re-renders. That is exactly `->finished()`.

The only missing piece was a way for a background PHP context to say "I'm done"
back to the UI runloop, plus a small API surface on top.

---

## Data flow

```
UI PHP thread (component runloop)          Async PHP thread (own interpreter)
────────────────────────────────           ──────────────────────────────────
handler: AsyncTask::dispatch($work)
  → serialize $work (SerializableClosure)
  → write payload → storage/framework/async/payload/<id>.task
  → NativeCallbacks::register(id, …)        (finished/failed stored, screen-tagged)
  → AsyncTaskRegistry::register(id, origin)
  → nativephp_call('AsyncTask.Dispatch', {id})  ─┐  native assigns a booted async
  → handler returns → re-render (spinner)        │  context and runs:
                                                 ▼
                                    artisan native:async:run --id=<id>
                                      → read + delete payload/<id>.task
                                      → unserialize + run $work → $result
                                      → AsyncTask.Complete(id, event, payload) ─┐
                                                                                │
UI runloop  ◀── sendNativeEvent(event, {id, result}) ◀──────────────────────────┘
  → handleAsyncCompletion: scope check, bind cb to live component, run cb($result)
  → re-render → publish frame
```

No PHP value ever crosses a thread directly — everything is serialized. The
**work payload travels via a temp file** on the shared app filesystem (the UI
and async contexts share it), so the native side never touches it: it just
assigns an async-lane context to run the task and relays the completion event.
It never interprets either.

---

## Decisions (from design review)

1. **Static closures only.** The work closure runs in a different interpreter, so
   it cannot carry `$this` or capture anything unserializable. We **reflect the
   closure at dispatch time and throw immediately** if it is non-static — failing
   in the handler where the developer can see it, not silently in a worker log.
   `finished()`/`failed()` closures **do** bind to the component (they run on the
   UI thread), exactly like Camera callbacks.

2. **Screen scoping, with an opt-out.** By default a `finished()` callback is
   **dropped if the originating screen is no longer topmost** when the result
   arrives — firing a component-bound closure against the wrong live component is
   a footgun (see the id-collision rationale in `CallbackRegistry`). The
   originating screen is held as a **`WeakReference`, not an object id**: PHP
   recycles `spl_object_id`s, so a popped screen's id can be handed to the next
   component allocated and an id comparison would then deliver the callback to an
   unrelated screen. A weak reference to a freed component reads back as `null`,
   which never matches, so the completion is dropped as intended. But a
   component may own a UI element shared across screens (a layout chrome element,
   a mini-player) that should react regardless. For that, `->shared('alias')`
   flips delivery from a component-scoped closure to a **named global event**:
   the result is delivered as a native event named `alias`, handled by any active
   component via `#[On('alias')]` / `->on('alias', …)`. The developer picks it up
   however they like.

3. **`failed()` handler.** Errors serialize down to class + message (+ trace
   string) as an `AsyncTaskFailed` event carrying the same id. Async UI tasks
   default to **one attempt** — silent queue-style retries of a button action are
   surprising.

   **Every dispatch reaches one outcome.** A spinner that never stops is the
   worst failure mode this API can have, so the paths that could deliver
   *nothing* are all closed:

   - **A refused dispatch fails at the call site.** `AsyncTask.Dispatch` returns
     `success`, and PHP checks it. No executor running, no free slot, no bridge,
     or a Jump subprocess that wouldn't launch — the task never started, so
     `->failed()` fires immediately rather than the UI waiting on a result that
     was never coming.
   - **A non-encodable result fails as a task failure.** The result is put
     through its JSON round trip **in the background context**
     (`AsyncTaskRunner::normalizeResult()`), where a failure can still be
     reported as an ordinary `AsyncTaskFailed`. Left to `json_encode()` in the
     transport it would just return `false` and nothing would be delivered.
   - **A hung task times out.** Every dispatch carries a deadline (default 60s,
     `->timeout($seconds)`, `0` to disable) plus the exact completion to post if
     it passes. The native watchdog holds both and posts the pre-built failure
     event when the deadline expires, so native still never has to know how a
     failure event is shaped. The work itself is *not* killed on device — a PHP
     interpreter mid-task can't be interrupted safely — so the timeout unblocks
     the UI, and a late completion for a task already reported as failed is
     discarded (its callbacks are gone). Jump has no native watchdog, so the
     transport sweeps overdue subprocesses on each runloop tick instead.

   ⚠️ **This guarantee is currently bounded by the event channel underneath it.**
   `nphp_element_post_event()` (in `build-scripts`, `shared/nativephp/nphp_element.c`)
   is a **single slot, not a queue**: it always writes at offset 0 and sets
   `event_count` to 1 as a flag. A second post landing before PHP drains the
   first overwrites it silently. This lane is the first thing to post into that
   channel from several OS threads at once — four pool threads plus the watchdog
   — so it is the first thing to expose it, and concurrent completions can be
   dropped (measured: 6 dispatches → 4–5 delivered). Every path *above* the
   channel reaches an outcome; the channel can still lose the frame carrying it,
   and that includes the watchdog's own timeout event. Not platform-specific —
   iOS goes through the same C function and simply loses the race less often.
   The fix is to make `event_heap` a real FIFO, which is a `build-scripts` change
   needing a PHP rebuild and a lib re-ship, so it is deferred past v4. Until it
   lands, treat the guarantee as holding for one in-flight task at a time.

4. **Immediate, concurrent, no SQLite, not the queue worker.** Tasks must start
   *now*, not on a ≤3s `queue:work --once` poll, and a developer may run several
   at once. So async tasks get their **own lane** with a small pool of PHP
   contexts, independent of the single queue-worker thread and its SQLite
   `database` queue. Concurrency is bounded (see §Concurrency); excess tasks
   queue in native and log when they do.

   Nothing on this path touches SQLite — but only because the completion
   callbacks are registered with `NativeCallbacks::register(..., durable: false)`.
   Its default tier-2 copy is a cache write (SQLite-backed by default) on the UI
   thread; async tasks skip it deliberately, since the payload and scope metadata
   live in RAM and a temp file, so a process kill loses the whole task anyway and
   a durable callback would have nothing to survive to.

5. **Result size and shape.** The event channel has no hard cap now, but the
   result still round-trips as JSON — objects arrive at `finished()` as arrays.
   Return scalars/arrays; pass large blobs by file path or cache key. The fake
   runs the same normalization, so a test can't pass on a value a device would
   never deliver.

6. **Testing + Jump.**
   - **Testing:** `AsyncTask::fake()` runs the work **inline-synchronously** and
     records dispatches, so tests need no threads. Assertion helpers
     (`assertDispatched`, etc.).
   - **Jump (dev server):** there is no device async lane on the laptop, so the
     Jump fallback spawns a background `php artisan native:async:run` **subprocess**
     on the dev machine (payload handed off via a temp file under
     `storage/framework/async/`), and the subprocess delivers completion back
     through the existing Jump event path. (The "serialize the closure over the
     websocket and run it on the phone" option is noted as a future alternative —
     it would exercise the real device lane from Jump, at the cost of a
     websocket round-trip for the payload.)

7. **`dispatch` terminology, not a real queued Job.** `AsyncTask::dispatch()`
   reads familiarly to Laravel devs but is **not** a `ShouldQueue` job and does
   not pollute the standard queue. `AsyncTask` is an extensible base — a developer
   can subclass it (`class BuildReport extends AsyncTask`) and
   `BuildReport::dispatch($month)->finished(…)`.

8. **Internal vs. surface naming.** Internally we reuse the existing fluent
   `->then()` / `->catch()` vocabulary where it already exists; the public surface
   on `PendingAsyncTask` uses **`finished()` / `failed()`** for clearer intent.

### Parked for later

- **`await()` via Fibers.** A synchronous-looking `$result = $this->await(fn …)`
  that suspends the handler and resumes it on completion would layer cleanly on
  this exact task-id + event plumbing. Parked until we confirm Fiber support in
  the custom PHP binaries and work through handler re-entrancy.

---

## Concurrency

A bounded pool of async PHP contexts (default 4). Each context is a booted
interpreter reused across tasks (boot cost paid once, like the queue worker).
Tasks beyond the pool size queue in native FIFO; the native side `log()`s when a
task waits so bounded concurrency is never silent. Ordering across tasks is not
guaranteed — each `finished()` fires when its own task completes.

---

## Native surface (two bridge functions + one lane)

The payload moves via a temp file, so native stays a dumb courier with just two
calls:

- `AsyncTask.Dispatch` `{id, timeout?, timeoutEvent?, timeoutPayload?}` → assign
  a booted async-lane context to run `native:async:run --id=<id>`. Returns
  immediately, with a `success` flag PHP acts on (see §3). The timeout trio is
  the watchdog contract: native holds the deadline and the pre-built completion
  to post if it passes, so it still never interprets a task or builds an event.
- `AsyncTask.Complete` `{id, event, payload}` → relay `sendNativeEvent(event,
  payload)` into the UI runloop (this is what wakes the C `wait_event`).

Both executors **stop synchronously**: `stop()` drains in-flight tasks and shuts
each context down before returning, because callers stop the pool immediately
before a persistent-runtime reboot and `php_embed_shutdown` frees Zend module
state a live async context still references. On iOS the pool boots asynchronously,
so `stop()` waits out an in-flight boot rather than returning early — bailing
would leave C slots allocated with nothing able to reclaim them, and `start()`
refusing to run twice would then drop every later dispatch for the life of the app.

The lane also avoids `setenv()`. The single-threaded lanes set
`APP_RUNNING_IN_CONSOLE`/`PHP_SELF` that way, but this one runs several contexts
at once and `setenv`/`getenv` are neither thread-safe nor per-thread — pool
threads would clobber each other and the UI lane. The eval'd code sets the
per-thread `$_SERVER` entries instead, which is what Laravel's `Env` reads.

C lane `async_php_*` mirrors the queue-worker / webview lanes: a pool of
per-thread TSRM contexts that boot the persistent bootstrap once and run
`native:async:run` for tasks handed to them — concurrent, reused, and with no
coupling to the persistent runtime's request mutex. Android
(`AsyncTaskExecutor.kt`) pins each context to a pool thread; iOS
(`AsyncTaskExecutor.swift`) pins each C slot to a serial `DispatchQueue`.

Payload handoff via temp file (rather than a native RAM map keyed by id) keeps
the native surface minimal and truly persistence-free — no database, no queue. The
RAM-map alternative would avoid the disk write but needs a concurrent map plus
lifecycle on each platform; revisit only if temp-file IO shows up in profiling.

### Alternative considered — a userland `nativephp_post_event()`

Rather than routing completion through a bridge function
(`AsyncTask.Complete` → `sendNativeEvent`), the extension could expose a
`nativephp_post_event(type, name, payload)` PHP function that wraps
`nphp_element_post_event` directly. That is **cleaner and has zero native hop** —
the async context would post the wake event itself. We did **not** take it here
because the `nativephp` extension ships **prebuilt** with the runtime, so a new
extension function can't land from this repo; the bridge-function route is the
one shippable without a new PHP binary. Worth revisiting when the extension is
next rebuilt.
