@file:Suppress("DEPRECATION")

package com.nativephp.mobile.bridge

import android.content.Context
import android.util.Log
import android.webkit.CookieManager
import org.json.JSONObject
import java.util.concurrent.ConcurrentHashMap
import com.nativephp.mobile.network.PHPRequest
import com.nativephp.mobile.security.LaravelCookieStore
import kotlin.concurrent.withLock

class PHPBridge(private val context: Context) {
    private var lastPostData: String? = null
    private val requestDataMap = ConcurrentHashMap<String, String>()
    private val postDataByKey = ConcurrentHashMap<String, String>()

    private val nativePhpScript: String
        get() = "${getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/native.php"

    /**
     * Serves this bridge's requests on a dedicated per-webview PHP context
     * instead of phpExecutor — REQUIRED for php-mode webviews embedded in
     * native screens, where phpExecutor is parked inside the screen's
     * event-loop dispatch and would never answer.
     */
    var dedicatedWebviewRuntime: WebviewPHPRuntime? = null

    internal val webviewBootstrapScript: String
        get() = "${getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"

    internal val webviewNativeScript: String
        get() = nativePhpScript

    private val persistentBootstrapScript: String
        get() = "${getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"

    private val workerBootstrapScript: String
        get() = "${getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"

    external fun nativeExecuteScript(filename: String): String
    external fun nativeSetEnv(name: String, value: String, overwrite: Int): Int
    external fun runArtisanCommand(command: String): String
    external fun initialize()
    external fun setRequestInfo(method: String, uri: String, postData: String?)
    external fun getLaravelPublicPath(): String
    external fun getLaravelRootPath(): String
    external fun shutdown()
    external fun nativeRuntimeInit()
    external fun nativeRuntimeShutdown()
    external fun nativeHandleRequest(
        method: String,
        uri: String,
        postData: String?,
        scriptPath: String
    ): String
    external fun nativeHandleRequestOnce(
        method: String,
        uri: String,
        postData: String?,
        scriptPath: String
    ): String

    // Persistent runtime JNI methods
    external fun nativePersistentBoot(bootstrapPath: String): Int
    external fun nativePersistentDispatch(
        method: String,
        uri: String,
        postData: String?,
        scriptPath: String
    ): String
    external fun nativePersistentArtisan(command: String): String
    external fun nativePersistentShutdown()

    // Worker (background queue) JNI methods — runs on a separate thread with its own TSRM context
    external fun nativeWorkerBoot(bootstrapPath: String): Int
    external fun nativeWorkerArtisan(command: String): String
    external fun nativeWorkerShutdown()

    // Ephemeral runtime JNI methods — generic background TSRM context for plugin use
    external fun nativeEphemeralBoot(bootstrapPath: String): Int
    external fun nativeEphemeralArtisan(command: String): String
    external fun nativeEphemeralShutdown()

    // Webview runtime (dedicated context per embedded php-mode webview).
    // Call ONLY from that webview's single-thread executor — the TSRM
    // context is bound to the calling thread.
    external fun nativeWebviewPhpBoot(bootstrapPath: String): Int
    external fun nativeWebviewPhpRequest(
        method: String,
        uri: String,
        cookieHeader: String,
        body: String,
        contentType: String,
        scriptPath: String
    ): String
    external fun nativeWebviewPhpShutdown()

    fun ensureRuntimeInitialized() {
        if (!runtimeInitialized) {
            nativeRuntimeInit()
            runtimeInitialized = true
            Log.i(TAG, "PHP runtime initialized (persistent)")
        }
    }

