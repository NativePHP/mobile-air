package com.nativephp.mobile.queue

import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.PHPBridge
import com.nativephp.mobile.network.PHPRequest
import com.nativephp.mobile.utils.NativeActionCoordinator
import org.json.JSONObject
import java.util.concurrent.Executors
import java.util.concurrent.ScheduledFuture
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicInteger

/**
 * Coordinates background queue processing for Laravel jobs.
 *
 * The coordinator monitors the job queue and triggers processing during idle periods.
 * It ensures jobs don't interfere with UI responsiveness by:
 * - Processing one job at a time
 * - Pausing during active user interaction
 * - Throttling based on configuration
 *
 * Jobs are processed via HTTP requests to `/_native/queue/work`, which executes
 * one job and returns. The coordinator decides when to process the next job.
 */
class NativeQueueCoordinator private constructor() {

    companion object {
        private const val TAG = "NativeQueueCoordinator"

        @Volatile
        private var instance: NativeQueueCoordinator? = null

        fun getInstance(): NativeQueueCoordinator {
            return instance ?: synchronized(this) {
                instance ?: NativeQueueCoordinator().also { instance = it }
            }
        }
    }

    // Configuration
    /** Minimum delay between job processing attempts (milliseconds) */
    var minDelayBetweenJobs: Long = 100

    /** Delay when queue is empty before checking again (milliseconds) */
    var emptyQueuePollInterval: Long = 2000

    /** Maximum jobs to process per batch before yielding (0 = unlimited) */
    var maxJobsPerBatch: Int = 10

    /** Whether to pause processing during active scrolling */
    var pauseDuringScroll: Boolean = true

    // Dependencies
    private var phpBridge: PHPBridge? = null
    private var activityProvider: (() -> FragmentActivity?)? = null

    // State
    private val isRunning = AtomicBoolean(false)
    private val isPaused = AtomicBoolean(false)
    private val pendingJobCount = AtomicInteger(0)
    private var processedInBatch = 0
    private var totalProcessed = 0

    private val executor = Executors.newSingleThreadScheduledExecutor()
    private var scheduledTask: ScheduledFuture<*>? = null
    private val mainHandler = Handler(Looper.getMainLooper())

    /**
     * Initialize the coordinator with required dependencies.
     * Call this from MainActivity.onCreate().
     */
    fun initialize(phpBridge: PHPBridge, activityProvider: () -> FragmentActivity?) {
        this.phpBridge = phpBridge
        this.activityProvider = activityProvider
    }

    /**
     * Start the queue coordinator.
     * Called automatically when the app launches.
     */
    fun start() {
        if (isRunning.getAndSet(true)) {
            Log.d(TAG, "⚠️ NativeQueueCoordinator already running")
            return // Already running
        }

        processedInBatch = 0
        Log.d(TAG, "🚀 NativeQueueCoordinator started")
        Log.d(TAG, "📊 phpBridge initialized: ${phpBridge != null}")
        Log.d(TAG, "📊 activityProvider initialized: ${activityProvider != null}")

        checkForJobs()
    }

    /**
     * Stop the queue coordinator.
     */
    fun stop() {
        isRunning.set(false)
        scheduledTask?.cancel(false)
        scheduledTask = null
        Log.d(TAG, "🛑 NativeQueueCoordinator stopped")
    }

    /**
     * Pause job processing (e.g., during heavy UI activity).
     */
    fun pause() {
        isPaused.set(true)
        Log.d(TAG, "⏸️ NativeQueueCoordinator paused")
    }

    /**
     * Resume job processing.
     */
    fun resume() {
        isPaused.set(false)
        Log.d(TAG, "▶️ NativeQueueCoordinator resumed")

        if (isRunning.get() && pendingJobCount.get() > 0) {
            scheduleNextJob(minDelayBetweenJobs)
        }
    }

    /**
     * Notify that jobs are available (called from Queue.JobsAvailable bridge function).
     */
    fun notifyJobsAvailable(count: Int, queue: String) {
        val wasEmpty = pendingJobCount.get() == 0
        pendingJobCount.set(count)

        Log.d(TAG, "📬 Jobs available: $count on queue '$queue'")

        // If we were idle and now have jobs, start processing
        if (wasEmpty && count > 0 && isRunning.get() && !isPaused.get()) {
            scheduleNextJob(minDelayBetweenJobs)
        }
    }

    // MARK: - Private

    private fun checkForJobs() {
        if (!isRunning.get()) return

        // Make status request to get current job count and config
        makeRequest("/_native/queue/status", "GET") { result ->
            result.fold(
                onSuccess = { json ->
                    val pending = json.optInt("pending", 0)
                    pendingJobCount.set(pending)

                    // Apply config from PHP if available
                    val config = json.optJSONObject("config")
                    if (config != null) {
                        minDelayBetweenJobs = config.optLong("min_delay", 100)
                        maxJobsPerBatch = config.optInt("batch_size", 10)
                        emptyQueuePollInterval = config.optLong("poll_interval", 2000)
                        Log.d(TAG, "📋 Queue config applied: minDelay=${minDelayBetweenJobs}ms, batchSize=$maxJobsPerBatch, pollInterval=${emptyQueuePollInterval}ms")
                    }

                    if (pending > 0 && !isPaused.get()) {
                        scheduleNextJob(minDelayBetweenJobs)
                    } else {
                        // Queue is empty, poll again later
                        scheduleNextJob(emptyQueuePollInterval)
                    }
                },
                onFailure = { error ->
                    Log.e(TAG, "❌ Queue status check failed: ${error.message}")
                    // Retry after a delay
                    scheduleNextJob(emptyQueuePollInterval)
                }
            )
        }
    }

