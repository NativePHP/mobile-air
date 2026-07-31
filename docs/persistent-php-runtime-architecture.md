# Persistent PHP Runtime Architecture

## Full conversation exploring PHP execution on Android, performance optimization, and the path to a persistent runtime.

---

## How PHP Runs on Android: WebView vs Native

### WebView Mode (Request/Response)

PHP works like a web server. Every time you tap a link or submit a form, PHP boots up, generates an HTML page, sends it back, and shuts down. The WebView (basically a built-in browser) displays that HTML. It's stateless — PHP has no memory between taps.

### Native UI Mode (Long-Lived Event Loop)

PHP starts up once and stays alive. It draws a native screen (real Android buttons, text fields, etc. — not HTML), then sits and waits. When you tap something, that event gets sent to PHP, it updates the screen, and goes back to waiting. It's a live conversation between PHP and the native UI, running in a loop until you leave that screen.

### The Simplest Analogy

WebView mode is like texting someone a question and getting a reply each time. Native UI mode is like being on a phone call — the connection stays open and you go back and forth in real time.

---

## Deep Dive: How PHP Boots on Android

### Step 1: Process & Library Loading

When Android launches the app, it forks the Zygote process. The app gets its own Linux process with one main thread (the UI thread).

The first time any Kotlin code touches `PHPBridge`, the companion `init` block runs:

```kotlin
System.loadLibrary("compat")       // libcompat.so — Android shims
System.loadLibrary("php")          // libphp.so — the entire PHP interpreter (Embedded SAPI, ZTS mode)
System.loadLibrary("php_wrapper")  // libphp_wrapper.so — your JNI bridge code
```

This calls `dlopen()` under the hood for each `.so`. When `libphp_wrapper.so` loads, the linker calls its `JNI_OnLoad()` function (`php_bridge.c:560`). That function:

1. Saves the `JavaVM*` pointer globally (`g_jvm = vm`) — this is how C code can later call back into Kotlin from any thread
2. Registers all native method bindings — maps Kotlin `external fun` declarations to C function pointers (e.g. `nativeHandleRequest` → `native_handle_request`)
3. Calls `InitializeBridgeJNI()` (`bridge_jni.cpp:37`) which finds and caches the `BridgeRouterKt` class and its `nativePHPCall`/`nativePHPCan` static methods, plus the `NativeElementBridge` methods for native UI

At this point, PHP is loaded into memory but not running. `libphp.so` is just a shared library sitting in the process's address space. No PHP code has executed yet.

### Step 2: App Initialization

`MainActivity.onCreate()` sets up the Compose UI, registers bridge functions, then kicks off `initializeEnvironmentAsync` which calls `LaravelEnvironment.initialize()`. This:

1. **Extracts the Laravel bundle** — unzips `laravel_bundle.zip` from APK assets into `/data/data/<package>/app_storage/laravel/`
2. **Sets up persistent storage** — creates directories for sessions, cache, logs, database under a separate `persisted_data/` directory (survives app updates)
3. **Sets ~40+ environment variables** via `nativeSetEnv()` — which just calls `setenv()` in C. These become `$_SERVER` values in PHP: `LARAVEL_BASE_PATH`, `DB_DATABASE`, `SESSION_DRIVER`, `APP_KEY`, etc.
4. **Runs artisan commands** — `optimize:clear`, `storage:link`, `migrate --force`. Each artisan command does a full PHP boot/shutdown cycle

### Step 3: How a Single PHP Request Executes

This is the heart of it. Tracing a WebView page load:

#### Thread Journey

