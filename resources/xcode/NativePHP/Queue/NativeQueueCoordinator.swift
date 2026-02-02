import Foundation

/// Coordinates background queue processing for Laravel jobs.
///
/// The coordinator monitors the job queue and triggers processing during idle periods.
/// It ensures jobs don't interfere with UI responsiveness by:
/// - Processing one job at a time
/// - Pausing during active user interaction
/// - Throttling based on configuration
///
/// Jobs are processed via HTTP requests to `/_native/queue/work`, which executes
/// one job and returns. The coordinator decides when to process the next job.
final class NativeQueueCoordinator {
    static let shared = NativeQueueCoordinator()

    // MARK: - Configuration

    /// Minimum delay between job processing attempts (seconds)
    var minDelayBetweenJobs: TimeInterval = 0.1

    /// Delay when queue is empty before checking again (seconds)
    var emptyQueuePollInterval: TimeInterval = 2.0

    /// Maximum jobs to process per batch before yielding (0 = unlimited)
    var maxJobsPerBatch: Int = 10

    /// Whether to pause processing during active scrolling
    var pauseDuringScroll: Bool = true

    // MARK: - State

    private var isRunning = false
    private var isPaused = false
    private var pendingJobCount = 0
    private var processedInBatch = 0
    private var totalProcessed = 0

    private let processingQueue = DispatchQueue(
        label: "com.nativephp.queue.coordinator",
        qos: .utility
    )

    private var workTimer: DispatchSourceTimer?

    private init() {}

    // MARK: - Public API

    /// Start the queue coordinator.
    /// Called automatically when the app launches.
    func start() {
        processingQueue.async { [weak self] in
            guard let self = self, !self.isRunning else { return }

            self.isRunning = true
            self.processedInBatch = 0
            self.checkForJobs()
        }
    }

    /// Stop the queue coordinator.
    func stop() {
        processingQueue.async { [weak self] in
            guard let self = self else { return }

            self.isRunning = false
            self.workTimer?.cancel()
            self.workTimer = nil
        }
    }

    /// Pause job processing (e.g., during heavy UI activity).
    func pause() {
        processingQueue.async { [weak self] in
            self?.isPaused = true
        }
    }

    /// Resume job processing.
    func resume() {
        processingQueue.async { [weak self] in
            guard let self = self else { return }

            self.isPaused = false

            if self.isRunning && self.pendingJobCount > 0 {
                self.scheduleNextJob(delay: self.minDelayBetweenJobs)
            }
        }
    }

    /// Notify that jobs are available (called from Queue.JobsAvailable bridge function).
    func notifyJobsAvailable(count: Int, queue: String) {
        processingQueue.async { [weak self] in
            guard let self = self else { return }

            let wasEmpty = self.pendingJobCount == 0
            self.pendingJobCount = count

            // If we were idle and now have jobs, start processing
            if wasEmpty && count > 0 && self.isRunning && !self.isPaused {
                self.scheduleNextJob(delay: self.minDelayBetweenJobs)
            }
        }
    }

    // MARK: - Private

    private func checkForJobs() {
        guard isRunning else { return }

        // Make status request to get current job count and config
        makeRequest(path: "/_native/queue/status") { [weak self] result in
            guard let self = self else { return }

            switch result {
            case .success(let data):
                if let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                   let pending = json["pending"] as? Int {
                    self.pendingJobCount = pending

                    // Apply config from PHP if available
                    if let config = json["config"] as? [String: Any] {
                        if let minDelay = config["min_delay"] as? Int {
                            self.minDelayBetweenJobs = TimeInterval(minDelay) / 1000.0
                        }
                        if let batchSize = config["batch_size"] as? Int {
                            self.maxJobsPerBatch = batchSize
                        }
                        if let pollInterval = config["poll_interval"] as? Int {
                            self.emptyQueuePollInterval = TimeInterval(pollInterval) / 1000.0
                        }
                    }

                    if pending > 0 && !self.isPaused {
                        self.scheduleNextJob(delay: self.minDelayBetweenJobs)
                    } else {
                        // Queue is empty, poll again later
                        self.scheduleNextJob(delay: self.emptyQueuePollInterval)
                    }
                }

            case .failure:
                // Retry after a delay
                self.scheduleNextJob(delay: self.emptyQueuePollInterval)
            }
        }
    }