    companion object {
        private const val TAG = "PHPBridge"
        private const val MAX_REQUEST_AGE = 5 * 60 * 1000L

        // ── PROCESS-level runtime state ──────────────────────────────
        // MainActivity constructs a fresh PHPBridge per activity, but the
        // native PHP runtime, its single executor thread, and the
        // booted/initialized flags belong to the PROCESS. When a plugin
        // foreground service keeps the process alive across activity
        // re-creation, a new activity's bridge instance must see (and
        // reuse) the runtime the previous one booted — instance-level
        // flags made it boot a SECOND runtime beside the live one, which
        // hangs in native code. Park/reuse only works with this state
        // shared here.
        private val phpExecutor = java.util.concurrent.Executors.newSingleThreadExecutor()

        @Volatile
        private var runtimeInitialized = false

        @Volatile
        private var persistentMode = false

        @Volatile
        private var persistentBooted = false

        /**
         * Every `php_embed_init` / `php_embed_shutdown` in this process runs
         * under this lock, whichever lane asks for it.
         *
         * `php_embed_init` is process-global however many TSRM contexts the
         * lanes end up owning: it walks the module registry and allocates
         * thread-resource ids, and two of them running at once corrupt that
         * state rather than sharing it. Two concurrent boots SEGV inside
         * `ts_allocate_fast_id` → `tsrm_update_active_threads` →
         * `cwd_globals_ctor` → `_emalloc`, and the crash takes the whole
         * process down — including the activity that was booting.
         *
         * The persistent lane has always taken this lock (see
         * `LaravelEnvironment.extractionLock`, whose comment describes the
         * same hazard against the classic embed cycles). The worker lane, the
         * webview lane and the ephemeral lane did not, and the ephemeral one
         * has no wrapper at all — a plugin reaches it through the raw
         * `nativeEphemeralBoot` external. A background job starting while the
         * user opens the app is not an unusual moment; for anything shipping
         * a widget, a shortcut or a notification it is the normal one.
         *
         * Held across boot *and* shutdown, because a shutdown landing in the
         * middle of another lane's boot is the same collision from the other
         * side. Not held for the life of a runtime: the lanes are built to
         * run concurrently once they exist, and they do.
         */
        internal val embedLifecycleLock = LaravelEnvironment.extractionLock

        init {
            System.loadLibrary("php_wrapper")
        }
    }

    /**
     * Boot the persistent PHP runtime. Call once during app startup.
     * PHP interpreter stays alive — no init/shutdown per request.
     */
    fun bootPersistentRuntime(): Boolean = LaravelEnvironment.extractionLock.withLock {
        // The lock is shared with LaravelEnvironment.initialize(): a
        // concurrently-created activity may still be extracting the bundle or
        // cycling classic-embed artisan commands, and the persistent
        // php_embed_init must not overlap either (different native mutexes;
        // a classic php_embed_shutdown mid-boot guts the persistent
        // interpreter — it "boots" in ~10ms with no classes loaded and every
        // dispatch 500s until the process dies).

        // Process reuse: a plugin foreground service kept the process alive
        // past the previous activity, which parked (not tore down) the
        // runtime. Reuse it — native PHP re-init in a used process is
        // exactly what SEGVs/hangs.
        if (persistentBooted) {
            Log.i(TAG, "Persistent runtime already booted — reusing (process outlived its activity)")
            WebviewPHPRuntime.resumeAfterRuntimeReboot()
            return true
        }

        val future = phpExecutor.submit<Boolean> {
            val start = System.currentTimeMillis()

            // Set up env vars needed for bootstrap
            ensureRuntimeInitialized()

            val result = nativePersistentBoot(persistentBootstrapScript)
            val elapsed = System.currentTimeMillis() - start

            if (result == 0) {
                persistentBooted = true
                persistentMode = true
                Log.i(TAG, "Persistent runtime booted in ${elapsed}ms")
                true
            } else {
                Log.e(TAG, "Persistent runtime boot FAILED (code=$result) after ${elapsed}ms")
                false
            }
        }
        val booted = future.get()

        // Re-open the window shutdownPersistentRuntime() closed, so webview
        // contexts suspended for this reboot can boot again. No-op on a cold
        // launch.
        WebviewPHPRuntime.resumeAfterRuntimeReboot()

        return booted
    }

