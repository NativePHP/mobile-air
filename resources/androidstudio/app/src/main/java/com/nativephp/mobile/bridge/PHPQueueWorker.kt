package com.nativephp.mobile.bridge

import android.util.Log

/**
 * Background queue worker that processes Laravel queue jobs.
 *
 * Schedules `queue:work --once` calls through the persistent runtime's artisan
 * method. PHP execution is serialized with UI requests via the phpExecutor,
 * but the scheduling loop runs on its own thread so jobs process automatically
 * between UI requests without any manual triggering.
 */
class PHPQueueWorker(private val phpBridge: PHPBridge) {

    companion object {
        private const val TAG = "PHPQueueWorker"
        private const val SLEEP_INTERVAL_MS = 1000L
        private const val SLEEP_IDLE_MS = 3000L
    }

    private var workerThread: Thread? = null

    @Volatile
    private var running = false

    @Volatile
    private var paused = false

    /**
     * Start the background queue worker thread.
     * No separate PHP boot needed — uses the main persistent runtime.
     */
    fun start() {
        if (running) {
            Log.w(TAG, "Worker already running")
            return
        }

        running = true
        paused = false

        workerThread = Thread({
            Log.i(TAG, "Queue worker started (serialized mode)")

            while (running) {
                if (paused) {
                    try {
                        Thread.sleep(500)
                    } catch (e: InterruptedException) {
                        // check running flag
                    }
                    continue
                }

                try {
                    val output = phpBridge.runPersistentArtisan("queue:work --once --quiet")
                    if (output.isNotEmpty() && output != "0") {
                        Log.d(TAG, "Job output: ${output.take(200)}")
                    }

                    // Shorter sleep if we just processed a job, longer if idle
                    val sleepMs = if (output.contains("Processed", ignoreCase = true)) {
                        SLEEP_INTERVAL_MS
                    } else {
                        SLEEP_IDLE_MS
                    }
                    Thread.sleep(sleepMs)
                } catch (e: InterruptedException) {
                    Log.d(TAG, "Worker sleep interrupted")
                } catch (e: Exception) {
                    Log.e(TAG, "Worker error", e)
                    try { Thread.sleep(SLEEP_IDLE_MS) } catch (_: InterruptedException) {}
                }
            }

            Log.i(TAG, "Queue worker stopped")
        }, "php-queue-worker").apply {
            isDaemon = true
            start()
        }

        Log.i(TAG, "Queue worker thread launched")
    }

    /**
     * Pause job processing (e.g., during hot reload).
     */
    fun pause() {
        if (!running) return
        paused = true
        Log.i(TAG, "Worker paused")
    }

    /**
     * Resume job processing after a pause.
     */
    fun resume() {
        if (!running) return
        paused = false
        Log.i(TAG, "Worker resumed")
    }

    /**
     * Stop the worker thread.
     */
    fun stop() {
        if (!running) return

        Log.i(TAG, "Stopping worker...")
        running = false
        paused = false
        workerThread?.interrupt()

        try {
            workerThread?.join(5000)
        } catch (e: InterruptedException) {
            Log.w(TAG, "Interrupted waiting for worker thread")
        }

        workerThread = null
        Log.i(TAG, "Worker stopped")
    }

    fun isRunning(): Boolean = running
}