    private func scheduleNextJob(delay: TimeInterval) {
        workTimer?.cancel()

        let timer = DispatchSource.makeTimerSource(queue: processingQueue)
        timer.schedule(deadline: .now() + delay)
        timer.setEventHandler { [weak self] in
            self?.processNextJob()
        }
        timer.resume()
        workTimer = timer
    }

    private func processNextJob() {
        guard isRunning, !isPaused else { return }

        // Check batch limit
        if maxJobsPerBatch > 0 && processedInBatch >= maxJobsPerBatch {
            processedInBatch = 0
            scheduleNextJob(delay: emptyQueuePollInterval)
            return
        }

        // Process one job
        makeRequest(path: "/_native/queue/work", method: "POST") { [weak self] result in
            guard let self = self, self.isRunning else { return }

            switch result {
            case .success(let data):
                self.handleWorkResponse(data)

            case .failure:
                self.scheduleNextJob(delay: self.emptyQueuePollInterval)
            }
        }
    }

    private func handleWorkResponse(_ data: Data) {
        guard let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            scheduleNextJob(delay: emptyQueuePollInterval)
            return
        }

        let processed = json["processed"] as? Bool ?? false
        let pending = json["pending"] as? Int ?? 0

        self.pendingJobCount = pending

        if processed {
            processedInBatch += 1
            totalProcessed += 1

            // Extract job info
            let jobData = json["job"] as? [String: Any]
            let jobId = jobData?["id"] as? String ?? "unknown"
            let jobName = jobData?["name"] as? String ?? "unknown"
            let queue = jobData?["queue"] as? String ?? "default"

            // Check for error (job failed but was processed)
            if let error = json["error"] as? [String: Any] {
                let errorMessage = error["message"] as? String ?? "Unknown error"

                // Dispatch failure event
                LaravelBridge.shared.send?("Native\\Mobile\\Queue\\Events\\JobFailed", [
                    "jobId": jobId,
                    "jobName": jobName,
                    "error": errorMessage,
                    "willRetry": true,
                    "attempts": jobData?["attempts"] as? Int ?? 1,
                    "pending": pending
                ])
            } else {
                // Dispatch success event
                LaravelBridge.shared.send?("Native\\Mobile\\Queue\\Events\\JobCompleted", [
                    "jobId": jobId,
                    "jobName": jobName,
                    "queue": queue,
                    "durationMs": durationMs,
                    "pending": pending
                ])
            }

            // More jobs? Process next one
            if pending > 0 {
                scheduleNextJob(delay: minDelayBetweenJobs)
            } else {
                // Dispatch queue empty event
                LaravelBridge.shared.send?("Native\\Mobile\\Queue\\Events\\QueueEmpty", [
                    "queue": jobData?["queue"] as? String ?? "default",
                    "processedCount": totalProcessed
                ])

                // Poll again later in case new jobs arrive
                scheduleNextJob(delay: emptyQueuePollInterval)
            }
        } else {
            // Queue was empty
            scheduleNextJob(delay: emptyQueuePollInterval)
        }
    }

    // MARK: - HTTP Helpers

    private func makeRequest(
        path: String,
        method: String = "GET",
        completion: @escaping (Result<Data, Error>) -> Void
    ) {
        // Execute through PHP on the shared serial queue to prevent concurrent PHP execution
        // Uses the same queue as PHPSchemeHandler to ensure WebView and queue requests don't overlap
        PHPSchemeHandler.phpSerialQueue.async {
            let requestData = RequestData(
                method: method,
                uri: path,
                data: nil,
                query: nil,
                headers: [
                    "Accept": "application/json",
                    "Content-Type": "application/json"
                ]
            )

            guard let response = NativePHPApp.laravel(request: requestData) else {
                completion(.failure(NSError(domain: "NativeQueueCoordinator", code: -2,
                                           userInfo: [NSLocalizedDescriptionKey: "No response from PHP"])))
                return
            }

            // Parse response - extract body from HTTP response
            let components = response.components(separatedBy: "\r\n\r\n")
            if components.count >= 2 {
                let body = components[1]
                if let data = body.data(using: .utf8) {
                    completion(.success(data))
                    return
                }
            }

            completion(.failure(NSError(domain: "NativeQueueCoordinator", code: -3,
                                       userInfo: [NSLocalizedDescriptionKey: "Invalid response format"])))
        }
    }
}
