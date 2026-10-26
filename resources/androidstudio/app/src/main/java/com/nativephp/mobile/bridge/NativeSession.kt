package com.nativephp.mobile.bridge

import android.content.Context
import android.os.SystemClock
import android.util.Log
import java.io.File

/**
 * Runs a native session: the one persistent-runtime dispatch of a native
 * route that drives the native UI until the app leaves it.
 *
 * PHP can die part-way through that dispatch. A fatal error (most often the
 * memory limit, since the session is a single request that lives as long as
 * the app) bails out of the runloop, and the dispatch returns early with
 * whatever PHP printed instead of an HTTP response. Nothing drives the screen
 * after that: it stays on its last frame, its polls stop, and every later
 * event goes unanswered.
 *
 * So when that happens the runtime is rebooted and the start route
 * dispatched again. Rebooting matters: after a fatal error PHP keeps the dead
 * session's memory, marks every object as destructed and stops collecting
 * cycles, so the same runtime would only die again sooner. A session that
 * keeps dying is given up on after a few restarts, so a broken start route
 * can't loop forever.
 */
object NativeSession {
    private const val TAG = "NativeSession"
    private const val MAX_RESTARTS = 3
    private const val RESTART_WINDOW_MS = 60_000L

    private val restarts = mutableListOf<Long>()

    /**
     * Where the restarted session finds what PHP printed when it died, to
     * log it, relative to the app's storage dir. PHP can't log a fatal error
     * itself here: Laravel reports those from a shutdown function, and the
     * persistent runtime's request never shuts down. Must match
     * `NativeRouter::CRASH_OUTPUT_PATH` under storage_path().
     */
    const val CRASH_OUTPUT_PATH = "persisted_data/storage/framework/.native_session_crash"

    /**
     * Dispatch [uri] as a native session and block until it ends, restarting
     * it if PHP dies part-way. [rebootRuntime] reboots the persistent runtime
     * the way hot reload does. Returns the session's exit response. Call it
     * off the main thread.
     */
    fun run(context: Context, phpBridge: PHPBridge, uri: String, rebootRuntime: () -> Unit): String {
        var uri = uri

        while (true) {
            val response = phpBridge.executeNativeRoute(uri)

            // Every session that ends on its own answers with an HTTP response:
            // EXIT_WEB's redirect, hot reload's 204, or an empty 200.
            if (phpBridge.isCompleteResponse(response)) {
                return String(phpBridge.processRawPHPResponse(response), Charsets.UTF_8)
            }

            val output = String(response, 0, minOf(response.size, 8 * 1024), Charsets.UTF_8).trim()
            Log.e(TAG, "PHP stopped part-way through the native session at $uri: $output")

            if (!mayRestart()) {
                Log.e(TAG, "The native session died $MAX_RESTARTS times in ${RESTART_WINDOW_MS / 1000}s, not restarting it again")
                return String(phpBridge.processRawPHPResponse(response), Charsets.UTF_8)
            }

            keepCrashOutput(context, output)
            rebootRuntime()

            uri = LaravelEnvironment.getStartURL(context)
            Log.i(TAG, "Restarting the native session at $uri")
        }
    }

    private fun mayRestart(): Boolean = synchronized(restarts) {
        val now = SystemClock.elapsedRealtime()
        restarts.removeAll { now - it >= RESTART_WINDOW_MS }
        if (restarts.size >= MAX_RESTARTS) return false
        restarts.add(now)

        true
    }

    private fun keepCrashOutput(context: Context, output: String) {
        try {
            File(context.getDir("storage", Context.MODE_PRIVATE), CRASH_OUTPUT_PATH).writeText(output)
        } catch (e: Exception) {
            Log.w(TAG, "Could not keep the dead session's output: ${e.message}")
        }
    }
}