    /**
     * Park the persistent runtime: end the element runloop (freeing the
     * single PHP executor thread) but leave the runtime booted and all
     * native PHP state untouched. Used at activity destroy — the process
     * may outlive the activity (plugin foreground services), and native
     * PHP does not survive a full teardown + re-init in the same process
     * (TSRM SEGV in ts_resource_ex; nativePersistentBoot hangs). A
     * re-created activity reuses the parked runtime via
     * bootPersistentRuntime()'s already-booted guard; when nothing pins
     * the process, the OS reclaims everything anyway.
     *
     * No blocking wait: the loop exits asynchronously, and any work a new
     * activity queues on phpExecutor is naturally serialized behind it.
     */
    fun parkPersistentRuntime() {
        if (!persistentBooted) return
        Log.i(TAG, "Parking persistent runtime — asking runloop to exit")
        try {
            com.nativephp.mobile.ui.nativerender.NativeElementBridge.sendShutdownEvent()
        } catch (t: Throwable) {
            Log.d(TAG, "park wake skipped: ${t.javaClass.simpleName}")
        }
    }

    /**
     * Shut down the persistent runtime. Called before hot reload reboot,
     * which re-boots immediately afterwards on the same executor — the one
     * teardown/re-init cycle native PHP handles reliably.
     *
     * The PHP runloop occupies the single phpExecutor thread, parked inside
     * `nativephp_element_wait_event`, so a shutdown task queued here can only
     * run after the loop exits. Post a SHUTDOWN event first to wake the loop
     * and make it return (hot reload already worked because it posts its own
     * HOT_RELOAD event; a plain activity destroy posted nothing and hung the
     * main thread forever once a foreground service kept the process alive).
     * The bounded get() is a backstop: an unkillable runtime must not take
     * the main thread down with it — worst case we leak the runtime thread
     * in a process that is already tearing down.
     */
    fun shutdownPersistentRuntime() {
        if (!persistentBooted) return

        // Embedded php-mode webviews each own a PHP context on their own
        // thread, built on the process-wide Zend module state that
        // php_embed_shutdown() is about to destroy. Take them down first or
        // they are left dereferencing freed memory — same hazard as the
        // queue worker, and why a hot reload with a php webview on screen
        // crashed. They re-boot on their next request once
        // bootPersistentRuntime() reopens the window.
        WebviewPHPRuntime.suspendAllForRuntimeReboot()

        try {
            com.nativephp.mobile.ui.nativerender.NativeElementBridge.sendShutdownEvent()
        } catch (t: Throwable) {
            // No element region (webview-only screen) or JNI unavailable —
            // the executor may already be free; fall through to the wait.
            Log.d(TAG, "shutdown wake skipped: ${t.javaClass.simpleName}")
        }

        val future = phpExecutor.submit<Unit> {
            embedLifecycleLock.withLock {
                nativePersistentShutdown()
            }
            persistentBooted = false
            Log.i(TAG, "Persistent runtime shut down")
        }
        try {
            future.get(5, java.util.concurrent.TimeUnit.SECONDS)
        } catch (e: java.util.concurrent.TimeoutException) {
            Log.e(TAG, "Persistent runtime shutdown timed out — runloop did not exit; abandoning")
            future.cancel(true)
        }
    }

    /**
     * Run an artisan command through the persistent interpreter (no boot/shutdown per command).
     */
    fun runPersistentArtisan(command: String): String {
        if (!persistentBooted) {
            Log.w(TAG, "Persistent runtime not booted, falling back to classic artisan")
            return runArtisanCommand(command)
        }
        val future = phpExecutor.submit<String> {
            nativePersistentArtisan(command)
        }
        return future.get()
    }

    fun isPersistentMode(): Boolean = persistentMode && persistentBooted

