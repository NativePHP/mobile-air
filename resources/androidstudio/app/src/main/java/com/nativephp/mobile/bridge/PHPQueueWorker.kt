package com.nativephp.mobile.bridge

import android.util.Log

/**
 * Background queue worker that processes Laravel queue jobs.
 *
 * Uses a dedicated PHP TSRM context (worker runtime) so queue processing
 * never contends with the main phpExecutor used for UI requests.
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

    /**
     * Start the background queue worker thread.
     * Boots a dedicated worker PHP runtime — fully independent from phpExecutor.
     */
    fun start() {
        if (running) {
            Log.w(TAG, "Worker already running")
            return
        }

        running = true

        workerThread = Thread({
            Log.i(TAG, "Queue worker starting (dedicated context)")

            val booted = phpBridge.bootWorkerRuntime()
            if (!booted) {
                Log.e(TAG, "Failed to boot worker runtime, aborting")
                running = false
                return@Thread
            }

            Log.i(TAG, "Worker runtime booted, processing jobs")

            while (running) {
                try {
                    val output = phpBridge.runWorkerArtisan("queue:work --once --quiet")
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

            phpBridge.shutdownWorkerRuntime()
            Log.i(TAG, "Queue worker stopped")
        }, "php-queue-worker").apply {
            isDaemon = true
            start()
        }

        Log.i(TAG, "Queue worker thread launched")
    }

    /**
     * Stop the worker thread and shut down the worker runtime.
     */
    fun stop() {
        if (!running) return

        Log.i(TAG, "Stopping worker...")
        running = false
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
