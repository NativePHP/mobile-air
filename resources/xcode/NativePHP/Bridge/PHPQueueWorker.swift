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
///
/// The worker is a whole Laravel boot of its own, so it only boots when there
/// is work for it:
///
/// - PHP calls `QueueWorker.Wake` whenever a job is pushed to the default
///   connection (`QueueWorkerSignal`), which boots the worker or cuts its wait
///   short.
/// - PHP also leaves `storage/framework/queue_pending` on every push, and only
///   the worker removes it, once the queue is empty. `start()` checks for that
///   file, so jobs still queued when the app last closed run after launch,
///   without booting Laravel just to find out.
///
/// Once it has gone `idleTimeout` seconds without being woken, the worker asks
/// PHP (`native:queue:idle`) what is left. An empty queue shuts it down. Jobs
/// that are delayed or waiting to be retried shut it down too, with a timer to
/// wake it when the first is due. An app that never queues anything never boots
/// the worker.
final class PHPQueueWorker {
    static let shared = PHPQueueWorker()

    private let sleepIntervalMs: UInt64 = 1_000
    private let sleepIdleMs: UInt64 = 3_000

    /// Seconds without a wake before the worker checks whether it can stop.
    private let idleTimeout: TimeInterval = 30

    /// When the queue holds jobs but can't say when they are due (a driver
    /// other than `database`), look again after this long.
    private let unknownDueRecheck: Int = 60

    private let lock = NSLock()

    // Guarded by `lock`.

    /// Between `start()` and `stop()`: wakes are taken.
    private var enabled = false
    /// A worker thread exists (booting, polling or shutting down).
    private var threadAlive = false
    /// A wake arrived that the worker loop has not seen yet.
    private var wakeRequested = false
    /// Signalled when the current worker thread has shut its context down.
    private var stopped: DispatchSemaphore?
    /// Wakes the worker when its next delayed job is due.
    private var dueTimer: DispatchWorkItem?

    /// Signalled by `wake()` and `stopAndWait()` so the wait between job passes
    /// ends at once. Without it every stop request — and therefore every hot
    /// reload, which stops the worker before rebooting the runtime — waits out
    /// up to `sleepIdleMs`. That was the dominant and most variable part of a
    /// reload: ~4.6s measured, versus ~1.0s once the wait is interruptible.
    private let wakeSignal = DispatchSemaphore(value: 0)

    private init() {}

    /// Where PHP leaves word that jobs may be queued (`QueueWorkerSignal::markerPath()`).
    static var pendingMarkerPath: String? {
        FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first?
            .appendingPathComponent("storage/framework/queue_pending").path
    }

    /// Take wakes from now on, and boot the worker if jobs were left queued.
    /// Boots nothing when there is no sign of queued work.
    func start() {
        lock.lock()
        enabled = true
        lock.unlock()

        if let marker = PHPQueueWorker.pendingMarkerPath, FileManager.default.fileExists(atPath: marker) {
            NSLog("PHPQueueWorker: jobs may be queued from an earlier run; booting")
            wake()
        } else {
            NSLog("PHPQueueWorker: idle until a job is queued")
        }
    }

    /// Boot the worker, or have the running one poll now. Called on every
    /// push, from whichever PHP thread pushed. Never blocks.
    func wake() {
        lock.lock()
        guard enabled else {
            lock.unlock()
            return
        }

        wakeRequested = true

        guard !threadAlive else {
            lock.unlock()
            wakeSignal.signal()
            return
        }

        threadAlive = true
        let done = DispatchSemaphore(value: 0)
        stopped = done
        lock.unlock()

        let thread = Thread {
            self.workerLoop(done: done)
        }
        thread.name = "php-queue-worker"
        thread.qualityOfService = .utility
        thread.start()

        NSLog("PHPQueueWorker: thread launched")
    }

    /// Stop the worker thread (non-blocking — thread exits on next loop iteration).
    func stop() {
        lock.lock()
        guard enabled || threadAlive else {
            lock.unlock()
            return
        }
        enabled = false
        dueTimer?.cancel()
        dueTimer = nil
        lock.unlock()

        NSLog("PHPQueueWorker: stopping")
        wakeSignal.signal()
    }

    /// Stop the worker thread and block until it has fully exited and called
    /// _worker_php_shutdown(). This MUST be called before php_embed_shutdown()
    /// (i.e. PersistentPHPRuntime.reboot()) — the global shutdown destroys
    /// shared Zend module state that would corrupt the worker's live TSRM context.
    func stopAndWait() {
        lock.lock()
        enabled = false
        dueTimer?.cancel()
        dueTimer = nil
        let alive = threadAlive
        let done = stopped
        lock.unlock()

        guard alive, let done else { return }

        NSLog("PHPQueueWorker: stopAndWait — waiting for worker to exit")
        wakeSignal.signal()
        done.wait()
        NSLog("PHPQueueWorker: stopAndWait — worker exited")
    }

    var isRunning: Bool {
        lock.lock()
        defer { lock.unlock() }
        return threadAlive
    }

    // MARK: - Worker Loop

