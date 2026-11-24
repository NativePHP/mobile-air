import Foundation

// C functions from PHP.c — worker runtime (separate TSRM context)
@_silgen_name("worker_php_boot")
private func _worker_php_boot(_ bootstrapPath: UnsafePointer<CChar>?) -> Int32

@_silgen_name("worker_php_artisan")
private func _worker_php_artisan(_ command: UnsafePointer<CChar>?) -> UnsafePointer<CChar>?

@_silgen_name("worker_php_shutdown")
private func _worker_php_shutdown()

@_silgen_name("worker_php_is_booted")
private func _worker_php_is_booted() -> Int32

@_silgen_name("worker_php_has_pending_jobs")
private func _worker_php_has_pending_jobs(_ dbPath: UnsafePointer<CChar>?) -> Int32

/// Background queue worker that processes Laravel queue jobs.
///
/// Uses a dedicated PHP TSRM context (worker runtime) so queue processing
/// never contends with the persistent runtime used for UI requests.
/// Mirrors Android's PHPQueueWorker.
final class PHPQueueWorker {
    static let shared = PHPQueueWorker()

    private let sleepIntervalMs: UInt64 = 1_000
    private let sleepIdleMs: UInt64 = 3_000

    private let condition = NSCondition()
    private var workerThread: Thread?
    private var running = false
    private var workerActive = false
    private var acceptingWake = false
    private var paused = false
    private var woken = false
    private var stopped: DispatchSemaphore?

    private init() {}

    /// Start the worker according to `queue_worker_mode` from `bundle_meta.json`:
    ///   - "disabled": never start
    ///   - "eager": always start immediately
    ///   - "lazy" (default): start only when `jobs` already has pending rows;
    ///     otherwise wait until PHP dispatches `Queue.Wake` on JobQueued.
    func startIfNeeded() {
        let mode = NativePHPApp.getQueueWorkerMode()
        switch mode {
        case "disabled":
            condition.lock()
            acceptingWake = false
            condition.unlock()
            NSLog("PHPQueueWorker: disabled via bundle_meta.json")
            return
        case "eager":
            start()
        default:
            let pending = hasPendingJobs()
            var threadToStart: Thread?

            condition.lock()
            acceptingWake = true
            if pending && !running && !workerActive {
                threadToStart = prepareThreadLocked()
            }
            condition.unlock()

            if let thread = threadToStart {
                NSLog("PHPQueueWorker: pending jobs found in database.sqlite, starting worker")
                thread.start()
                NSLog("PHPQueueWorker: thread launched")
            } else if !pending {
                NSLog("PHPQueueWorker: idle (lazy mode), deferring worker boot until first queued job")
            }
        }
    }

    /// Start the worker on demand (in lazy mode) or interrupt its idle wait
    /// immediately when PHP pushes a job onto the queue.
    @discardableResult
    func wake() -> Bool {
        guard PersistentPHPRuntime.shared.isBooted else {
            return false
        }

        var threadToStart: Thread?

        condition.lock()
        guard acceptingWake else {
            condition.unlock()
            return false
        }

        if running {
            woken = true
            condition.broadcast()
            condition.unlock()
            return true
        }

        guard !workerActive else {
            condition.unlock()
            return false
        }

        threadToStart = prepareThreadLocked()
        condition.unlock()

        threadToStart?.start()
        NSLog("PHPQueueWorker: thread launched on demand")
        return true
    }

    /// Pause polling while the app is backgrounded so the worker stops waking
    /// the CPU every few seconds when idle. Any job already running completes
    /// before the loop parks.
    func pause() {
        condition.lock()
        paused = true
        condition.broadcast()
        condition.unlock()
    }

    /// Resume polling when the app returns to the foreground.
    func resume() {
        condition.lock()
        paused = false
        condition.broadcast()
        condition.unlock()
    }

    /// Start the background queue worker.
    /// Boots a dedicated worker PHP runtime on a new thread, then loops
    /// processing jobs via `queue:work --once`.
    func start() {
        condition.lock()
        acceptingWake = true
        guard !running && !workerActive else {
            condition.unlock()
            NSLog("PHPQueueWorker: already running")
            return
        }

        let thread = prepareThreadLocked()
        condition.unlock()

        thread.start()
        NSLog("PHPQueueWorker: thread launched")
    }