    private fun scheduleNextJob(delayMs: Long) {
        scheduledTask?.cancel(false)
        scheduledTask = executor.schedule({
            processNextJob()
        }, delayMs, TimeUnit.MILLISECONDS)
    }

    private fun processNextJob() {
        if (!isRunning.get()) return

        if (isPaused.get()) {
            Log.d(TAG, "⏸️ Job processing paused, waiting...")
            return
        }

        // Check batch limit
        if (maxJobsPerBatch > 0 && processedInBatch >= maxJobsPerBatch) {
            Log.d(TAG, "📦 Batch limit reached ($maxJobsPerBatch), yielding...")
            processedInBatch = 0
            scheduleNextJob(emptyQueuePollInterval)
            return
        }

        // Process one job
        makeRequest("/_native/queue/work", "POST") { result ->
            if (!isRunning.get()) return@makeRequest

            result.fold(
                onSuccess = { json -> handleWorkResponse(json) },
                onFailure = { error ->
                    Log.e(TAG, "❌ Job processing failed: ${error.message}")
                    scheduleNextJob(emptyQueuePollInterval)
                }
            )
        }
    }

    private fun handleWorkResponse(json: JSONObject) {
        val processed = json.optBoolean("processed", false)
        val pending = json.optInt("pending", 0)
        val durationMs = json.optInt("duration_ms", 0)

        pendingJobCount.set(pending)

        if (processed) {
            processedInBatch++
            totalProcessed++

            // Extract job info
            val jobData = json.optJSONObject("job")
            val jobId = jobData?.optString("id", "unknown") ?: "unknown"
            val jobName = jobData?.optString("name", "unknown") ?: "unknown"
            val queue = jobData?.optString("queue", "default") ?: "default"

            // Check for error (job failed but was processed)
            val error = json.optJSONObject("error")
            if (error != null) {
                val errorMessage = error.optString("message", "Unknown error")
                Log.w(TAG, "⚠️ Job $jobId failed: $errorMessage (${durationMs}ms)")

                // Dispatch failure event
                dispatchEvent("Native\\Mobile\\Queue\\Events\\JobFailed", JSONObject().apply {
                    put("jobId", jobId)
                    put("jobName", jobName)
                    put("error", errorMessage)
                    put("willRetry", true)
                    put("attempts", jobData?.optInt("attempts", 1) ?: 1)
                    put("pending", pending)
                })
            } else {
                Log.d(TAG, "✅ Job $jobId completed: $jobName (${durationMs}ms)")

                // Dispatch success event
                dispatchEvent("Native\\Mobile\\Queue\\Events\\JobCompleted", JSONObject().apply {
                    put("jobId", jobId)
                    put("jobName", jobName)
                    put("queue", queue)
                    put("durationMs", durationMs)
                    put("pending", pending)
                })
            }

            // More jobs? Process next one
            if (pending > 0) {
                scheduleNextJob(minDelayBetweenJobs)
            } else {
                Log.d(TAG, "🎉 Queue empty! Processed $totalProcessed total jobs")

                // Dispatch queue empty event
                dispatchEvent("Native\\Mobile\\Queue\\Events\\QueueEmpty", JSONObject().apply {
                    put("queue", queue)
                    put("processedCount", totalProcessed)
                })

                // Poll again later in case new jobs arrive
                scheduleNextJob(emptyQueuePollInterval)
            }
        } else {
            // Queue was empty
            val reason = json.optString("reason", "unknown")
            Log.d(TAG, "📭 Queue empty (reason: $reason), polling in ${emptyQueuePollInterval}ms")
            scheduleNextJob(emptyQueuePollInterval)
        }
    }

    // MARK: - Helpers

    private fun makeRequest(
        path: String,
        method: String,
        callback: (Result<JSONObject>) -> Unit
    ) {
        val bridge = phpBridge
        if (bridge == null) {
            Log.e(TAG, "❌ makeRequest failed: PHPBridge not initialized")
            callback(Result.failure(IllegalStateException("PHPBridge not initialized")))
            return
        }

        Log.d(TAG, "📡 makeRequest: $method $path")

        executor.execute {
            try {
                val request = PHPRequest(
                    url = path,
                    method = method,
                    headers = mapOf(
                        "Accept" to "application/json",
                        "Content-Type" to "application/json"
                    )
                )

                Log.d(TAG, "📤 Calling handleLaravelRequest...")
                val response = bridge.handleLaravelRequest(request)
                Log.d(TAG, "📥 Got response: ${response.take(200)}...")

                // Parse response - extract body from HTTP response
                val parts = response.split("\r\n\r\n", limit = 2)
                if (parts.size >= 2) {
                    val body = parts[1]
                    Log.d(TAG, "📋 Response body: ${body.take(500)}")
                    val json = JSONObject(body)
                    callback(Result.success(json))
                } else {
                    Log.e(TAG, "❌ Invalid response format - no body separator found")
                    callback(Result.failure(Exception("Invalid response format")))
                }
            } catch (e: Exception) {
                Log.e(TAG, "❌ makeRequest exception: ${e.message}", e)
                callback(Result.failure(e))
            }
        }
    }

    private fun dispatchEvent(event: String, payload: JSONObject) {
        val activity = activityProvider?.invoke()
        if (activity != null) {
            mainHandler.post {
                NativeActionCoordinator.dispatchEvent(activity, event, payload.toString())
            }
        } else {
            Log.e(TAG, "❌ Cannot dispatch event - no activity available")
        }
    }
}
