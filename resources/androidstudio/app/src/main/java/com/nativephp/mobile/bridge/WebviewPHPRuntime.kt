package com.nativephp.mobile.bridge

import android.util.Log
import com.nativephp.mobile.network.PHPRequest
import com.nativephp.mobile.security.LaravelCookieStore
import java.util.concurrent.Executors

/**
 * A dedicated PHP context for one embedded php-mode webview.
 *
 * phpExecutor — the persistent runtime's single lane — is parked inside the
 * native screen's event-loop dispatch for the screen's whole lifetime, so it
 * can never answer requests from a webview embedded in that screen. Each
 * instance of this class owns a single-thread executor whose thread carries
 * its own TSRM context in the C bridge (thread == context): boot happens on
 * first use, requests serialize behind it, and [release] tears the context
 * down when the webview leaves the view hierarchy.
 */
class WebviewPHPRuntime(private val bridge: PHPBridge) {
    companion object {
        private const val TAG = "WebviewPHPRuntime"
    }

    private val executor = Executors.newSingleThreadExecutor { r ->
        Thread(r, "nativephp-webview-php")
    }

    @Volatile
    private var released = false

    // Executor-confined — only ever touched on the runtime's own thread.
    private var booted = false

    init {
        executor.execute {
            val rc = bridge.nativeWebviewPhpBoot(bridge.webviewBootstrapScript)
            booted = rc == 0
            Log.i(TAG, if (booted) "boot OK" else "boot FAILED ($rc)")
        }
    }

    /**
     * Serve one request on this webview's own PHP context. Blocks the caller
     * (shouldInterceptRequest needs a synchronous response); queues behind
     * the boot and any in-flight request.
     */
    fun request(request: PHPRequest): String {
        if (released) {
            return unavailable()
        }

        return try {
            executor.submit<String> {
                if (!booted) {
                    return@submit unavailable()
                }

                val cookieHeader = LaravelCookieStore.asCookieHeader()
                val contentType = request.headers["Content-Type"]
                    ?: request.headers["content-type"]
                    ?: ""

                val start = System.currentTimeMillis()
                Log.i(TAG, "--> ${request.method} ${request.uri}")

                val output = bridge.nativeWebviewPhpRequest(
                    request.method,
                    request.uri,
                    cookieHeader,
                    request.body,
                    contentType,
                    bridge.webviewNativeScript
                )

                val statusLine = output.lineSequence().firstOrNull() ?: ""
                Log.i(TAG, "<-- $statusLine (${System.currentTimeMillis() - start}ms)")

                output
            }.get()
        } catch (e: Exception) {
            "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nWebview runtime error: ${e.message}"
        }
    }

    /**
     * Stop this webview's PHP thread and free its context. Queued behind any
     * in-flight request; further requests answer 503. Idempotent.
     */
    fun release() {
        if (released) {
            return
        }
        released = true

        executor.execute {
            if (booted) {
                bridge.nativeWebviewPhpShutdown()
                booted = false
                Log.i(TAG, "context released")
            }
        }
        executor.shutdown()
    }

    private fun unavailable(): String =
        "HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/plain\r\n\r\nWebview PHP runtime unavailable."
}