    /// What `native:queue:idle` said: jobs left, and seconds until the first
    /// is due (nil when unknown).
    private struct IdleStatus {
        let pending: Int?
        let next: Int?
    }

    private func workerLoop(done: DispatchSemaphore) {
        let appPath = AppUpdateManager.shared.getAppPath()
        let bootstrapPath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/persistent.php"

        // Each pass boots a context, works until idle, and shuts it down. A
        // wake that lands while it shuts down starts another pass on this
        // thread, so two contexts never overlap in the C worker's globals.
        while true {
            NSLog("PHPQueueWorker: starting (dedicated TSRM context)")
            let started = DispatchTime.now()

            let result = _worker_php_boot(bootstrapPath)
            if result != 0 {
                NSLog("PHPQueueWorker: boot FAILED (%d), aborting", result)
                lock.lock()
                threadAlive = false
                wakeRequested = false
                lock.unlock()
                done.signal()
                return
            }

            let ms = Double(DispatchTime.now().uptimeNanoseconds - started.uptimeNanoseconds) / 1_000_000
            NSLog("PHPQueueWorker: boot complete in %.0f ms, processing jobs", ms)

            let wakeAgainIn = processUntilIdle()

            _worker_php_shutdown()

            lock.lock()
            let again = enabled && wakeRequested
            if !again {
                threadAlive = false
                if enabled, let seconds = wakeAgainIn {
                    armDueTimer(seconds: seconds)
                }
            }
            lock.unlock()

            if again {
                NSLog("PHPQueueWorker: woken while shutting down; booting again")
                continue
            }

            done.signal()
            NSLog("PHPQueueWorker: stopped")
            return
        }
    }

    /// Run `queue:work --once` until the worker has gone `idleTimeout` without
    /// a wake and PHP says nothing is ready to run. Returns the seconds after
    /// which to boot again for a job that is not due yet, or nil for none.
    private func processUntilIdle() -> Int? {
        var idleSince = Date()

        while true {
            lock.lock()
            if !enabled {
                lock.unlock()
                return nil
            }
            if wakeRequested {
                wakeRequested = false
                idleSince = Date()
            }
            lock.unlock()

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
                idleSince = Date()
            } else {
                sleepMs = sleepIdleMs
            }

            if Date().timeIntervalSince(idleSince) >= idleTimeout {
                let status = idleStatus()

                switch (status.pending, status.next) {
                case (0, _):
                    NSLog("PHPQueueWorker: queue empty; shutting down until a job is queued")
                    return nil
                case (nil, _):
                    // PHP couldn't read the queue. The marker stays, so the
                    // next launch or push tries again.
                    NSLog("PHPQueueWorker: queue state unknown; shutting down")
                    return nil
                case let (pending?, next?) where next * 1000 <= Int(sleepIdleMs):
                    // Due now or within one poll: keep going.
                    NSLog("PHPQueueWorker: %d job(s) still due; staying up", pending)
                    idleSince = Date()
                case let (pending?, next?):
                    NSLog("PHPQueueWorker: %d job(s) not due for %ds; shutting down until then", pending, next)
                    return next
                case let (pending?, nil):
                    NSLog("PHPQueueWorker: %d job(s) left with no due time; checking again in %ds", pending, unknownDueRecheck)
                    return unknownDueRecheck
                }
            }

            // Interruptible idle wait — returns as soon as `wake()` or
            // `stopAndWait()` signals, rather than sleeping the full interval.
            _ = wakeSignal.wait(timeout: .now() + Double(sleepMs) / 1000.0)
        }
    }

    private func idleStatus() -> IdleStatus {
        let output = artisan(command: "native:queue:idle")

        // The JSON line is the last thing the command prints.
        guard let line = output.split(whereSeparator: \.isNewline).last(where: { $0.hasPrefix("{") }),
              let data = String(line).data(using: .utf8),
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            NSLog("PHPQueueWorker: unreadable idle status: %@", output)
            return IdleStatus(pending: nil, next: nil)
        }

        return IdleStatus(
            pending: (json["pending"] as? NSNumber)?.intValue,
            next: (json["next"] as? NSNumber)?.intValue
        )
    }

    /// Wake the worker in `seconds`. Call with `lock` held.
    private func armDueTimer(seconds: Int) {
        dueTimer?.cancel()
        let item = DispatchWorkItem { [weak self] in
            NSLog("PHPQueueWorker: a delayed job is due; waking")
            self?.wake()
        }
        dueTimer = item
        DispatchQueue.global(qos: .utility).asyncAfter(deadline: .now() + .seconds(max(1, seconds)), execute: item)
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

// MARK: - QueueWorker.Wake

/// Bridge function PHP calls when a job is pushed (`QueueWorkerSignal::wake()`).
///
/// A web view can call it too, through `/_native/api/call`. All it can do with
/// that is boot the worker, which then finds nothing and shuts down again.
///
/// Android twin: `bridge/functions/QueueWorkerFunctions.kt`.
enum QueueWorkerFunctions {
    class Wake: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            PHPQueueWorker.shared.wake()
            return ["success": true]
        }
    }
}