    /**
     * Boot the worker PHP runtime on a dedicated TSRM context.
     * Does NOT use phpExecutor — no contention with UI requests.
     */
    fun bootWorkerRuntime(): Boolean = embedLifecycleLock.withLock {
        ensureRuntimeInitialized()
        val result = nativeWorkerBoot(workerBootstrapScript)
        if (result == 0) {
            Log.i(TAG, "Worker runtime booted")
        } else {
            Log.e(TAG, "Worker runtime boot FAILED (code=$result)")
        }
        return result == 0
    }

    /**
     * Run an artisan command through the worker interpreter.
     * Runs on the caller's thread — no phpExecutor involvement.
     */
    fun runWorkerArtisan(command: String): String {
        return nativeWorkerArtisan(command)
    }

    /**
     * Shut down the worker runtime.
     */
    fun shutdownWorkerRuntime() = embedLifecycleLock.withLock {
        nativeWorkerShutdown()
        Log.i(TAG, "Worker runtime shut down")
    }

    /**
     * Boot the ephemeral PHP runtime — the generic background TSRM context
     * documented for plugin use, and the lane a WorkManager job takes when it
     * started the process after the app was killed.
     *
     * Runs on the caller's thread and must: `ephemeral_embed_init` does
     * `ts_resource(0)` on whichever thread calls it and the shutdown does
     * `ts_free_thread()`, so boot, run and shutdown all belong to one thread.
     * Callers are expected to own a single-thread lane for that reason.
     *
     * Prefer this over calling [nativeEphemeralBoot] directly: the raw
     * external does not serialise against the other lanes' `php_embed_init`,
     * and a background job booting at the same moment as a cold app launch
     * SEGVs the process. See [embedLifecycleLock].
     */
    fun bootEphemeralRuntime(bootstrapPath: String = persistentBootstrapScript): Boolean =
        embedLifecycleLock.withLock {
            ensureRuntimeInitialized()
            val result = nativeEphemeralBoot(bootstrapPath)
            if (result == 0) {
                Log.i(TAG, "Ephemeral runtime booted")
            } else {
                Log.e(TAG, "Ephemeral runtime boot FAILED (code=$result)")
            }
            return result == 0
        }

    /**
     * Run an artisan command through the ephemeral interpreter. The lane has
     * no dispatch: an artisan command is how background PHP is reached.
     *
     * Deliberately not under [embedLifecycleLock] — the command is the work,
     * it can run for minutes, and holding the lock would make every other
     * lane (a cold app launch among them) wait for it. Only the init and the
     * shutdown have to be exclusive.
     */
    fun runEphemeralArtisan(command: String): String = nativeEphemeralArtisan(command)

    /**
     * Shut the ephemeral runtime down. Always — an ephemeral runtime left
     * booted by a background job is a `php_embed_init` the user's next app
     * launch has to survive.
     */
    fun shutdownEphemeralRuntime() = embedLifecycleLock.withLock {
        nativeEphemeralShutdown()
        Log.i(TAG, "Ephemeral runtime shut down")
    }

