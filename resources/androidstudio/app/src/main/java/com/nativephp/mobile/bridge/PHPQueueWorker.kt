package com.nativephp.mobile.bridge

import android.content.Context
import android.util.Log
import org.json.JSONObject
import java.io.File
import java.util.concurrent.Executors
import java.util.concurrent.ScheduledFuture
import java.util.concurrent.TimeUnit

/**
 * Background queue worker that processes Laravel queue jobs.
 *
 * Uses a dedicated PHP TSRM context (worker runtime) so queue processing
 * never contends with the main phpExecutor used for UI requests.
 *
 * The worker is a whole Laravel boot of its own, so it only boots when there
 * is work for it:
 *
 *  - PHP calls `QueueWorker.Wake` whenever a job is pushed to the default
 *    connection (`QueueWorkerSignal`), which boots the worker or cuts its wait
 *    short.
 *  - PHP also leaves `storage/framework/queue_pending` on every push, and only
 *    the worker removes it, once the queue is empty. [start] checks for that
 *    file, so jobs still queued when the app last closed run after launch,
 *    without booting Laravel just to find out.
 *
 * Once it has gone [IDLE_TIMEOUT_MS] without being woken, the worker asks PHP
 * (`native:queue:idle`) what is left. An empty queue shuts it down. Jobs that
 * are delayed or waiting to be retried shut it down too, with a timer to wake
 * it when the first is due. An app that never queues anything never boots it.
 *
 * iOS twin: `PHPQueueWorker.swift`.
 */
