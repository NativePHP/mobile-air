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

/// Background queue worker that processes Laravel queue jobs.
///
/// Uses a dedicated PHP TSRM context (worker runtime) so queue processing
/// never contends with the persistent runtime used for UI requests.
/// Mirrors Android's PHPQueueWorker.
final class PHPQueueWorker {
    static let shared = PHPQueueWorker()

    private let sleepIntervalMs: UInt64 = 1_000
    private let sleepIdleMs: UInt64 = 3_000

    private var workerThread: Thread?
    private var running = false
    private var stopped: DispatchSemaphore?

    /// Signalled by `stopAndWait()` so the idle wait between job passes ends
    /// immediately. Without it the loop only re-checks `running` after the
    /// full interval elapses, so every stop request — and therefore every hot
    /// reload, which stops the worker before rebooting the runtime — waits out
    /// up to `sleepIdleMs`. That was the dominant and most variable part of a
    /// reload: ~4.6s measured, versus ~1.0s once the wait is interruptible.
    private var wake = DispatchSemaphore(value: 0)

    private init() {}

    /// Start the background queue worker.
    /// Boots a dedicated worker PHP runtime on a new thread, then loops
    /// processing jobs via `queue:work --once`.
    func start() {
        guard !running else {
            NSLog("PHPQueueWorker: already running")
            return
        }

        running = true
        stopped = DispatchSemaphore(value: 0)
        wake = DispatchSemaphore(value: 0)

        let thread = Thread {
            self.workerLoop()
        }
        thread.name = "php-queue-worker"
        thread.qualityOfService = .utility
        workerThread = thread
        thread.start()

        NSLog("PHPQueueWorker: thread launched")
    }

    /// Stop the worker thread (non-blocking — thread exits on next loop iteration).
    func stop() {
        guard running else { return }

        NSLog("PHPQueueWorker: stopping")
        running = false
        // Thread will exit on next loop iteration
        workerThread = nil
    }

    /// Stop the worker thread and block until it has fully exited and called
    /// _worker_php_shutdown(). This MUST be called before php_embed_shutdown()
    /// (i.e. PersistentPHPRuntime.reboot()) — the global shutdown destroys
    /// shared Zend module state that would corrupt the worker's live TSRM context.
    func stopAndWait() {
        guard running else { return }

        NSLog("PHPQueueWorker: stopAndWait — waiting for worker to exit")
        running = false
        wake.signal()
        stopped?.wait()
        stopped = nil
        workerThread = nil
        NSLog("PHPQueueWorker: stopAndWait — worker exited")
    }

    var isRunning: Bool { running }

    // MARK: - Worker Loop

    private func workerLoop() {
        NSLog("PHPQueueWorker: starting (dedicated TSRM context)")

        let appPath = AppUpdateManager.shared.getAppPath()
        let bootstrapPath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/persistent.php"

        // Boot worker runtime — this allocates a new TSRM context and
        // runs the Laravel bootstrap on this thread
        let result = _worker_php_boot(bootstrapPath)
        if result != 0 {
            NSLog("PHPQueueWorker: boot FAILED (%d), aborting", result)
            running = false
            return
        }

        NSLog("PHPQueueWorker: boot complete, processing jobs")

        while running {
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

            // Interruptible idle wait — returns as soon as `stopAndWait()`
            // signals, rather than sleeping the full interval first.
            _ = wake.wait(timeout: .now() + Double(sleepMs) / 1000.0)
        }

        _worker_php_shutdown()
        stopped?.signal()
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
