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
    private var lastPostData: String? = null
    private val requestDataMap = ConcurrentHashMap<String, String>()
    private val phpExecutor = java.util.concurrent.Executors.newSingleThreadExecutor()

    private val nativePhpScript: String
        get() = "${getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/native.php"

    external fun nativeExecuteScript(filename: String): String
    external fun nativeSetEnv(name: String, value: String, overwrite: Int): Int
    external fun runArtisanCommand(command: String): String
    external fun initialize()
    external fun setRequestInfo(method: String, uri: String, postData: String?)
    external fun getLaravelPublicPath(): String
    external fun getLaravelRootPath(): String
    external fun shutdown()
    external fun nativeHandleRequestOnce(
        method: String,
        uri: String,
        postData: String?,
        scriptPath: String
    ): String


    companion object {
        private const val TAG = "PHPBridge"
        private const val MAX_REQUEST_AGE = 5 * 60 * 1000L

        @Volatile
        private var instance: PHPBridge? = null

        /**
         * Get the singleton instance of PHPBridge.
         * Returns null if no instance has been created yet.
         * Used by BackgroundQueueWorker to access the bridge from background context.
         */
        fun getInstance(): PHPBridge? = instance

        /**
         * Set the singleton instance of PHPBridge.
         * Called by MainActivity when creating the bridge.
         */
        fun setInstance(bridge: PHPBridge) {
            instance = bridge
        }

        init {
            System.loadLibrary("compat")
            System.loadLibrary("php")
            System.loadLibrary("php_wrapper")
        }
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

            Log.d(TAG, "🍪 Sent HTTP_COOKIE to native: $cookieHeader")

            initialize()

            val prepTime = System.currentTimeMillis() - prepStart
            val jniStart = System.currentTimeMillis()

            val output = nativeHandleRequestOnce(
                request.method,
                request.uri,
                request.body,
                nativePhpScript
            )

            val jniTime = System.currentTimeMillis() - jniStart
            val processStart = System.currentTimeMillis()

            val processedOutput = processRawPHPResponse(output)

            val processTime = System.currentTimeMillis() - processStart
            Log.d("PerfTiming", "⏱️ BRIDGE [${request.uri}] prep=${prepTime}ms jni=${jniTime}ms process=${processTime}ms")

            processedOutput
        }

        val result = future.get()
        val totalTime = System.currentTimeMillis() - requestStart
        Log.d("PerfTiming", "⏱️ BRIDGE_TOTAL [${request.uri}] ${totalTime}ms")
        return result
    }

    // New function to store request data with a key
    fun storeRequestData(key: String, data: String) {
        requestDataMap[key] = data
        Log.d(TAG, "🔑 Stored request data with key: $key (length=${data.length})")

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
            Log.d(TAG, "🧹 Cleaned up ${keysToRemove.size} old request entries")
        }
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
        Log.d(TAG, "🔍 Response first 200 chars: ${response.take(200)}")

        // Check for Set-Cookie headers regardless of response format
        if (response.contains("Set-Cookie:", ignoreCase = true)) {
            Log.d(TAG, "🍪 Found Set-Cookie in raw response!")

            // Extract all Set-Cookie lines
            val setCookieLines = response.split("\r\n")
                .filter { it.startsWith("Set-Cookie:", ignoreCase = true) }

            setCookieLines.forEach { cookieLine ->
                Log.d(TAG, "🍪 Cookie line: $cookieLine")

                // Extract the cookie value (after "Set-Cookie:")
                val cookieValue = cookieLine.substringAfter(":", "").trim()
                if (cookieValue.isNotEmpty()) {
                    // Manually set this cookie
                    val cookieManager = CookieManager.getInstance()
                    cookieManager.setCookie("http://127.0.0.1", cookieValue)
                    Log.d(TAG, "🍪 Manually set cookie: $cookieValue")
                }
            }

            // Make sure to flush the cookies
            CookieManager.getInstance().flush()
            Log.d(TAG, "🍪 Flushed cookies after extraction")
        } else {
            Log.d(TAG, "⚠️ No Set-Cookie headers found in the response")
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