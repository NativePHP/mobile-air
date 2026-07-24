package com.nativephp.mobile.bridge

import android.util.Log
import java.util.concurrent.LinkedBlockingQueue
import java.util.concurrent.TimeUnit

/**
 * Runs dispatched async tasks immediately on a small pool of background PHP
 * contexts — the lane behind `AsyncTask::dispatch()`.
 *
 * Distinct from [PHPQueueWorker]:
 *   - It does NOT poll `queue:work` and never touches SQLite or the standard
 *     queue. Task ids arrive directly via the `AsyncTask.Dispatch` bridge
 *     function and are run right away.
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
         * Process-wide handle so the `AsyncTask.Dispatch` bridge function can
         * reach the running executor. Set on start(), cleared on stop().
         */
        @Volatile
        var shared: AsyncTaskExecutor? = null
            private set
    }

    private val queue = LinkedBlockingQueue<String>()
    private val threads = mutableListOf<Thread>()

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

        shared = this
        Log.i(TAG, "Async executor started ($poolSize threads)")
    }

    /** Enqueue a dispatched task id. Returns immediately. */
    fun dispatch(taskId: String) {
        if (!running) {
            Log.w(TAG, "dispatch($taskId) ignored — executor not running")
            return
        }

        val waiting = queue.size
        queue.offer(taskId)

        // Never silently over-subscribe: surface when tasks are queueing behind
        // a saturated pool so the concurrency ceiling is visible in the logs.
        if (waiting >= poolSize) {
            Log.i(TAG, "async task $taskId queued behind $waiting others (pool=$poolSize)")
        }
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

    fun stop() {
        if (!running) return
        Log.i(TAG, "Stopping async executor...")
        running = false
        threads.forEach { it.interrupt() }
        threads.clear()
        if (shared === this) {
            shared = null
        }
    }

    fun isRunning(): Boolean = running
}