    fun handleLaravelRequest(request: PHPRequest): String {
        // Embedded php-mode webview — its own context serves the request;
        // phpExecutor may be parked inside a native screen's event-loop
        // dispatch and would never answer.
        dedicatedWebviewRuntime?.let {
            return processRawPHPResponse(it.request(request))
        }

        val requestStart = System.currentTimeMillis()

        val future = phpExecutor.submit<String> {
            val prepStart = System.currentTimeMillis()

            // Clear Inertia-related env vars first - they persist between requests
            // and cause Laravel to return JSON instead of HTML
            val inertiaEnvVars = listOf(
                "HTTP_X_INERTIA",
                "HTTP_X_INERTIA_VERSION",
                "HTTP_X_INERTIA_PARTIAL_DATA",
                "HTTP_X_INERTIA_PARTIAL_COMPONENT",
                "HTTP_X_INERTIA_PARTIAL_EXCEPT"
            )
            inertiaEnvVars.forEach { envVar ->
                nativeSetEnv(envVar, "", 1)
            }

            request.headers.forEach { (key, value) ->
                val envKey = "HTTP_" + key.replace("-", "_").uppercase()
                nativeSetEnv(envKey, value, 1)
            }

            val cookieHeader = LaravelCookieStore.asCookieHeader()
            nativeSetEnv("HTTP_COOKIE", cookieHeader, 1)

            val prepTime = System.currentTimeMillis() - prepStart
            val jniStart = System.currentTimeMillis()

            val output = if (persistentMode && persistentBooted) {
                // Persistent mode: dispatch through the already-running interpreter
                nativePersistentDispatch(
                    request.method,
                    request.uri,
                    request.body,
                    nativePhpScript
                )
            } else {
                // Classic mode: full init/shutdown per request
                ensureRuntimeInitialized()
                nativeHandleRequest(
                    request.method,
                    request.uri,
                    request.body,
                    nativePhpScript
                )
            }

            val jniTime = System.currentTimeMillis() - jniStart
            val processStart = System.currentTimeMillis()

            val processedOutput = processRawPHPResponse(output)

            val processTime = System.currentTimeMillis() - processStart
            val mode = if (persistentMode && persistentBooted) "PERSISTENT" else "CLASSIC"
            Log.d("PerfTiming", "BRIDGE[$mode] [${request.uri}] prep=${prepTime}ms jni=${jniTime}ms process=${processTime}ms")

            processedOutput
        }

        val result = future.get()
        val totalTime = System.currentTimeMillis() - requestStart
        Log.d("PerfTiming", "BRIDGE_TOTAL [${request.uri}] ${totalTime}ms")
        return result
    }

    // New function to store request data with a key
    fun storeRequestData(key: String, data: String) {
        requestDataMap[key] = data
        Log.d(TAG, "Stored request data with key: $key (length=${data.length})")

        // Also update last post data for backward compatibility
        lastPostData = data

        // Clean up old requests occasionally
        if (requestDataMap.size > 10) {
            cleanupOldRequests()
        }
    }

    // Clean up old request data
    private fun cleanupOldRequests() {
        val now = System.currentTimeMillis()
        val keysToRemove = mutableListOf<String>()

        // Find keys with timestamps older than MAX_REQUEST_AGE
        requestDataMap.keys.forEach { key ->
            if (key.contains("-")) {
                val timestampStr = key.substringAfterLast("-")
                try {
                    val timestamp = timestampStr.toLong()
                    if (now - timestamp > MAX_REQUEST_AGE) {
                        keysToRemove.add(key)
                    }
                } catch (e: NumberFormatException) {
                    // Key doesn't have a valid timestamp format, ignore
                }
            }
        }

        // Remove old entries
        keysToRemove.forEach { requestDataMap.remove(it) }
        if (keysToRemove.isNotEmpty()) {
            Log.d(TAG, "Cleaned up ${keysToRemove.size} old request entries")
        }
    }

    fun storePostData(key: String, data: String) {
        postDataByKey[key] = data
        Log.d(TAG, "Stored POST data for key=$key (length=${data.length})")
    }

    fun consumePostData(key: String): String? {
        // Try immediate lookup
        var data = postDataByKey.remove(key)

        // If not found, the JS bridge may not have fired yet — wait briefly
        if (data == null) {
            for (i in 1..10) {
                Thread.sleep(5)
                data = postDataByKey.remove(key)
                if (data != null) {
                    Log.d(TAG, "POST data for key=$key arrived after ${i * 5}ms wait")
                    break
                }
            }
        }

        if (data != null) {
            Log.d(TAG, "Consumed POST data for key=$key (length=${data.length})")
        } else {
            Log.w(TAG, "No POST data for key=$key after 50ms — request may have no body")
        }
        return data
    }

