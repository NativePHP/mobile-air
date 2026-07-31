package com.nativephp.mobile.bridge

import android.util.Log
import com.nativephp.mobile.network.PHPRequest
import com.nativephp.mobile.security.LaravelCookieStore
import java.util.Collections
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.Executors
import java.util.concurrent.Future
import java.util.concurrent.RejectedExecutionException
import java.util.concurrent.TimeUnit
import java.util.concurrent.locks.ReentrantLock
import kotlin.concurrent.withLock

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
 *
 * A hot reload is the one event that reaches across those private lanes:
 * [PHPBridge.shutdownPersistentRuntime] calls php_embed_shutdown(), which
 * destroys process-wide Zend module state every webview context is built on.
 * See [suspendAllForRuntimeReboot].
 */
class WebviewPHPRuntime(private val bridge: PHPBridge) {
    companion object {
        private const val TAG = "WebviewPHPRuntime"

        /** How long a reboot waits for live contexts to come down. */
        private const val SUSPEND_TIMEOUT_MS = 5_000L

        /** How long a request parks waiting for a reboot to finish. */
        private const val REBOOT_WAIT_TIMEOUT_MS = 15_000L

        /**
         * Every un-released runtime in the process. Strong refs: [release]
         * is the sole exit, and a runtime we lost track of would keep a live
         * PHP context we can no longer suspend — exactly what breaks the
         * reboot.
         */
        private val live: MutableSet<WebviewPHPRuntime> =
            Collections.newSetFromMap(ConcurrentHashMap<WebviewPHPRuntime, Boolean>())

        private val rebootLock = ReentrantLock()
        private val rebootFinished = rebootLock.newCondition()

        @Volatile
        private var rebootInFlight = false

        /**
         * Close the reboot window and tear down every live webview context,
         * blocking until they are all gone.
         *
         * This MUST run before php_embed_shutdown() — i.e. before
         * [PHPBridge.shutdownPersistentRuntime], which is what a hot reload
         * does. That shutdown destroys the shared Zend module state every
         * webview context is built on, so a context still live across it
         * dereferences freed memory: the same hazard the queue worker is
         * stopped for, and the reason a hot reload with an embedded php
         * webview on screen took the whole app down.
         *
         * The contexts are suspended, not retired. [resumeAfterRuntimeReboot]
         * re-opens the window and each runtime boots a fresh context on its
         * next request, so a webview that survives the reload (the tree is
         * preserved across it) keeps serving — on the reloaded code.
         */
        @JvmStatic
        fun suspendAllForRuntimeReboot() {
            rebootLock.withLock { rebootInFlight = true }

            val runtimes = live.toList()
            if (runtimes.isEmpty()) {
                return
            }

            Log.i(TAG, "suspending ${runtimes.size} context(s) for runtime reboot")

            // Bounded — a wedged context must not deadlock the reload.
            // Timing out means we are about to reboot with a live context
            // attached, so say so loudly: that is the crash this prevents.
            val deadline = System.currentTimeMillis() + SUSPEND_TIMEOUT_MS
            runtimes.mapNotNull { it.suspendForReboot() }.forEach { pending ->
                val remaining = (deadline - System.currentTimeMillis()).coerceAtLeast(0)
                try {
                    pending.get(remaining, TimeUnit.MILLISECONDS)
                } catch (e: Exception) {
                    Log.e(
                        TAG,
                        "⚠️ suspend did not complete (${e.javaClass.simpleName}) — " +
                            "rebooting with a live context still attached"
                    )
                }
            }
        }

        /**
         * Re-open the reboot window once the persistent runtime is back up.
         * Requests parked in [request] wake and boot themselves a fresh
         * context. No-op when no reboot was in flight.
         */
        @JvmStatic
        fun resumeAfterRuntimeReboot() {
            rebootLock.withLock {
                if (!rebootInFlight) {
                    return
                }
                rebootInFlight = false
                rebootFinished.signalAll()
            }
            Log.i(TAG, "reboot window closed — contexts re-boot on next request")
        }

        /** Park the calling lane while a persistent-runtime reboot is in flight. */
        private fun awaitRuntimeReboot() {
            if (!rebootInFlight) {
                return
            }

            rebootLock.withLock {
                // Bounded so a reload that never completes degrades to a 503
                // rather than a webview that hangs forever.
                var remaining = TimeUnit.MILLISECONDS.toNanos(REBOOT_WAIT_TIMEOUT_MS)
                while (rebootInFlight && remaining > 0) {
                    remaining = try {
                        rebootFinished.awaitNanos(remaining)
                    } catch (e: InterruptedException) {
                        Thread.currentThread().interrupt()
                        return
                    }
                }
            }
        }
    }

    private val executor = Executors.newSingleThreadExecutor { r ->
        Thread(r, "nativephp-webview-php")
    }

    @Volatile
    private var released = false

    // Executor-confined — only ever touched on the runtime's own thread.
    private var booted = false
    private var needsBoot = true

    init {
        live.add(this)
        executor.execute { boot() }
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
                if (needsBoot) {
                    // No context right now — most likely a hot reload just
                    // suspended it. Hold the request until the persistent
                    // runtime is back rather than answering 503, so the
                    // webview renders the reloaded code instead of an error
                    // page. Only requests that actually need a context wait,
                    // so a request already queued ahead of the suspend still
                    // completes on the old context (and can't deadlock the
                    // suspend behind it).
                    awaitRuntimeReboot()
                    boot()
                }

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
        live.remove(this)

        executor.execute {
            if (booted) {
                bridge.nativeWebviewPhpShutdown()
                booted = false
                Log.i(TAG, "context released")
            }
            needsBoot = false
        }
        executor.shutdown()
    }

    /** Boot this runtime's PHP context. Executor-confined. */
    private fun boot() {
        if (released) {
            return
        }

        // Never boot across a persistent-runtime reboot. With the persistent
        // runtime down, ephemeral_embed_init takes its COLD path and calls
        // php_embed_init() beside the runtime that is about to re-boot,
        // corrupting TSRM/SAPI globals. Defer to the next request.
        if (rebootInFlight) {
            needsBoot = true
            return
        }

        val rc = bridge.nativeWebviewPhpBoot(bridge.webviewBootstrapScript)
        booted = rc == 0
        // Leave the door open on failure — the next request retries rather
        // than leaving the webview permanently dead.
        needsBoot = !booted
        Log.i(TAG, if (booted) "boot OK" else "boot FAILED ($rc)")
    }

    /**
     * Stop this runtime's context while keeping the runtime usable — the next
     * request boots a fresh one. Queued behind any in-flight request, so a
     * response already being generated still completes on the old context
     * before it goes away.
     */
    private fun suspendForReboot(): Future<*>? {
        if (released) {
            return null
        }

        return try {
            // Explicit Runnable: submit(Runnable) vs submit(Callable) is
            // ambiguous for a Unit-returning lambda.
            executor.submit(Runnable {
                if (booted) {
                    bridge.nativeWebviewPhpShutdown()
                    booted = false
                    Log.i(TAG, "context suspended for runtime reboot")
                }
                needsBoot = true
            })
        } catch (e: RejectedExecutionException) {
            // Released between the check above and the submit.
            null
        }
    }

    private fun unavailable(): String =
        "HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/plain\r\n\r\nWebview PHP runtime unavailable."
}