    /// Stop the worker thread (non-blocking; thread exits on next loop iteration).
    func stop() {
        condition.lock()
        acceptingWake = false
        guard running else {
            condition.unlock()
            return
        }

        NSLog("PHPQueueWorker: stopping")
        running = false
        condition.broadcast()
        condition.unlock()
    }

    /// Stop the worker thread and block until it has fully exited and called
    /// _worker_php_shutdown(). This MUST be called before php_embed_shutdown()
    /// (i.e. PersistentPHPRuntime.reboot()) because the global shutdown destroys
    /// shared Zend module state that would corrupt the worker's live TSRM context.
    func stopAndWait() {
        condition.lock()
        acceptingWake = false
        guard running || workerActive else {
            condition.unlock()
            return
        }

        NSLog("PHPQueueWorker: stopAndWait waiting for worker to exit")
        running = false
        let stopSem = stopped
        condition.broadcast()
        condition.unlock()

        stopSem?.wait()
        NSLog("PHPQueueWorker: stopAndWait worker exited")
    }

    var isRunning: Bool {
        condition.lock()
        defer { condition.unlock() }
        return running
    }

    // MARK: - Helpers

    /// Caller must hold `condition`.
    private func prepareThreadLocked() -> Thread {
        let stopSem = DispatchSemaphore(value: 0)
        let thread = Thread {
            self.workerLoop(stopSem: stopSem)
        }
        thread.name = "php-queue-worker"
        thread.qualityOfService = .utility

        running = true
        workerActive = true
        woken = false
        stopped = stopSem
        workerThread = thread
        return thread
    }

    private func hasPendingJobs() -> Bool {
        guard let supportDir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first else {
            return false
        }
        let dbPath = supportDir.appendingPathComponent("database/database.sqlite").path
        guard FileManager.default.fileExists(atPath: dbPath) else {
            return false
        }
        return dbPath.withCString { _worker_php_has_pending_jobs($0) != 0 }
    }

    // MARK: - Worker Loop

    private func workerLoop(stopSem: DispatchSemaphore) {
        defer {
            condition.lock()
            running = false
            workerActive = false
            if stopped === stopSem {
                stopped = nil
            }
            if workerThread === Thread.current {
                workerThread = nil
            }
            condition.broadcast()
            condition.unlock()
            stopSem.signal()
        }

        NSLog("PHPQueueWorker: starting (dedicated TSRM context)")

        let appPath = AppUpdateManager.shared.getAppPath()
        let bootstrapPath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/persistent.php"

        // Boot worker runtime: allocates a new TSRM context and runs the
        // Laravel bootstrap on this thread.
        let result = _worker_php_boot(bootstrapPath)
        if result != 0 {
            NSLog("PHPQueueWorker: boot FAILED (%d), aborting", result)
            return
        }

        NSLog("PHPQueueWorker: boot complete, processing jobs")

        while true {
            condition.lock()
            while running && paused {
                condition.wait()
            }
            let stillRunning = running
            woken = false
            condition.unlock()

            guard stillRunning else { break }

            let output = artisan(command: "queue:work --once --quiet")

            // Log any output for diagnostics (errors from bridge calls, etc.)
            if !output.isEmpty {
                NSLog("PHPQueueWorker: output: %@", output)
            }

            // Shorter sleep if we just processed a job, longer if idle
            let sleepMs: UInt64
            if output.localizedCaseInsensitiveContains("Processed") {
                NSLog("PHPQueueWorker: job processed")
                sleepMs = sleepIntervalMs
            } else {
                sleepMs = sleepIdleMs
            }

            // Interruptible idle wait: returns immediately on `wake()`,
            // `pause()`, or `stopAndWait()`.
            condition.lock()
            if running && !paused && !woken {
                _ = condition.wait(until: Date(timeIntervalSinceNow: Double(sleepMs) / 1000.0))
            }
            condition.unlock()
        }

        _worker_php_shutdown()
        NSLog("PHPQueueWorker: stopped")
    }

    private func artisan(command: String) -> String {
        guard let resultPtr = _worker_php_artisan(command) else {
            return ""
        }
        let result = String(cString: resultPtr)
        free(UnsafeMutableRawPointer(mutating: resultPtr))
        return result
    }
}
