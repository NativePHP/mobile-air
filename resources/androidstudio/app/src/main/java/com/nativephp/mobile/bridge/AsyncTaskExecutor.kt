package com.nativephp.mobile.bridge

import android.content.Context
import android.util.Log
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import org.json.JSONObject
import java.util.ArrayDeque
import java.util.concurrent.ConcurrentHashMap
import com.nativephp.mobile.utils.NativeLog

/**
 * Runs dispatched async tasks immediately on a small pool of background PHP
 * contexts — the lane behind `AsyncTask::dispatch()`.
 *
 * Distinct from [PHPQueueWorker]:
 *   - It does NOT poll `queue:work` and never touches the standard queue. Task
 *     ids arrive directly via the `AsyncTask.Dispatch` bridge function and are
 *     run right away.
 *   - It is CONCURRENT — up to `maxSlots` threads, so a screen can run several
 *     background tasks at once — where the queue worker is a single thread.
 *
 * Threads start on demand, each booting its own thread-local PHP context (a
 * full Laravel boot) before taking its first task. None run at launch. The
 * first dispatch starts one, and another starts only while more tasks are
 * waiting than threads are free, up to `maxSlots`. A thread with no task for
 * `idleTimeoutSeconds` shuts its context down and exits. Both come from
 * bundle_meta.json (`config('nativephp.async')`).
 *
 * All threads take from one shared queue, so a task goes to whichever thread
 * frees up first. The task payload and result travel via the PHP-side
 * temp-file transport plus the `AsyncTask.Complete` bridge function, which
 * wakes the UI runloop.
 *
 * iOS twin: `AsyncTaskExecutor.swift`.
 */
