@file:Suppress("DEPRECATION")

package com.nativephp.mobile.bridge

import android.content.Context
import android.util.Log
import android.webkit.CookieManager
import org.json.JSONObject
import java.util.concurrent.ConcurrentHashMap
import com.nativephp.mobile.network.PHPRequest
import com.nativephp.mobile.security.LaravelCookieStore

class PHPBridge(private val context: Context) {
    private val postDataByKey = ConcurrentHashMap<String, String>()
    private val phpExecutor = java.util.concurrent.Executors.newSingleThreadExecutor()

    private val nativePhpScript: String
        get() = "${getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/native.php"

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

    @Volatile
    private var runtimeInitialized = false

    @Volatile
    private var persistentMode = false

    @Volatile
    private var persistentBooted = false

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

        init {
            System.loadLibrary("php_wrapper")
        }
    }

    /**
     * Boot the persistent PHP runtime. Call once during app startup.
     * PHP interpreter stays alive — no init/shutdown per request.
     */
    fun bootPersistentRuntime(): Boolean {
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
        return future.get()
    }

    /**
     * Shut down the persistent runtime. Called before hot reload reboot or app destroy.
     */
    fun shutdownPersistentRuntime() {
        if (!persistentBooted) return
        val future = phpExecutor.submit<Unit> {
            nativePersistentShutdown()
            persistentBooted = false
            Log.i(TAG, "Persistent runtime shut down")
        }
        future.get()
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
    fun bootWorkerRuntime(): Boolean {
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
    fun shutdownWorkerRuntime() {
        nativeWorkerShutdown()
        Log.i(TAG, "Worker runtime shut down")
    }

    fun handleLaravelRequest(request: PHPRequest): String {
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
                    // Manually set this cookie
                    val cookieManager = CookieManager.getInstance()
                    cookieManager.setCookie("http://127.0.0.1", cookieValue)
                    Log.d(TAG, "Manually set cookie: $cookieValue")
                }
            }

            // Make sure to flush the cookies
            CookieManager.getInstance().flush()
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
