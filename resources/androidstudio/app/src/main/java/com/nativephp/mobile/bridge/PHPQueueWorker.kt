package com.nativephp.mobile.bridge

import android.util.Log
import java.util.concurrent.TimeUnit
import java.util.concurrent.locks.ReentrantLock
import kotlin.concurrent.withLock

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

        private val wakeHandlerLock = Any()

        @Volatile
        private var wakeHandler: (() -> Boolean)? = null

        fun setWakeHandler(handler: (() -> Boolean)?) {
            synchronized(wakeHandlerLock) {
                wakeHandler = handler
            }
        }

        fun clearWakeHandler(handler: () -> Boolean) {
            synchronized(wakeHandlerLock) {
                if (wakeHandler === handler) {
                    wakeHandler = null
                }
            }
        }

        fun wakeShared(): Boolean {
            val handler = synchronized(wakeHandlerLock) { wakeHandler }
            return handler?.invoke() ?: false
        }
    }

    private var workerThread: Thread? = null
    private val stateLock = ReentrantLock()
    private val stateCondition = stateLock.newCondition()

    @Volatile
    private var running = false
    private var paused = false
    private var woken = false

    /**
     * Start the background queue worker thread.
     * Boots a dedicated worker PHP runtime, independent from phpExecutor.
     */
    @JvmOverloads
    fun start(startPaused: Boolean = false) {
        val thread = Thread({
            try {
                Log.i(TAG, "Queue worker starting (dedicated context)")

                val booted = phpBridge.bootWorkerRuntime()
                if (!booted) {
                    Log.e(TAG, "Failed to boot worker runtime, aborting")
                } else {
                    Log.i(TAG, "Worker runtime booted, processing jobs")

                    try {
                        while (running) {
                            try {
                                val shouldContinue = stateLock.withLock {
                                    while (running && paused) {
                                        stateCondition.await()
                                    }
                                    if (!running) {
                                        false
                                    } else {
                                        woken = false
                                        true
                                    }
                                }
                                if (!shouldContinue) break

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

                                stateLock.withLock {
                                    if (running && !paused && !woken) {
                                        stateCondition.await(sleepMs, TimeUnit.MILLISECONDS)
                                    }
                                }
                            } catch (e: InterruptedException) {
                                Log.d(TAG, "Worker wait interrupted")
                            } catch (e: Exception) {
                                Log.e(TAG, "Worker error", e)
                                try {
                                    stateLock.withLock {
                                        if (running && !paused && !woken) {
                                            stateCondition.await(SLEEP_IDLE_MS, TimeUnit.MILLISECONDS)
                                        }
                                    }
                                } catch (_: InterruptedException) {
                                }
                            }
                        }
                    } finally {
                        phpBridge.shutdownWorkerRuntime()
                        Log.i(TAG, "Queue worker stopped")
                    }
                }
            } finally {
                stateLock.withLock {
                    running = false
                    paused = false
                    if (workerThread === Thread.currentThread()) {
                        workerThread = null
                    }
                    stateCondition.signalAll()
                }
            }
        }, "php-queue-worker").apply {
            isDaemon = true
        }

        stateLock.withLock {
            if (running || workerThread != null) {
                Log.w(TAG, "Worker already running")
                return
            }

            running = true
            paused = startPaused
            woken = false
            workerThread = thread
        }

        thread.start()
        Log.i(TAG, "Queue worker thread launched")
    }

    /**
     * Pause polling while the activity is backgrounded so the worker does not
     * wake the CPU every few seconds when idle. Any job already running finishes
     * its current pass before the loop parks.
     */
    fun pause() {
        stateLock.withLock {
            if (!running || paused) return
            paused = true
            stateCondition.signalAll()
        }
    }

    /**
     * Resume polling when the activity returns to the foreground.
     */
    fun resume() {
        stateLock.withLock {
            if (!running || !paused) return
            paused = false
            stateCondition.signalAll()
        }
    }

    /**
     * Interrupt an idle sleep immediately when PHP dispatches a new job.
     */
    fun wake() {
        stateLock.withLock {
            if (!running) return
            woken = true
            stateCondition.signalAll()
        }
    }

    /**
     * Stop the worker thread and shut down the worker runtime.
     */
    fun stop() {
        val thread = stateLock.withLock {
            if (!running && workerThread == null) return
            Log.i(TAG, "Stopping worker...")
            running = false
            paused = false
            woken = true
            stateCondition.signalAll()
            workerThread
        }

        thread?.interrupt()

        try {
            thread?.join(5000)
        } catch (e: InterruptedException) {
            Log.w(TAG, "Interrupted waiting for worker thread")
        }

        stateLock.withLock {
            if (workerThread === thread) {
                workerThread = null
            }
        }
        Log.i(TAG, "Worker stopped")
    }

    fun isRunning(): Boolean = running
}