class PHPQueueWorker(
    private val phpBridge: PHPBridge,
    private val pendingMarker: File,
) {

    companion object {
        private const val TAG = "PHPQueueWorker"
        private const val SLEEP_INTERVAL_MS = 1000L
        private const val SLEEP_IDLE_MS = 3000L

        /** How long without a wake before the worker checks whether it can stop. */
        private const val IDLE_TIMEOUT_MS = 30_000L

        /** Jobs left with no due time (a driver other than database): look again after this. */
        private const val UNKNOWN_DUE_RECHECK_S = 60L

        /**
         * The running worker, for the `QueueWorker.Wake` bridge function.
         * Set on start(), cleared on stop().
         */
        @Volatile
        var shared: PHPQueueWorker? = null
            private set

        /**
         * Leave the marker [start] looks for, so the next start boots the worker
         * once. Called after a new bundle is extracted: jobs queued by a version
         * that wrote no marker would otherwise wait for the next push.
         */
        fun markPending(context: Context) {
            try {
                val marker = pendingMarkerFor(context)
                marker.parentFile?.mkdirs()
                marker.createNewFile()
            } catch (e: Exception) {
                Log.w(TAG, "Could not write the pending-jobs marker", e)
            }
        }

        /** Where PHP leaves word that jobs may be queued (`QueueWorkerSignal::markerPath()`). */
        fun pendingMarkerFor(context: Context): File =
            File(context.getDir("storage", Context.MODE_PRIVATE), "persisted_data/storage/framework/queue_pending")
    }

    private val lock = Object()

    // Guarded by `lock`.
    private var enabled = false
    private var workerThread: Thread? = null
    private var wakeRequested = false
    private var dueTimer: ScheduledFuture<*>? = null

    private val timers = Executors.newSingleThreadScheduledExecutor { r ->
        Thread(r, "php-queue-worker-timer").apply { isDaemon = true }
    }

    /**
     * Take wakes from now on, and boot the worker if jobs were left queued.
     * Boots nothing when there is no sign of queued work.
     */
    fun start() {
        synchronized(lock) {
            if (enabled) {
                Log.w(TAG, "Worker already running")
                return
            }
            enabled = true
        }
        shared = this

        if (pendingMarker.isFile) {
            Log.i(TAG, "Jobs may be queued from an earlier run; booting")
            wake()
        } else {
            Log.i(TAG, "Idle until a job is queued")
        }
    }

    /**
     * Boot the worker, or have the running one poll now. Called on every push,
     * from whichever PHP thread pushed. Never blocks.
     */
    fun wake() {
        synchronized(lock) {
            if (!enabled) return
            wakeRequested = true

            workerThread?.let {
                it.interrupt()
                return
            }

            workerThread = Thread({ workerLoop() }, "php-queue-worker").apply {
                isDaemon = true
                start()
            }
        }
        Log.i(TAG, "Queue worker thread launched")
    }

    private fun workerLoop() {
        // Each pass boots a context, works until idle, and shuts it down. A
        // wake that lands while it shuts down starts another pass on this
        // thread, so two contexts never overlap in the C worker's globals.
        while (true) {
            Log.i(TAG, "Queue worker starting (dedicated context)")
            val started = System.currentTimeMillis()

            if (!phpBridge.bootWorkerRuntime()) {
                Log.e(TAG, "Failed to boot worker runtime, aborting")
                synchronized(lock) {
                    workerThread = null
                    wakeRequested = false
                }
                return
            }

            Log.i(TAG, "Worker runtime booted in ${System.currentTimeMillis() - started} ms, processing jobs")

            val wakeAgainIn = processUntilIdle()

            phpBridge.shutdownWorkerRuntime()

            val again = synchronized(lock) {
                val again = enabled && wakeRequested
                if (!again) {
                    workerThread = null
                    if (enabled && wakeAgainIn != null) {
                        armDueTimer(wakeAgainIn)
                    }
                }
                again
            }

            if (!again) {
                Log.i(TAG, "Queue worker stopped")
                return
            }

            Log.i(TAG, "Woken while shutting down; booting again")
            // Clear the interrupt wake() may have left on this thread.
            Thread.interrupted()
        }
    }

    /**
     * Run `queue:work --once` until the worker has gone [IDLE_TIMEOUT_MS]
     * without a wake and PHP says nothing is ready. Returns the seconds after
     * which to boot again for a job not due yet, or null for none.
     */
    private fun processUntilIdle(): Long? {
        var idleSince = System.currentTimeMillis()

        while (true) {
            synchronized(lock) {
                if (!enabled) return null
                if (wakeRequested) {
                    wakeRequested = false
                    idleSince = System.currentTimeMillis()
                }
            }

            try {
                val output = phpBridge.runWorkerArtisan("queue:work --once --quiet")
                if (output.isNotEmpty() && output != "0") {
                    Log.d(TAG, "Job output: ${output.take(200)}")
                }

                // Shorter sleep if we just processed a job, longer if idle
                val sleepMs = if (output.contains("Processed", ignoreCase = true)) {
                    idleSince = System.currentTimeMillis()
                    SLEEP_INTERVAL_MS
                } else {
                    SLEEP_IDLE_MS
                }

                if (System.currentTimeMillis() - idleSince >= IDLE_TIMEOUT_MS) {
                    val (pending, next) = idleStatus()
                    when {
                        pending == 0 -> {
                            Log.i(TAG, "Queue empty; shutting down until a job is queued")
                            return null
                        }
                        pending == null -> {
                            // The marker stays, so the next launch or push tries again.
                            Log.w(TAG, "Queue state unknown; shutting down")
                            return null
                        }
                        next != null && next * 1000 <= SLEEP_IDLE_MS -> {
                            Log.i(TAG, "$pending job(s) still due; staying up")
                            idleSince = System.currentTimeMillis()
                        }
                        next != null -> {
                            Log.i(TAG, "$pending job(s) not due for ${next}s; shutting down until then")
                            return next
                        }
                        else -> {
                            Log.i(TAG, "$pending job(s) left with no due time; checking again in ${UNKNOWN_DUE_RECHECK_S}s")
                            return UNKNOWN_DUE_RECHECK_S
                        }
                    }
                }

                Thread.sleep(sleepMs)
            } catch (e: InterruptedException) {
                // wake() or stop(): loop round and look at the flags.
            } catch (e: Exception) {
                Log.e(TAG, "Worker error", e)
                try { Thread.sleep(SLEEP_IDLE_MS) } catch (_: InterruptedException) {}
            }
        }
    }

    /** What `native:queue:idle` said: jobs left, and seconds until the first is due. */
    private fun idleStatus(): Pair<Int?, Long?> {
        val output = phpBridge.runWorkerArtisan("native:queue:idle")
        val line = output.lines().lastOrNull { it.trim().startsWith("{") }
        if (line == null) {
            Log.w(TAG, "Unreadable idle status: ${output.take(200)}")
            return Pair(null, null)
        }

        return try {
            val json = JSONObject(line.trim())
            val pending = if (json.isNull("pending")) null else json.getInt("pending")
            val next = if (json.isNull("next")) null else json.getLong("next")
            Pair(pending, next)
        } catch (e: Exception) {
            Log.w(TAG, "Unreadable idle status: ${output.take(200)}")
            Pair(null, null)
        }
    }

    /** Wake the worker in [seconds]. Call with `lock` held. */
    private fun armDueTimer(seconds: Long) {
        dueTimer?.cancel(false)
        dueTimer = timers.schedule({
            Log.i(TAG, "A delayed job is due; waking")
            wake()
        }, seconds.coerceAtLeast(1), TimeUnit.SECONDS)
    }

    /**
     * Stop the worker thread and shut down the worker runtime, waiting for it.
     */
    fun stop() {
        val thread: Thread?
        synchronized(lock) {
            if (!enabled && workerThread == null) return
            enabled = false
            dueTimer?.cancel(false)
            dueTimer = null
            thread = workerThread
        }
        if (shared === this) {
            shared = null
        }

        Log.i(TAG, "Stopping worker...")
        thread?.interrupt()

        try {
            thread?.join(5000)
        } catch (e: InterruptedException) {
            Log.w(TAG, "Interrupted waiting for worker thread")
        }

        timers.shutdownNow()
        Log.i(TAG, "Worker stopped")
    }

    fun isRunning(): Boolean = synchronized(lock) { workerThread != null }
}