class AsyncTaskExecutor(
    private val phpBridge: PHPBridge,
    maxSlots: Int = DEFAULT_MAX_SLOTS,
    idleTimeoutSeconds: Int = DEFAULT_IDLE_TIMEOUT_SECONDS,
) {
    companion object {
        private const val TAG = "AsyncTaskExecutor"

        /** Matches the iOS C lane's ASYNC_PHP_MAX_SLOTS, the ceiling on both platforms. */
        private const val HARD_MAX_SLOTS = 4
        private const val DEFAULT_MAX_SLOTS = 2
        private const val DEFAULT_IDLE_TIMEOUT_SECONDS = 60

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

        /** True on the threads of the pool, where a dispatched task runs. */
        private val laneThread = ThreadLocal.withInitial { false }

        /**
         * Whether the calling thread is a thread of the pool. A bridge call
         * that PHP makes runs on the thread of the PHP that made it, so this
         * tells a call from a task apart from the same call made by
         * JavaScript in a web view.
         */
        val onLaneThread: Boolean
            get() = laneThread.get() == true

        /** An executor sized by the `async_*` keys in bundle_meta.json. */
        fun fromBundleMeta(context: Context, phpBridge: PHPBridge): AsyncTaskExecutor {
            val meta = try {
                context.assets.open("bundle_meta.json").bufferedReader().use { JSONObject(it.readText()) }
            } catch (e: Exception) {
                null
            }

            return AsyncTaskExecutor(
                phpBridge,
                maxSlots = meta?.optInt("async_max_slots", DEFAULT_MAX_SLOTS) ?: DEFAULT_MAX_SLOTS,
                idleTimeoutSeconds = meta?.optInt("async_idle_timeout", DEFAULT_IDLE_TIMEOUT_SECONDS)
                    ?: DEFAULT_IDLE_TIMEOUT_SECONDS,
            )
        }
    }

    private val maxSlots = maxSlots.coerceIn(1, HARD_MAX_SLOTS)
    private val idleTimeoutMs = idleTimeoutSeconds.coerceAtLeast(0) * 1000L

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

    /** Guards [queue], [threads], [live] and [busy]; threads wait on it for work. */
    private val lock = Object()

    /** Tasks waiting for a thread, oldest first. */
    private val queue = ArrayDeque<String>()

    /** Every thread started, including ones that have since exited. */
    private val threads = mutableListOf<Thread>()

    /** Threads that are booting or booted and have not exited. */
    private var live = 0

    /** Threads running a task right now. */
    private var busy = 0

    private var nextThreadIndex = 0

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

        watchdogThread = Thread({ watchdogLoop() }, "nativephp-async-watchdog").apply {
            isDaemon = true
            start()
        }

        shared = this
        NativeLog.perf(TAG) { "Async executor ready (0/$maxSlots threads, boot on first task, idle timeout ${idleTimeoutMs / 1000}s)" }
    }

    /**
     * Enqueue a dispatched task id. Returns immediately.
     *
     * A non-zero [timeoutSeconds] arms the watchdog: if the deadline passes with
     * nothing delivered, [timeoutEvent]/[timeoutPayloadJson] are posted into the
     * UI runloop so `->failed()` fires instead of the UI waiting forever. The
     * deadline covers queue and boot time as well as run time — from the user's
     * point of view the wait started when they tapped.
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

        val waiting: Int
        val spawn: Boolean
        synchronized(lock) {
            waiting = queue.size
            queue.addLast(taskId)

            // Free threads (idle or still booting) will take queued tasks. Start
            // another only when the queue outnumbers them and there is room.
            spawn = queue.size > live - busy && live < maxSlots
            if (spawn) {
                live++
            }
            lock.notify()
        }

        if (spawn) {
            startThread()
        } else if (waiting >= live - busy) {
            // Never silently over-subscribe: surface when tasks are queueing
            // behind a saturated pool so the concurrency ceiling is visible.
            NativeLog.i(TAG) { "async task $taskId queued behind $waiting others (pool=$live, max=$maxSlots)" }
        }

        return true
    }

    /** Start one pool thread. The caller has already counted it in [live]. */
    private fun startThread() {
        synchronized(lock) {
            // Threads that shut down after sitting idle are done with.
            threads.removeAll { !it.isAlive }
            val index = nextThreadIndex++
            val thread = Thread({ workerLoop(index) }, "nativephp-async-$index").apply { isDaemon = true }
            threads.add(thread)
            thread.start()
        }
    }

    private fun workerLoop(index: Int) {
        laneThread.set(true)

        val started = System.currentTimeMillis()
        val booted = running && phpBridge.bootAsyncContext()

        if (!booted) {
            val stranded = mutableListOf<String>()
            synchronized(lock) {
                live--
                // Nothing else can run the waiting tasks: fail them now rather
                // than at their deadline.
                if (live == 0) {
                    stranded.addAll(queue)
                    queue.clear()
                }
            }
            if (running) {
                Log.e(TAG, "async context boot failed on thread $index")
                stranded.forEach { failNow(it) }
            }
            return
        }

        NativeLog.perf(TAG) { "async thread $index booted in ${System.currentTimeMillis() - started} ms" }
        var idleSince = System.currentTimeMillis()

        while (true) {
            val taskId: String = synchronized(lock) {
                var next: String? = null
                while (running) {
                    next = queue.pollFirst()
                    if (next != null) break

                    val waitMs = if (idleTimeoutMs > 0) idleTimeoutMs - (System.currentTimeMillis() - idleSince) else 0L
                    if (idleTimeoutMs > 0 && waitMs <= 0) break

                    try {
                        lock.wait(if (idleTimeoutMs > 0) waitMs else 0L)
                    } catch (e: InterruptedException) {
                        // stop() interrupts; the loop condition sees it.
                    }
                }

                if (next == null) {
                    live--
                } else {
                    busy++
                }
                next
            } ?: break

            try {
                phpBridge.runAsyncTask(taskId)
            } catch (e: Exception) {
                Log.e(TAG, "async task $taskId threw in native run", e)
            } finally {
                // The run is over either way — disarm the watchdog. A completion
                // (or a native-level failure) has already been posted from PHP.
                inFlight.remove(taskId)
                synchronized(lock) { busy-- }
                idleSince = System.currentTimeMillis()
            }
        }

        if (running) {
            NativeLog.i(TAG) { "async thread $index idle for ${idleTimeoutMs / 1000}s; shutting its context down" }
        }

        try {
            phpBridge.shutdownAsyncContext()
        } catch (e: Exception) {
            Log.w(TAG, "async context shutdown failed on thread $index", e)
        }
    }

    /**
     * Post the failure PHP built for a task that can never run. Without a
     * watchdog there is no failure to post, so it is only logged.
     */
    private fun failNow(taskId: String) {
        val watchdog = inFlight.remove(taskId)
        if (watchdog == null) {
            Log.w(TAG, "async task $taskId dropped — no context could boot")
            return
        }
        Log.w(TAG, "async task $taskId failed — no context could boot")
        try {
            NativeElementBridge.sendNativeEvent(watchdog.event, watchdog.payloadJson)
        } catch (e: Exception) {
            Log.e(TAG, "failed to post failure for $taskId", e)
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

        NativeLog.i(TAG) { "Stopping async executor..." }
        running = false

        if (shared === this) {
            shared = null
        }

        // Queued-but-unstarted tasks will never run now; drop them rather than
        // letting a worker pick one up on its way out.
        val abandoned: List<String>
        val pool: List<Thread>
        synchronized(lock) {
            abandoned = queue.toList()
            queue.clear()
            pool = threads.toList()
            threads.clear()
            lock.notifyAll()
        }
        if (abandoned.isNotEmpty()) {
            Log.w(TAG, "${abandoned.size} queued async task(s) dropped on stop")
            abandoned.forEach { inFlight.remove(it) }
        }

        watchdogThread?.interrupt()
        pool.forEach { it.interrupt() }

        // Interrupting a thread parked in a native run does nothing — it only
        // unsticks the wait for work — so the join deadline is what actually
        // bounds this. Shared budget across the pool, not per thread.
        val deadline = System.currentTimeMillis() + STOP_TIMEOUT_MS
        var clean = true

        for (thread in pool + listOfNotNull(watchdogThread)) {
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

        watchdogThread = null
        inFlight.clear()

        NativeLog.i(TAG) { if (clean) "Async executor stopped" else "Async executor stop TIMED OUT" }
        return clean
    }

    fun isRunning(): Boolean = running
}
