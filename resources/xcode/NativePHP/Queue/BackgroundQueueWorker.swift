import Foundation
import BackgroundTasks

/// Manages background queue processing using iOS BGTaskScheduler.
///
/// This worker handles job processing when the app is in the background,
/// complementing the foreground NativeQueueCoordinator. Jobs are processed
/// in batches during system-allocated background time windows (up to 30 seconds).
///
/// The worker integrates with the existing queue system:
/// - Uses the same `/_native/queue/work` endpoint as foreground processing
/// - Respects the same throttling configuration
/// - Re-schedules itself when jobs remain after processing
final class BackgroundQueueWorker {
    static let shared = BackgroundQueueWorker()

    /// Task identifier for BGTaskScheduler - must match Info.plist
    private var taskIdentifier: String {
        let bundleId = Bundle.main.bundleIdentifier ?? "com.nativephp.app"
        return "\(bundleId).queue-processing"
    }

    // MARK: - Configuration

    /// Maximum time to spend processing jobs (leave buffer for cleanup)
    private let maxProcessingTime: TimeInterval = 25.0

    /// Maximum jobs to process per background session
    private let maxJobsPerSession: Int = 50

    /// Minimum delay between job processing
    private var minDelayBetweenJobs: TimeInterval = 0.1

    // MARK: - State

    private var isProcessing = false
    private var shouldStop = false
    private var pendingJobCount = 0

    private init() {}

    // MARK: - Public API

    /// Register the background task handler.
    /// Must be called during app launch, before applicationDidFinishLaunching returns.
    func register() {
        BGTaskScheduler.shared.register(
            forTaskWithIdentifier: taskIdentifier,
            using: nil
        ) { [weak self] task in
            self?.handleBackgroundTask(task as! BGProcessingTask)
        }

        DebugLogger.shared.log("BackgroundQueue: Registered task handler for \(taskIdentifier)")
    }

    /// Schedule a background task if there are pending jobs.
    /// Called when the app enters background.
    func scheduleIfNeeded() {
        // First check if we have pending jobs via the queue status
        checkPendingJobsAndSchedule()
    }

    /// Cancel any pending background task.
    /// Called when the app returns to foreground.
    func cancelPendingTask() {
        BGTaskScheduler.shared.cancel(taskRequestWithIdentifier: taskIdentifier)
        DebugLogger.shared.log("BackgroundQueue: Cancelled pending background task")
    }

    // MARK: - Private

    private func checkPendingJobsAndSchedule() {
        // Make a quick status check to see if there are jobs to process
        PHPSchemeHandler.phpSerialQueue.async { [weak self] in
            guard let self = self else { return }

            let requestData = RequestData(
                method: "GET",
                uri: "/_native/queue/status",
                data: nil,
                query: nil,
                headers: ["Accept": "application/json"]
            )

            guard let response = NativePHPApp.laravel(request: requestData) else {
                return
            }

            // Parse response
            let components = response.components(separatedBy: "\r\n\r\n")
            guard components.count >= 2,
                  let data = components[1].data(using: .utf8),
                  let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let pending = json["pending"] as? Int else {
                return
            }

            self.pendingJobCount = pending

            // Update config if available
            if let config = json["config"] as? [String: Any],
               let minDelay = config["min_delay"] as? Int {
                self.minDelayBetweenJobs = TimeInterval(minDelay) / 1000.0
            }

            // Only schedule if there are jobs waiting
            if pending > 0 {
                self.scheduleBackgroundTask()
            } else {
                DebugLogger.shared.log("BackgroundQueue: No pending jobs, skipping background task schedule")
            }
        }
    }

    private func scheduleBackgroundTask() {
        let request = BGProcessingTaskRequest(identifier: taskIdentifier)
        request.requiresNetworkConnectivity = false  // SQLite is local
        request.requiresExternalPower = false

        do {
            try BGTaskScheduler.shared.submit(request)
            DebugLogger.shared.log("BackgroundQueue: Scheduled background task with \(pendingJobCount) pending jobs")
        } catch {
            DebugLogger.shared.log("BackgroundQueue: Failed to schedule task: \(error.localizedDescription)")
        }
    }

