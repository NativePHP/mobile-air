package com.nativephp.mobile.bridge

import android.util.Log
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.LinkedBlockingQueue
import java.util.concurrent.TimeUnit

/**
 * Runs dispatched async tasks immediately on a small pool of background PHP
 * contexts — the lane behind `AsyncTask::dispatch()`.
 *
 * Distinct from [PHPQueueWorker]:
 *   - It does NOT poll `queue:work` and never touches the standard queue. Task
 *     ids arrive directly via the `AsyncTask.Dispatch` bridge function and are
 *     run right away.
 *   - It is CONCURRENT — `poolSize` threads, so a screen can run several
 *     background tasks at once — where the queue worker is a single thread.
 *
 * Each pool thread boots its own thread-local PHP context lazily (on its first
 * task, so idle apps pay nothing) and reuses it for subsequent tasks. The task
 * payload and result travel via the PHP-side temp-file transport plus the
 * `AsyncTask.Complete` bridge function, which wakes the UI runloop.
 *
 * iOS twin: `AsyncTaskExecutor.swift`.
 */
class AsyncTaskExecutor(
    private val phpBridge: PHPBridge,
    private val poolSize: Int = DEFAULT_POOL_SIZE,
) {
    companion object {
        private const val TAG = "AsyncTaskExecutor"
        private const val DEFAULT_POOL_SIZE = 4

        /**
         * How long stop() waits for the pool to drain before giving up on it.
         * Matches [PHPQueueWorker]'s join budget: long enough for a task to
         * land, short enough that stopping from onDestroy() can't hang the app.
         */
        private const val STOP_TIMEOUT_MS = 5_000L

        /** How often the watchdog looks for tasks that have outrun their deadline. */
        private const val WATCHDOG_TICK_MS = 500L

        /**
         * Process-wide handle so the `AsyncTask.Dispatch` bridge function can
         * reach the running executor. Set on start(), cleared on stop().
         */
        @Volatile
        var shared: AsyncTaskExecutor? = null
            private set
    }

    /**
     * The completion to post if a task outruns its deadline. PHP builds it at
     * dispatch time and we hold it verbatim, so this stays a dumb courier that
     * knows nothing about how a failure event is shaped.
     */
    private data class Watchdog(
        val deadlineMs: Long,
        val event: String,
        val payloadJson: String,
    )

    private val queue = LinkedBlockingQueue<String>()
    private val threads = mutableListOf<Thread>()

    /** Dispatched-but-not-yet-finished tasks that carry a deadline. */
    private val inFlight = ConcurrentHashMap<String, Watchdog>()

    private var watchdogThread: Thread? = null

    @Volatile
    private var running = false

    fun start() {
        if (running) {
            Log.w(TAG, "Async executor already running")
            return
        }
        running = true

        repeat(poolSize) { index ->
            Thread({ workerLoop(index) }, "nativephp-async-$index").apply {
                isDaemon = true
                threads.add(this)
                start()
            }
        }

        watchdogThread = Thread({ watchdogLoop() }, "nativephp-async-watchdog").apply {
            isDaemon = true
            start()
        }

        shared = this
        Log.i(TAG, "Async executor started ($poolSize threads)")
    }

    /**
     * Enqueue a dispatched task id. Returns immediately.
     *
     * A non-zero [timeoutSeconds] arms the watchdog: if the deadline passes with
     * nothing delivered, [timeoutEvent]/[timeoutPayloadJson] are posted into the
     * UI runloop so `->failed()` fires instead of the UI waiting forever. The
     * deadline covers queue time as well as run time — from the user's point of
     * view the wait started when they tapped.
     *
     * Returns false when the task was NOT accepted, so the bridge function can
     * tell PHP and the dispatch can fail loudly at the call site.
     */
    @JvmOverloads
    fun dispatch(
        taskId: String,
        timeoutSeconds: Int = 0,
        timeoutEvent: String? = null,
        timeoutPayloadJson: String? = null,
    ): Boolean {
        if (!running) {
            Log.w(TAG, "dispatch($taskId) ignored — executor not running")
            return false
        }

        if (timeoutSeconds > 0 && timeoutEvent != null && timeoutPayloadJson != null) {
            inFlight[taskId] = Watchdog(
                deadlineMs = System.currentTimeMillis() + timeoutSeconds * 1000L,
                event = timeoutEvent,
                payloadJson = timeoutPayloadJson,
            )
        }

        val waiting = queue.size
        if (!queue.offer(taskId)) {
            inFlight.remove(taskId)
            Log.e(TAG, "dispatch($taskId) rejected — queue full")
            return false
        }

        // Never silently over-subscribe: surface when tasks are queueing behind
        // a saturated pool so the concurrency ceiling is visible in the logs.
        if (waiting >= poolSize) {
            Log.i(TAG, "async task $taskId queued behind $waiting others (pool=$poolSize)")
        }

        return true
    }

    private fun workerLoop(index: Int) {
        var booted = false

        while (running) {
            val taskId = try {
                queue.poll(1, TimeUnit.SECONDS)
            } catch (e: InterruptedException) {
                continue
            } ?: continue

            if (!booted) {
                booted = phpBridge.bootAsyncContext()
                if (!booted) {
                    Log.e(TAG, "async context boot failed on thread $index; requeueing $taskId")
                    queue.offer(taskId)
                    try {
                        Thread.sleep(500)
                    } catch (_: InterruptedException) {
                    }
                    continue
                }
            }

            try {
                phpBridge.runAsyncTask(taskId)
            } catch (e: Exception) {
                Log.e(TAG, "async task $taskId threw in native run", e)
            } finally {
                // The run is over either way — disarm the watchdog. A completion
                // (or a native-level failure) has already been posted from PHP.
                inFlight.remove(taskId)
            }
        }

        if (booted) {
            try {
                phpBridge.shutdownAsyncContext()
            } catch (e: Exception) {
                Log.w(TAG, "async context shutdown failed on thread $index", e)
            }
        }
    }

    /**
     * Fail tasks that have outrun their deadline. The PHP interpreter running a
     * hung task can't be interrupted safely, so the task keeps going; what this
     * does is unblock the UI. A late completion for an already-failed task is
     * discarded on the PHP side (its callbacks are gone by then).
     */
    private fun watchdogLoop() {
        while (running) {
            try {
                Thread.sleep(WATCHDOG_TICK_MS)
            } catch (e: InterruptedException) {
                continue
            }

            if (inFlight.isEmpty()) continue

            val now = System.currentTimeMillis()
            val iterator = inFlight.entries.iterator()
            while (iterator.hasNext()) {
                val (taskId, watchdog) = iterator.next()
                if (watchdog.deadlineMs > now) continue

                iterator.remove()
                Log.w(TAG, "async task $taskId timed out; reporting failure to the UI")
                try {
                    NativeElementBridge.sendNativeEvent(watchdog.event, watchdog.payloadJson)
                } catch (e: Exception) {
                    Log.e(TAG, "failed to post timeout completion for $taskId", e)
                }
            }
        }
    }

    /**
     * Stop the pool, WAITING for in-flight tasks to finish and their contexts to
     * shut down.
     *
     * The wait is the point: callers stop the executor immediately before
     * `shutdownPersistentRuntime()`, and `php_module_shutdown` destroys Zend
     * state that a live async context still references. Returning while a task
     * is mid-run tears PHP down underneath it — a crash, not a leak. Mirrors
     * [PHPQueueWorker.stop].
     *
     * Returns whether the pool drained cleanly within [STOP_TIMEOUT_MS]; a false
     * return means it is NOT safe to shut the runtime down yet.
     */
    fun stop(): Boolean {
        if (!running) return true

        Log.i(TAG, "Stopping async executor...")
        running = false

        if (shared === this) {
            shared = null
        }

        // Queued-but-unstarted tasks will never run now; drop them rather than
        // letting a worker pick one up on its way out.
        val abandoned = mutableListOf<String>()
        queue.drainTo(abandoned)
        if (abandoned.isNotEmpty()) {
            Log.w(TAG, "${abandoned.size} queued async task(s) dropped on stop")
            abandoned.forEach { inFlight.remove(it) }
        }

        watchdogThread?.interrupt()
        threads.forEach { it.interrupt() }

        // Interrupting a thread parked in a native run does nothing — it only
        // unsticks the queue poll — so the join deadline is what actually bounds
        // this. Shared budget across the pool, not per thread.
        val deadline = System.currentTimeMillis() + STOP_TIMEOUT_MS
        var clean = true

        for (thread in threads + listOfNotNull(watchdogThread)) {
            val remaining = deadline - System.currentTimeMillis()
            if (remaining > 0) {
                try {
                    thread.join(remaining)
                } catch (e: InterruptedException) {
                    Log.w(TAG, "Interrupted waiting for ${thread.name}")
                    Thread.currentThread().interrupt()
                }
            }
            if (thread.isAlive) {
                clean = false
                Log.e(TAG, "${thread.name} still running after ${STOP_TIMEOUT_MS}ms — its PHP context is still live")
            }
        }

        threads.clear()
        watchdogThread = null
        inFlight.clear()

        Log.i(TAG, if (clean) "Async executor stopped" else "Async executor stop TIMED OUT")
        return clean
    }

    fun isRunning(): Boolean = running
}
