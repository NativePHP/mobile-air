import Foundation

// C functions from PHP.c — async task lane (pool of TSRM contexts)
@_silgen_name("async_php_boot")
private func _async_php_boot(_ bootstrapPath: UnsafePointer<CChar>?) -> Int32

@_silgen_name("async_php_run")
private func _async_php_run(_ handle: Int32, _ taskId: UnsafePointer<CChar>?) -> UnsafePointer<CChar>?

@_silgen_name("async_php_stop")
private func _async_php_stop(_ handle: Int32)

/// Runs dispatched async tasks immediately on a small pool of background PHP
/// contexts — the lane behind `AsyncTask::dispatch()`.
///
/// Distinct from `PHPQueueWorker`: it never polls `queue:work` or touches SQLite,
/// and it is concurrent (one in-flight task per slot). Each slot is a C worker
/// thread with its own booted TSRM context; here each slot handle is pinned to a
/// dedicated serial `DispatchQueue`, so a slot is only ever driven by one thread
/// and its context stays thread-local.
///
/// The task payload and result travel via the PHP temp-file transport plus the
/// `AsyncTask.Complete` bridge function (which wakes the UI runloop).
///
/// Android twin: `AsyncTaskExecutor.kt`.
final class AsyncTaskExecutor {
    static let shared = AsyncTaskExecutor()

    private let poolSize = 4

    /// One serial queue per booted slot; `slots[i]` runs on `queues[i]`.
    private var slotHandles: [Int32] = []
    private var queues: [DispatchQueue] = []

    /// Round-robins dispatched tasks across the slot queues.
    private var nextSlot = 0
    private let lock = NSLock()

    private var running = false

    private init() {}

    var isRunning: Bool { running }

    /// Boot the slot pool and begin accepting dispatches. Slots boot their PHP
    /// context up front (each is a full Laravel boot), mirroring the queue worker.
    func start() {
        guard !running else {
            NSLog("AsyncTaskExecutor: already running")
            return
        }

        let appPath = AppUpdateManager.shared.getAppPath()
        let bootstrapPath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/persistent.php"

        // Boot on a background thread so we never block the caller (deferred init).
        DispatchQueue.global(qos: .utility).async {
            for i in 0..<self.poolSize {
                let handle = bootstrapPath.withCString { _async_php_boot($0) }
                if handle < 0 {
                    NSLog("AsyncTaskExecutor: slot %d boot FAILED (%d)", i, handle)
                    continue
                }
                self.lock.lock()
                self.slotHandles.append(handle)
                self.queues.append(DispatchQueue(label: "nativephp-async-\(handle)", qos: .utility))
                self.lock.unlock()
            }

            self.running = !self.slotHandles.isEmpty
            NSLog("AsyncTaskExecutor: started (%d/%d slots)", self.slotHandles.count, self.poolSize)
        }
    }

    /// Enqueue a dispatched task id on the next slot. Returns immediately.
    func dispatch(taskId: String) {
        lock.lock()
        let slotCount = slotHandles.count
        guard slotCount > 0 else {
            lock.unlock()
            NSLog("AsyncTaskExecutor: dispatch(%@) dropped — no booted slots", taskId)
            return
        }
        let index = nextSlot % slotCount
        nextSlot = (nextSlot + 1) % slotCount
        let handle = slotHandles[index]
        let queue = queues[index]
        lock.unlock()

        queue.async {
            if let resultPtr = _async_php_run(handle, taskId) {
                free(UnsafeMutableRawPointer(mutating: resultPtr))
            }
        }
    }

    /// Stop the pool and shut down every slot's context. MUST be called before a
    /// persistent-runtime reboot (php_embed_shutdown destroys shared Zend module
    /// state the slots' live TSRM contexts reference).
    func stop() {
        guard running else { return }
        running = false

        lock.lock()
        let handles = slotHandles
        let slotQueues = queues
        slotHandles.removeAll()
        queues.removeAll()
        nextSlot = 0
        lock.unlock()

        // Run each slot's shutdown ON its own serial queue, so it serializes
        // AFTER any in-flight task rather than racing a blocked async_php_run
        // on the slot's semaphore.
        for (handle, queue) in zip(handles, slotQueues) {
            queue.sync {
                _async_php_stop(handle)
            }
        }
        NSLog("AsyncTaskExecutor: stopped")
    }
}