    private func handleBackgroundTask(_ task: BGProcessingTask) {
        DebugLogger.shared.log("BackgroundQueue: Starting background processing")

        shouldStop = false
        isProcessing = true

        // Set expiration handler to gracefully stop
        task.expirationHandler = { [weak self] in
            DebugLogger.shared.log("BackgroundQueue: System requested expiration")
            self?.shouldStop = true
        }

        // Process jobs in the background
        processBackgroundBatch { [weak self] success, jobsProcessed, remainingJobs in
            guard let self = self else { return }

            self.isProcessing = false

            DebugLogger.shared.log("BackgroundQueue: Completed - processed \(jobsProcessed) jobs, \(remainingJobs) remaining")

            // Re-schedule if jobs remain
            if remainingJobs > 0 {
                self.pendingJobCount = remainingJobs
                self.scheduleBackgroundTask()
            }

            task.setTaskCompleted(success: success)
        }
    }

    private func processBackgroundBatch(completion: @escaping (Bool, Int, Int) -> Void) {
        let startTime = Date()
        var jobsProcessed = 0
        var remainingJobs = 0
        var hadError = false

        func processNextJob() {
            // Check stop conditions
            guard !shouldStop else {
                completion(!hadError, jobsProcessed, remainingJobs)
                return
            }

            // Check time limit
            let elapsed = Date().timeIntervalSince(startTime)
            guard elapsed < maxProcessingTime else {
                DebugLogger.shared.log("BackgroundQueue: Time limit reached after \(jobsProcessed) jobs")
                completion(!hadError, jobsProcessed, remainingJobs)
                return
            }

            // Check job limit
            guard jobsProcessed < maxJobsPerSession else {
                DebugLogger.shared.log("BackgroundQueue: Job limit reached (\(maxJobsPerSession))")
                completion(!hadError, jobsProcessed, remainingJobs)
                return
            }

            // Process one job
            PHPSchemeHandler.phpSerialQueue.async { [weak self] in
                guard let self = self else {
                    completion(false, jobsProcessed, remainingJobs)
                    return
                }

                let requestData = RequestData(
                    method: "POST",
                    uri: "/_native/queue/work",
                    data: nil,
                    query: nil,
                    headers: [
                        "Accept": "application/json",
                        "Content-Type": "application/json"
                    ]
                )

                guard let response = NativePHPApp.laravel(request: requestData) else {
                    hadError = true
                    completion(false, jobsProcessed, remainingJobs)
                    return
                }

                // Parse response
                let components = response.components(separatedBy: "\r\n\r\n")
                guard components.count >= 2,
                      let data = components[1].data(using: .utf8),
                      let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
                    hadError = true
                    completion(false, jobsProcessed, remainingJobs)
                    return
                }

                let processed = json["processed"] as? Bool ?? false
                remainingJobs = json["pending"] as? Int ?? 0

                if processed {
                    jobsProcessed += 1

                    // Extract job info for logging
                    if let jobData = json["job"] as? [String: Any],
                       let jobName = jobData["name"] as? String {
                        DebugLogger.shared.log("BackgroundQueue: Processed job \(jobsProcessed): \(jobName)")
                    }

                    // Continue with next job after delay
                    if remainingJobs > 0 && !self.shouldStop {
                        DispatchQueue.global(qos: .utility).asyncAfter(deadline: .now() + self.minDelayBetweenJobs) {
                            processNextJob()
                        }
                    } else {
                        // Queue empty or stopped
                        completion(!hadError, jobsProcessed, remainingJobs)
                    }
                } else {
                    // Queue was empty
                    DebugLogger.shared.log("BackgroundQueue: Queue empty after \(jobsProcessed) jobs")
                    completion(!hadError, jobsProcessed, 0)
                }
            }
        }

        // Start processing
        processNextJob()
    }
}