    /**
     * Re-execute a native route with a fresh PHP process.
     * Used by hot reload to restart native UI with updated class definitions.
     * Blocks the calling thread for the duration of the native UI session.
     */
    fun executeNativeRoute(uri: String) {
        val future = phpExecutor.submit<Unit> {
            if (persistentMode && persistentBooted) {
                nativePersistentDispatch("GET", uri, null, nativePhpScript)
            } else {
                ensureRuntimeInitialized()
                nativeHandleRequest("GET", uri, null, nativePhpScript)
            }
        }
        future.get()  // Block caller — native UI route runs until navigation exits
    }

    fun getLastPostData(): String? {
        return lastPostData
    }

    fun getLaravelPath(): String {
        val storageDir = context.getDir("storage", Context.MODE_PRIVATE)
        return "${storageDir.absolutePath}/laravel"
    }

    fun processRawPHPResponse(response: String): String {
        // Log the first 200 characters to understand the response format
        Log.d(TAG, "Response first 200 chars: ${response.take(200)}")

        // Check for Set-Cookie headers regardless of response format
        if (response.contains("Set-Cookie:", ignoreCase = true)) {
            Log.d(TAG, "Found Set-Cookie in raw response!")

            // Extract all Set-Cookie lines
            val setCookieLines = response.split("\r\n")
                .filter { it.startsWith("Set-Cookie:", ignoreCase = true) }

            setCookieLines.forEach { cookieLine ->
                Log.d(TAG, "Cookie line: $cookieLine")

                // Extract the cookie value (after "Set-Cookie:")
                val cookieValue = cookieLine.substringAfter(":", "").trim()
                if (cookieValue.isNotEmpty()) {
                    // Store is the source of truth; the WebView jar is a
                    // gated mirror (no-op until a WebRenderer exists, so a
                    // native-only boot never loads the Chromium provider).
                    com.nativephp.mobile.security.LaravelCookieStore.storeFromSetCookieHeader(cookieValue)
                    com.nativephp.mobile.security.WebCookieMirror.set(cookieValue)
                    Log.d(TAG, "Stored cookie: $cookieValue")
                }
            }

            com.nativephp.mobile.security.WebCookieMirror.flush()
            Log.d(TAG, "Flushed cookies after extraction")
        } else {
            Log.d(TAG, "No Set-Cookie headers found in the response")
        }

        // Continue with your existing logic for different response types
        if (response.trim().startsWith("{") && response.trim().endsWith("}")) {
            try {
                val json = JSONObject(response)
                if (json.has("message") && json.getString("message")
                        .contains("CSRF token mismatch")
                ) {
                    Log.e(TAG, "CSRF token mismatch detected. Adding 419 status.")
                    return "HTTP/1.1 419 Page Expired\r\n" +
                            "Content-Type: application/json\r\n" +
                            "X-CSRF-Error: true\r\n" +
                            "\r\n" +
                            response
                }

                // Regular JSON response
                return "HTTP/1.1 200 OK\r\n" +
                        "Content-Type: application/json\r\n" +
                        "\r\n" +
                        response
            } catch (e: Exception) {
                Log.e(TAG, "Error parsing JSON response", e)
            }
        }

        // If it already has headers (check for common header fields)
        if (response.contains("Content-Type:", ignoreCase = true) ||
            response.contains("Set-Cookie:", ignoreCase = true)
        ) {

            // It has some headers, but might not have the status line
            // Add a status line if it doesn't have one
            if (!response.startsWith("HTTP/")) {
                return "HTTP/1.1 200 OK\r\n" + response
            }
            return response
        }

        // Default case: assume it's just content without headers
        return "HTTP/1.1 200 OK\r\n" +
                "Content-Type: text/html\r\n" +
                "\r\n" +
                response
    }

    // All native bridge methods have been migrated to god method pattern
    // See BridgeFunctionRegistry.kt and bridge/functions/* for implementations
}