1. **WebView thread** (Android's chromium compositor thread) makes a request to `http://127.0.0.1/dashboard`
2. `PHPWebViewClient.shouldInterceptRequest()` catches it on that background thread
3. Calls `PHPBridge.handleLaravelRequest()` which submits work to `phpExecutor` — a **single-threaded Java executor** (`Executors.newSingleThreadExecutor()`)
4. The calling thread **blocks** on `future.get()` waiting for the result
5. The executor's single worker thread runs the lambda, which:
   - Clears stale env vars (Inertia headers from previous request)
   - Sets `HTTP_*` env vars from the request headers
   - Sets cookies
   - Calls `nativeHandleRequest()` — a JNI call that crosses into C

#### Inside C: `run_php_request()` (`php_bridge.c:188`)

This is where PHP actually boots. On the executor's single Java thread, now executing native code:

```
1. pthread_mutex_lock(&g_php_request_mutex)
   — Acquires the global mutex. Only ONE PHP execution at a time, ever.
   — If another request is running (artisan command, native UI), we block here.

2. clear_collected_output()
   — Frees the old output buffer, mallocs a fresh 256KB buffer

3. setenv() calls
   — REQUEST_URI="/dashboard", REQUEST_METHOD="GET", SCRIPT_FILENAME=..., HTTP_HOST="127.0.0.1", etc.
   — These are PROCESS-WIDE. This is why the mutex exists — two concurrent
     requests would clobber each other's env vars.

4. setup_embed_module()
   — Configures the PHP Embed SAPI module struct BEFORE init:
     • ub_write = capture_php_output    (output interception callback)
     • header_handler = android_header_handler
     • ini_entries = "output_buffering=4096\ndisplay_errors=1\n..."
     • additional_functions = nativephp_functions  (our custom PHP functions)

5. php_embed_init(0, NULL)
   — THIS IS THE BIG ONE. This boots PHP's entire runtime:
     • Allocates TSRM (Thread-Safe Resource Manager) context for this thread
     • Initializes Zend Engine (compiler, executor, memory manager)
     • Allocates Zend Memory Manager arena (zend_mm) — PHP's own heap
     • Initializes all built-in extensions (standard, json, pdo, etc.)
     • Processes ini_entries
     • Sets up the SAPI layer (our embed callbacks)
   — Returns SUCCESS or FAILURE

6. zend_register_functions(NULL, nativephp_functions, NULL, MODULE_PERSISTENT)
   — Registers our custom PHP functions into the function table:
     • nativephp_call()
     • nativephp_can()
     • nativephp_element_init()
     • nativephp_element_publish()
     • nativephp_element_wait_event()
     • nativephp_element_reset()
     • nativephp_element_shutdown()
   — These are now callable from PHP code

7. zend_activate_modules()
   — Runs RINIT (Request Init) for all loaded extensions

8. initialize_php_with_request(postData, method, uri)
   — Populates $_POST, $_SERVER with request data
   — Handles content-type parsing for form data / JSON

9. php_execute_script(&fileHandle)
   — Compiles and executes native.php:
     a. Zend lexer tokenizes the file
     b. Zend parser builds AST
     c. Zend compiler produces opcodes
     d. Zend VM executes opcodes
   — native.php then:
     • requires autoload.php (Composer)
     • bootstraps Laravel (service providers, config, etc.)
     • captures the HTTP request
     • routes it through Laravel's kernel
     • echoes the HTTP response (headers + body)
   — Every echo/print calls our capture_php_output() callback

10. Output capture chain:
    PHP echo → ub_write → capture_php_output() → pipe_php_output()
    → realloc g_collected_output buffer (256KB chunks, max 16MB)

11. strdup(g_collected_output)
    — Copy the output before shutdown destroys it

12. safe_php_embed_shutdown()
    — Blocks all signals (prevents SIGSEGV during TSRM teardown)
    — Runs RSHUTDOWN for all extensions
    — Destroys Zend Engine state
    — Frees TSRM context
    — Frees zend_mm arena (all PHP memory freed)
    — Restores signal mask

13. pthread_mutex_unlock(&g_php_request_mutex)
    — Release the lock, next request can proceed
```

The response string goes back up through JNI → Java → `processRawPHPResponse()` (extracts Set-Cookie headers, wraps in HTTP status line if missing) → returned to WebView as a `WebResourceResponse`.

### Step 4: The Memory Model

Everything is in-process. There is no separate PHP process:

```
┌─────────────────────────────────────────┐
│          Android App Process            │
│                                         │
│  ┌──────────┐  ┌──────────────────┐     │
│  │ Dalvik/  │  │ Native Heap      │     │
│  │ ART VM   │  │                  │     │
│  │          │  │ libphp.so        │     │
│  │ Kotlin   │  │ (PHP interpreter)│     │
│  │ objects  │  │                  │     │
│  │          │  │ libphp_wrapper.so│     │
│  │ WebView  │  │ (JNI bridge)    │     │
│  │ Compose  │  │                  │     │
│  └──────────┘  │ g_collected_output│    │
│                │ (output buffer)  │     │
│                │                  │     │
│                │ g_element_region │     │
│                │ (native UI bufs) │     │
│                └──────────────────┘     │
│                                         │
│  Shared: env vars, file descriptors,    │
│          process memory space           │
└─────────────────────────────────────────┘
```

PHP's Zend Memory Manager allocates from the native heap (via `malloc`/`mmap`). When `php_embed_shutdown()` runs, it frees everything. Next request starts completely fresh — no state carries over inside PHP itself.

### Step 5: Thread Summary

| Thread | What it does |
|---|---|
| **Main (UI)** thread | Compose rendering, touch events, activity lifecycle |
| **WebView compositor** thread | Triggers `shouldInterceptRequest`, blocks waiting for response |
| **phpExecutor** worker thread | The ONE thread that runs all PHP. Serialized via Java's single-thread executor + C pthread mutex |
| **Hot reload watcher** thread | Polls for `.hot_restart` file, spawns native UI PHP execution on the phpExecutor or directly |

There is only ever one thread running PHP at any given moment. The combination of Java's single-thread executor and the C `pthread_mutex_t` guarantees this. PHP is compiled with ZTS (thread safety) but the full init/shutdown per request means each execution is isolated anyway — ZTS is there as a safety net since the thread ID may differ between requests.

### Step 6: Native UI Difference

For native UI, the flow diverges at step 9. Instead of the PHP script echoing HTML and returning, it:

1. Calls `nativephp_element_init()` → allocates the `nphp_element_region_t` struct (4MB flat buffer + 4MB prop buffer + 256KB event buffer)
2. Registers the region pointer with the JNI bridge via `NativeElement_RegisterRegion()`
3. Calls `nativephp_element_publish($tree)` → serializes the PHP array tree into the flat buffer (108 bytes per node, packed binary) and calls `NativeElement_PostTreeUpdate()` which does a JNI call to Kotlin's `postTreeUpdate()` — this reads the buffer and posts to the Compose renderer
4. Calls `nativephp_element_wait_event(-1)` → blocks on `pthread_cond_wait()` waiting for the event condvar
5. When a user taps a button, Kotlin writes a binary event into the 256KB event buffer and signals the condvar
6. PHP unblocks, reads the event, processes it, rebuilds the tree, publishes again, waits again
7. This loop continues until the PHP script returns, at which point `nphp_element_shutdown()` frees everything

The key difference: PHP never calls `php_embed_shutdown()` until the user leaves the native UI screen. The interpreter stays alive, holding state, running an event loop. The mutex stays locked the entire time, meaning no WebView requests can execute until the native UI session ends.

---

## Communication: Not IPC

There is no IPC. It's all in-process, same address space.

PHP isn't a separate process. `libphp.so` is loaded directly into the app's process via `System.loadLibrary("php")` → `dlopen()`. PHP's entire interpreter — the Zend VM, memory manager, all of it — runs as native code inside the same Linux process as the Kotlin/Compose code.

### PHP → Kotlin (bridge calls)

```
PHP calls nativephp_call("Dialog.Alert", "{...}")
    → That's a C function registered in the Zend function table
    → C function body (native_functions.c) calls NativePHPCall() (bridge_jni.cpp)
    → NativePHPCall() calls GetJNIEnv() to get the Java env for the current thread
    → env->CallStaticObjectMethod() invokes BridgeRouterKt.nativePHPCall()
    → Kotlin method runs, returns a jstring
    → C copies it with strdup(), returns to PHP as a string
```

That's just a function call chain. Same thread, same stack. PHP calls C calls Java calls Kotlin, and the return value comes back up. No sockets, no pipes, no serialization to cross process boundaries.

### Kotlin → PHP (native UI events)

```
User taps button
    → Compose callback fires on main thread
    → element_write_event() writes bytes directly into the region's event_buffer
    → pthread_cond_signal() wakes the PHP thread
    → PHP thread reads the bytes from the same memory address
```

That's a `pthread_cond_t` — one thread signals, another thread wakes up. They're sharing memory because they're in the same process. The `event_buffer` is just a `uint8_t[256KB]` that both threads can read/write because it's `malloc`'d in their shared heap.

### PHP → Kotlin (tree updates)

```
PHP calls nativephp_element_publish($tree)
    → C serializes the PHP array into flat_buffer (same malloc'd memory)
    → Calls NativeElement_PostTreeUpdate()
    → JNI call to Kotlin's postTreeUpdate()
    → Kotlin reads flat_buffer via NewDirectByteBuffer() — zero-copy, it's a pointer to the same memory
```

The `NewDirectByteBuffer()` call in `bridge_jni.cpp:363` is the key — it wraps a raw C pointer in a Java `ByteBuffer` object. Kotlin reads the bytes directly from the C-allocated memory. No copy.

### The whole stack

```
┌─────────────────────────────────┐
│        One Linux Process        │
│                                 │
│  ART VM (Kotlin/Java)           │
│       ↕ JNI (function calls)    │
│  Native code (C/C++)            │
│       ↕ Zend API (function calls)│
│  PHP interpreter (libphp.so)    │
│                                 │
│  All sharing one heap,          │
│  one set of file descriptors,   │
│  one set of env vars            │
└─────────────────────────────────┘
```

The only synchronization primitives are:
- `pthread_mutex_t` — to serialize PHP execution
- `pthread_cond_t` — to wake PHP when a UI event arrives
- `atomic` loads/stores — for lock-free reads of buffer sizes and version counters

It's about as fast as inter-thread communication gets. The overhead is basically zero compared to actual IPC (pipes, sockets, shared memory with `mmap` across processes, etc.).

---

## What is IPC?

Inter-Process Communication — how separate processes talk to each other. Since processes have isolated memory spaces, they can't just read each other's variables. IPC mechanisms bridge that gap: pipes, sockets, shared memory (`mmap`), message queues, signals, and files. Each has tradeoffs — pipes are simple but one-directional, sockets work across networks but have overhead, shared memory is fast but needs manual synchronization. Android uses Binder (a custom IPC mechanism) for app-to-system communication. IPC always has overhead from context switching and data copying between address spaces. This project avoids all of this because PHP runs in-process — no boundaries to cross.

---

## Performance: How We Can Beat React Native's JSI/Hermes

### React Native's Architecture (Post-Bridge)

Modern React Native (post-bridge) looks like this:

```
JS ↔ C++ (via JavaScript Interface)
C++ ↔ Platform (via Java Native Interface on Android)
```

Key performance factors:
- JS engine speed (Hermes/JSC)
- How much work happens on JS thread
- How much crosses language boundaries
- How often layout recalculates
- How much garbage collection stalls

React Native's bottleneck historically was the bridge. JSI removed a lot of that. Now performance is mostly: JS execution, UI reconciliation cost, native module crossing cost.

### Where NativePHP Already Wins

**1. No bridge at all for native UI**

JSI is still a bridge — JS calls C++ host objects which proxy to Java/ObjC. There's indirection. Our element system writes directly to a flat buffer that Compose reads via `NewDirectByteBuffer`. That's zero-copy, zero-indirection. JSI can't do that — every JS→native call still goes through C++ wrapper objects.

**2. True shared memory for UI**

React Native's JSI gives synchronous function calls but the data still gets copied when crossing the JS→native boundary. Our flat buffer approach means PHP writes bytes and Kotlin reads the exact same bytes. The UI tree is a binary format that both sides understand natively.

**3. No garbage collector pauses**

Hermes has a GC. It pauses. Our PHP model (init/shutdown per request in WebView mode, or manual `gc_collect_cycles()` in persistent mode) means we control when cleanup happens. In persistent mode, we could GC between screen transitions when the user won't notice.

**4. PHP's type system is simpler to serialize**

JS objects are complex — prototypes, getters, proxies, Symbols. Serializing JS→native is inherently messy. PHP arrays are flat hash tables. Serializing a PHP array to a binary flat buffer is trivial and fast — which is exactly what `build_node()` does in `native_functions.c`.

### Where JSI Still Has an Edge (and How to Close It)

**Hermes precompiles to bytecode** — skips parsing/compilation at runtime. PHP recompiles every script from source on each `php_embed_init()`. With a persistent interpreter, this gap closes because you only compile once. Could go further with OPcache in shared memory.

**JSI is truly synchronous inline** — a JS function call to a host object returns in the same tick, same stack frame. Our `nativephp_call()` already does this too (same thread, same stack), so we're already at parity here.

### The Key Arguments from the Analysis

**The question isn't "Is PHP faster than JS?" It's "How many boundaries and abstractions exist?"**

```
Performance =
  (number of crossings) × (cost per crossing)
  + (runtime execution cost)
  + (UI scheduling overhead)
```

If you reduce abstractions more aggressively than RN, you win.

Mobile UI apps are not CPU benchmarks. They are:
- IO-bound
- UI-thread bound
- Layout-bound
- Native-bound

Raw language speed matters less than architecture.

### Where NativePHP Can Win

1. **If boundary crossings are cheaper than RN's JSI layer** — they already are (raw C strings vs boxed jsi::Value objects)
2. **If virtual DOM-style reconciliation is avoided** — it already is (full tree republish, no diffing in PHP, Compose does its own minimal diffing)
3. **If heavy work stays native-side** — animations, rendering, gestures should be 100% native, PHP off the UI thread

### Where React Native Still Has Advantage

- 10+ years of optimization
- Mature JSI tuning
- Hermes specialization for RN
- Massive engineering resources from Meta
- JS engines optimize hot loops better than PHP currently does
- Stronger speculative optimization

### The Strategic Twist

We're not trying to beat React Native at JavaScript. We're trying to redefine architecture.

If NativePHP becomes:
- Shared-memory PHP runtime
- Direct C++ calls
- Minimal crossing
- No virtual DOM
- Native layout ownership

It could have:
- Lower memory churn
- Fewer scheduler layers
- Simpler threading model
- Less GC pause frequency

**Performance is an architecture competition, not a benchmark competition.**

### The Scorecard

| Factor | React Native | NativePHP Today | NativePHP (persistent) |
|---|---|---|---|
| Boundary crossings per UI update | 3 layers (JS→C++→JNI→Java) | 1 (flat buffer + JNI push) | 1 |
| Data copying per UI update | Multiple copies + boxing | Zero-copy (direct ByteBuffer) | Zero-copy |
| Reconciliation | Fiber diffing in JS | None (full tree republish) | None |
| Runtime boot cost | ~50ms (Hermes bytecode) | ~200ms per request | ~200ms once |
| GC pauses | Hermes GC, unpredictable | None in persistent mode | Controlled |
| Threading complexity | 3 threads + scheduler | 1 PHP thread + UI thread | Same |
| Raw compute | V8/Hermes wins | PHP JIT loses | Same |
| Animation | Native-driven (Animated/Reanimated) | Not yet architected | Needs native animation specs |

### Design Principles for Speed

1. PHP never touches 60fps animation loops
2. Native owns rendering
3. Shared memory structs instead of value copying
4. No virtual DOM diffing
5. Persistent PHP VM (no request lifecycle resets)
6. Smart batching of native calls
7. Thread isolation for heavy PHP logic

---

## The Plan: Persistent PHP Runtime

### The Problem Today

The boot sequence is WebView-first:

```
onCreate()
  → create WebView instance
  → initializeEnvironmentAsync {
      → LaravelEnvironment.initialize()       ← extracts bundle, runs artisan
      → WebViewManager.setup()                ← configures interceptors
      → webView.loadUrl("http://127.0.0.1/")  ← triggers first PHP request
         → php_embed_init() + shutdown         ← FIRST boot of PHP, ~200ms wasted
  }
```

Everything revolves around the WebView being the entry point.

### The New Model

Flip it. PHP boots first, stays alive, and it decides whether to render native or hand off to WebView:

```
onCreate()
  → initializeEnvironmentAsync {
      → LaravelEnvironment.initialize()
      → phpRuntime.boot()                    ← PHP boots ONCE, stays alive
      → phpRuntime.dispatch("/")              ← ask PHP: "what's the start route?"
         → PHP returns: native tree           ← render with Compose
         OR
         → PHP returns: HTML response         ← hand to WebView (lazy-created)
  }
```

### Where the Time Goes Today

Every single page navigation does this:

```
php_embed_init()          ~30-80ms   ← Zend Engine boot, TSRM alloc, extension init
Composer autoload         ~20-50ms   ← require vendor/autoload.php, register classmap
Laravel bootstrap         ~40-100ms  ← service providers, config, route loading
Route + Controller        ~5-30ms    ← actual business logic
Blade/Inertia render      ~10-40ms   ← template compilation, HTML generation
php_embed_shutdown()      ~10-30ms   ← teardown everything
Output → WebView parse    ~10-20ms   ← string copy, HTTP parse, HTML render
                         ─────────
                         ~125-350ms per navigation
```

Maybe 5-30ms of that is actual code. The rest is ceremony.

In native UI mode today, each re-render is just:

```
Serialize tree to flat buffer   ~1-3ms
JNI push to Compose             ~1-2ms
Compose diff + render            ~3-8ms
                                ─────────
                                ~5-13ms per update
```

That's a 20-50x improvement per interaction.

### Performance Gains

| | Classic (today) | Persistent |
|---|---|---|
| First page load | ~300ms (full PHP boot + Laravel + render) | ~200ms boot + ~10ms dispatch |
| Subsequent navigation | ~200ms each (full re-boot) | ~10ms each (dispatch only) |
| 5 page navigations | ~1.3s total | ~250ms total |
| Memory | Freed between requests | Grows (need periodic GC) |
| State | Stateless (session files) | In-memory |

---

## Why Not Use Octane?

Octane's value is two things:

1. **The server** (Swoole/RoadRunner) — keeps PHP alive, listens for requests
2. **The sandbox** — clones the app container per request, flushes state, prevents leaks

We don't need #1. We already have something better — PHP embedded directly in-process with zero-copy shared memory. Swoole/RoadRunner are separate processes communicating over sockets. That's IPC. We've eliminated IPC entirely.

What we want is #2 — the sandbox. But even that is overkill for a mobile app.

### Do We Even Need Full Request Isolation?

In a mobile app, there's one user. One session. One auth state. The things Octane's sandbox resets are:

- **Auth guard** — same user the whole time
- **Config** — doesn't change between navigations
- **Locale** — probably static
- **Request instance** — yes, this needs resetting per dispatch
- **Resolved route** — yes, needs resetting

For a mobile app, 90% of what the sandbox does is unnecessary. We're not a web server handling requests from different users with different configs.

### The Lightweight Approach

Reset only what actually needs resetting between dispatches:

```php
app()->forgetInstance('request');
app()->forgetInstance('router');
Facade::clearResolvedInstances();
```

That's it. No sandbox. No clone. Just flush the request-specific stuff and go. If something breaks, add it to the reset list. Whitelist approach — reset only what you discover needs resetting.

---

## What We Gain

- **Laravel boots once.** Composer autoload, service providers, config loading, route registration — all the stuff that takes 100-200ms today happens at app launch and never again.
- **Every navigation after that is just your code.** Controller runs, queries the database, builds the response. No framework boot overhead. ~5-15ms instead of ~200-300ms.
- **State lives in memory.** Auth user, app config, cached queries — they survive between navigations without reading from session files or SQLite each time.
- **GC is controlled.** The reset tears down request-scoped stuff after each dispatch, `gc_collect_cycles()` keeps memory flat.
- **WebView becomes optional.** Since PHP is already alive and driving the app, native UI routes don't need HTTP. And if a route does return HTML, you feed it directly to a WebView.
- **One boot cost, paid once, amortized forever.** The app feels instant after the splash screen.

---

## What We Lose

- **Request isolation isn't free anymore.** Today, `php_embed_shutdown()` guarantees a perfectly clean slate. With the lightweight reset, you're trusting that request-scoped state gets properly cleared. If a package stores state in a static variable that the reset doesn't know about, it leaks between requests.
- **Memory grows.** PHP's memory manager was designed to allocate for one request then free everything. In persistent mode, fragmentation builds up. A long session — 200+ navigations — could see memory creep. Android will eventually kill the process if it gets too hungry.
- **Crashes are worse.** Today if PHP segfaults on one request, the next request boots fresh. In persistent mode, a segfault kills the interpreter with no recovery without restarting the whole app.
- **Not every Laravel package plays nice.** Packages that cache state, hold file handles, keep database connections in statics, or assume they run once and die — those break.
- **Debugging gets harder.** "It works on the first request but breaks on the third" is a whole class of bug that doesn't exist in the current model.
- **The mutex blocks longer.** In persistent mode with native UI, PHP holds the mutex for the entire time a screen is active. Background bridge calls or WebView requests queue behind it. Eventually need to rethink the single-mutex model.

None of these are dealbreakers. The model is proven (Octane). It shifts complexity from "pay 200ms per request" to "know your state management." A tradeoff of performance for discipline.

---

## Implementation

### PHP Side: Runtime Class

```php
// vendor/nativephp/mobile/src/Runtime.php

class Runtime
{
    protected static Application $app;
    protected static Kernel $kernel;
    protected static array $resetCallbacks = [];

    public static function boot(string $bootstrapPath): void
    {
        $app = require $bootstrapPath . '/app.php';
        static::$kernel = $app->make(Kernel::class);
        static::$kernel->bootstrap();
        static::$app = $app;
    }

    public static function dispatch(): void
    {
        // Reset state from previous dispatch
        static::reset();

        $request = Request::capture();
        $response = static::$kernel->handle($request);
        static::$kernel->terminate($request, $response);

        // Send response (captured by ub_write → g_collected_output)
        static::sendResponse($response);

        // Cleanup after dispatch
        static::reset();
        gc_collect_cycles();
    }

    public static function reset(): void
    {
        // The essentials — always reset these
        static::$app->forgetInstance('request');
        static::$app->forgetInstance(SymfonyRequest::class);
        Facade::clearResolvedInstances();

        // Run developer-registered reset callbacks
        foreach (static::$resetCallbacks as $callback) {
            $callback(static::$app);
        }
    }

    public static function onReset(callable $callback): void
    {
        static::$resetCallbacks[] = $callback;
    }
}
```

### Bootstrap Script

New file the C layer executes once at app launch:

```php
// bootstrap/android/persistent.php

require $_SERVER['COMPOSER_AUTOLOADER_PATH'];

\NativePHP\Mobile\Runtime::boot($_SERVER['LARAVEL_BOOTSTRAP_PATH']);

// Runtime is now alive — C will call Runtime::dispatch()
// via zend_eval_string for each navigation
```

### C Layer Changes

Two new functions in `php_bridge.c`:

```c
// Boot once — called at app startup
char* php_persistent_boot(const char* scriptPath) {
    pthread_mutex_lock(&g_php_request_mutex);

    setup_embed_module();
    if (php_embed_init(0, NULL) != SUCCESS) {
        pthread_mutex_unlock(&g_php_request_mutex);
        return strdup("boot_failed");
    }
    zend_register_functions(NULL, nativephp_functions, NULL, MODULE_PERSISTENT);
    php_initialized = 1;

    // Execute persistent.php — boots Laravel, returns
    zend_file_handle fh;
    zend_stream_init_filename(&fh, scriptPath);
    php_execute_script(&fh);

    // DON'T call php_embed_shutdown() — leave PHP alive
    pthread_mutex_unlock(&g_php_request_mutex);
    return strdup("ok");
}

// Dispatch a request within the living interpreter
char* php_persistent_dispatch(const char* method, const char* uri,
                               const char* postData) {
    pthread_mutex_lock(&g_php_request_mutex);
    clear_collected_output();

    setenv("REQUEST_URI", uri, 1);
    setenv("REQUEST_METHOD", method, 1);
    // ... other env vars

    // Call Runtime::dispatch() — NOT a new script, just a function call
    zend_eval_string(
        "\\NativePHP\\Mobile\\Runtime::dispatch();",
        NULL, "dispatch"
    );

    char *response = g_collected_output ? strdup(g_collected_output) : strdup("");
    pthread_mutex_unlock(&g_php_request_mutex);
    return response;
}

// Shutdown — called on app destroy
void php_persistent_shutdown() {
    pthread_mutex_lock(&g_php_request_mutex);
    if (php_initialized) {
        safe_php_embed_shutdown();
        php_initialized = 0;
    }
    pthread_mutex_unlock(&g_php_request_mutex);
}
```

### Kotlin Side

```kotlin
class PHPRuntime(private val context: Context) {
    private var booted = false

    external fun nativePersistentBoot(scriptPath: String): String
    external fun nativePersistentDispatch(
        method: String, uri: String, postData: String?
    ): String
    external fun nativePersistentShutdown()

    fun boot() {
        val script = "${getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"
        val result = nativePersistentBoot(script)
        booted = result == "ok"
    }

    fun dispatch(method: String, uri: String, postData: String? = null): String {
        if (!booted) boot()
        return nativePersistentDispatch(method, uri, postData)
    }

    fun shutdown() {
        nativePersistentShutdown()
        booted = false
    }
}
```

### Developer Configuration

In `config/nativephp.php`:

```php
return [
    'runtime' => [
        // 'persistent' (new default) or 'classic' (old boot-per-request)
        'mode' => 'persistent',

        // Run GC between dispatches (recommended)
        'gc_between_dispatches' => true,

        // Additional instances to forget between dispatches
        // For packages that cache request-scoped data
        'reset_instances' => [
            // 'translator',
            // 'url',
        ],

        // Additional singletons to flush
        'reset_singletons' => [
            // SomePackage\RequestCache::class,
        ],
    ],
];
```

Then `Runtime::reset()` reads the config:

```php
public static function reset(): void
{
    // Always reset
    static::$app->forgetInstance('request');
    static::$app->forgetInstance(SymfonyRequest::class);
    Facade::clearResolvedInstances();

    // Config-driven resets
    $config = static::$app['config']['nativephp.runtime'] ?? [];

    foreach ($config['reset_instances'] ?? [] as $instance) {
        static::$app->forgetInstance($instance);
    }

    foreach ($config['reset_singletons'] ?? [] as $singleton) {
        if (method_exists($singleton, 'flush')) {
            $singleton::flush();
        }
    }

    // Developer callbacks
    foreach (static::$resetCallbacks as $callback) {
        $callback(static::$app);
    }

    // GC
    if ($config['gc_between_dispatches'] ?? true) {
        gc_collect_cycles();
    }
}
```

### Service Provider Hook

For package authors or app developers who need custom cleanup:

```php
// In a service provider's boot() method:
use NativePHP\Mobile\Runtime;

Runtime::onReset(function ($app) {
    // Clear my package's request-scoped cache
    MyPackage\RequestStore::flush();

    // Re-bind something fresh
    $app->forgetInstance('my.service');
});
```

### Backward Compatibility

Users who want the old WebView-only mode get it through the config flag:

```php
'runtime' => [
    'mode' => 'classic',  // old behavior: boot/shutdown per request
],
```

When `runtime.mode = 'classic'`, the boot sequence falls back to exactly what exists today — WebView loads URL, PHP boots/shuts per request.

When `runtime.mode = 'persistent'` (the new default), PHP stays alive and drives everything.

### What Developers See

From the developer's perspective, nothing changes about how they write their app. Routes, controllers, Eloquent, Blade — all the same. The only new surface area is:

1. **`runtime.mode`** in config — switch between persistent and classic
2. **`runtime.reset_instances`** — if something weird happens between navigations, add it here
3. **`Runtime::onReset()`** — for package authors who hold request state

That's the whole API. Everything else is invisible.

---

## What is Garbage Collection?

Automatic memory cleanup. When PHP runs code, it allocates memory for variables, arrays, objects, etc. When those things are no longer referenced, that memory needs to be freed. PHP's GC handles this automatically — it tracks reference counts, and when something hits zero references, it frees the memory. It also has a cycle collector (`gc_collect_cycles()`) for circular references (object A references B, B references A, but nothing else references either).

In the current init/shutdown model, this doesn't matter — `php_embed_shutdown()` nukes everything. All memory freed, clean slate.

In a persistent model, PHP never shuts down. Memory accumulates. If a controller creates 10,000 objects handling a request but some don't get properly dereferenced, they leak. Over hundreds of navigations, that adds up.

The fix is calling `gc_collect_cycles()` between dispatches and being careful about what Laravel keeps in static/singleton state.