package com.nativephp.mobile.queue

import android.content.Context
import android.util.Log
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.nativephp.mobile.bridge.PHPBridge
import com.nativephp.mobile.network.PHPRequest
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.util.concurrent.TimeUnit

/**
 * WorkManager-based background worker for processing Laravel queue jobs.
 *
 * This worker handles job processing when the app is in the background,
 * complementing the foreground NativeQueueCoordinator. Jobs are processed
 * in batches during system-allocated background work windows.
 *
 * The worker integrates with the existing queue system:
 * - Uses the same `/_native/queue/work` endpoint as foreground processing
 * - Respects the same throttling configuration from PHP
 * - Re-schedules itself when jobs remain after processing
 */
class BackgroundQueueWorker(
    context: Context,
    params: WorkerParameters
) : CoroutineWorker(context, params) {

    companion object {
        private const val TAG = "BackgroundQueueWorker"
        private const val WORK_NAME = "nativephp_queue_processing"

        // Default configuration
        private const val DEFAULT_INTERVAL_MINUTES = 15L
        private const val MAX_PROCESSING_TIME_MS = 9 * 60 * 1000L  // 9 minutes (leave buffer)
        private const val MAX_JOBS_PER_SESSION = 50
        private const val DEFAULT_MIN_DELAY_MS = 100L

        /**
         * Schedule periodic background queue processing.
         * Called when the app enters background.
         *
         * @param context Application context
         * @param intervalMinutes Minimum interval between work executions (default 15)
         */
        fun schedule(context: Context, intervalMinutes: Long = DEFAULT_INTERVAL_MINUTES) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.NOT_REQUIRED)  // SQLite is local
                .build()

            val workRequest = PeriodicWorkRequestBuilder<BackgroundQueueWorker>(
                intervalMinutes, TimeUnit.MINUTES
            )
                .setConstraints(constraints)
                .setBackoffCriteria(
                    BackoffPolicy.EXPONENTIAL,
                    10, TimeUnit.SECONDS
                )
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                workRequest
            )

            Log.d(TAG, "Scheduled background queue processing with ${intervalMinutes}min interval")
        }

        /**
         * Cancel any pending background work.
         * Called when the app returns to foreground.
         */
        fun cancel(context: Context) {
            WorkManager.getInstance(context).cancelUniqueWork(WORK_NAME)
            Log.d(TAG, "Cancelled background queue processing")
        }
    }

    override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
        Log.d(TAG, "Starting background queue processing")

        val phpBridge = PHPBridge.getInstance()
        if (phpBridge == null) {
            Log.w(TAG, "PHPBridge not available, retrying later")
            return@withContext Result.retry()
        }

        var minDelayMs = DEFAULT_MIN_DELAY_MS
        var jobsProcessed = 0
        var remainingJobs = 0
        val startTime = System.currentTimeMillis()

        try {
            // First, check queue status and get config
            val statusResponse = makeRequest(phpBridge, "/_native/queue/status", "GET")
            if (statusResponse != null) {
                val pending = statusResponse.optInt("pending", 0)
                if (pending == 0) {
                    Log.d(TAG, "Queue is empty, nothing to process")
                    return@withContext Result.success()
                }

                // Apply config from PHP
                val config = statusResponse.optJSONObject("config")
                if (config != null) {
                    minDelayMs = config.optLong("min_delay", DEFAULT_MIN_DELAY_MS)
                }
            }

            // Process jobs in a loop
            while (true) {
                // Check time limit
                val elapsed = System.currentTimeMillis() - startTime
                if (elapsed >= MAX_PROCESSING_TIME_MS) {
                    Log.d(TAG, "Time limit reached after $jobsProcessed jobs")
                    break
                }

                // Check job limit
                if (jobsProcessed >= MAX_JOBS_PER_SESSION) {
                    Log.d(TAG, "Job limit reached ($MAX_JOBS_PER_SESSION)")
                    break
                }

                // Check if stopped
                if (isStopped) {
                    Log.d(TAG, "Worker stopped after $jobsProcessed jobs")
                    break
                }

                // Process one job
                val workResponse = makeRequest(phpBridge, "/_native/queue/work", "POST")
                if (workResponse == null) {
                    Log.e(TAG, "Failed to get work response")
                    break
                }

                val processed = workResponse.optBoolean("processed", false)
                remainingJobs = workResponse.optInt("pending", 0)

                if (processed) {
                    jobsProcessed++

                    // Log job info
                    val jobData = workResponse.optJSONObject("job")
                    val jobName = jobData?.optString("name", "unknown") ?: "unknown"
                    Log.d(TAG, "Processed job $jobsProcessed: $jobName")

                    // Continue with next job after delay if more jobs remain
                    if (remainingJobs > 0) {
                        delay(minDelayMs)
                    } else {
                        Log.d(TAG, "Queue empty after $jobsProcessed jobs")
                        break
                    }
                } else {
                    // Queue was empty
                    Log.d(TAG, "Queue empty (processed=false)")
                    break
                }
            }

            Log.d(TAG, "Background processing complete: $jobsProcessed jobs processed, $remainingJobs remaining")
            Result.success()

        } catch (e: Exception) {
            Log.e(TAG, "Error during background processing", e)
            Result.retry()
        }
    }

    /**
     * Make an HTTP request to the Laravel queue endpoint.
     */
    private fun makeRequest(phpBridge: PHPBridge, path: String, method: String): JSONObject? {
        return try {
            val request = PHPRequest(
                url = path,
                method = method,
                headers = mapOf(
                    "Accept" to "application/json",
                    "Content-Type" to "application/json"
                )
            )

            val response = phpBridge.handleLaravelRequest(request)

            // Parse response - extract body from HTTP response
            val parts = response.split("\r\n\r\n", limit = 2)
            if (parts.size >= 2) {
                JSONObject(parts[1])
            } else {
                null
            }
        } catch (e: Exception) {
            Log.e(TAG, "Request failed: ${e.message}")
            null
        }
    }
}
