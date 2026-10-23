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
/// Distinct from `PHPQueueWorker`: it never polls `queue:work` or touches the
/// standard queue, and it is concurrent (one in-flight task per slot). Each slot
/// is a C worker thread with its own booted TSRM context; here each slot handle
/// is pinned to a dedicated serial `DispatchQueue`, so a slot is only ever driven
/// by one thread and its context stays thread-local.
///
/// The task payload and result travel via the PHP temp-file transport plus the
/// `AsyncTask.Complete` bridge function (which wakes the UI runloop).
///
/// Android twin: `AsyncTaskExecutor.kt`.
final class AsyncTaskExecutor {
    static let shared = AsyncTaskExecutor()

    private let poolSize = 4

    /// How long `stop()` waits for an in-flight boot before tearing down anyway.
    private let bootWaitTimeout: TimeInterval = 30

    /// One serial queue per booted slot; `slots[i]` runs on `queues[i]`.
    private var slotHandles: [Int32] = []
    private var queues: [DispatchQueue] = []

    /// Round-robins dispatched tasks across the slot queues.
    private var nextSlot = 0
    private let lock = NSLock()

    /// All mutable state below is guarded by `lock`.
    private var running = false

    /// True from `start()` until the boot loop finishes. `stop()` waits this out
    /// rather than bailing: a stop that lands mid-boot must still tear the slots
    /// down, or their C handles leak and the pool can never be started again.
    private var starting = false
    private let bootFinished = DispatchGroup()

    /// Timeout work items for dispatched tasks, keyed by task id.
    private var watchdogs: [String: DispatchWorkItem] = [:]
    private let watchdogQueue = DispatchQueue(label: "nativephp-async-watchdog")

    /// Tasks dispatched while the pool is still booting, run once it's up. The
    /// pool boots lazily after launch, and a task dispatched in that window is
    /// worth holding for a moment rather than failing outright.
    private var pending: [String] = []

    private init() {}

    var isRunning: Bool {
        lock.lock()
        defer { lock.unlock() }
        return running
    }

    /// Boot the slot pool and begin accepting dispatches. Slots boot their PHP
    /// context up front (each is a full Laravel boot), mirroring the queue worker.
    func start() {
        lock.lock()
        guard !running, !starting else {
            lock.unlock()
            NSLog("AsyncTaskExecutor: already running")
            return
        }
        starting = true
        lock.unlock()

        let appPath = AppUpdateManager.shared.getAppPath()
        let bootstrapPath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/persistent.php"

        bootFinished.enter()

        // Boot on a background thread so we never block the caller (deferred init).
        DispatchQueue.global(qos: .utility).async {
            defer { self.bootFinished.leave() }

            for i in 0..<self.poolSize {
                // A stop() that arrived mid-boot: don't keep booting slots
                // nobody is going to tear down.
                self.lock.lock()
                let aborted = !self.starting
                self.lock.unlock()
                if aborted {
                    NSLog("AsyncTaskExecutor: boot aborted after %d slot(s)", i)
                    break
                }

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

            self.lock.lock()
            let aborted = !self.starting
            self.starting = false
            self.running = !aborted && !self.slotHandles.isEmpty
            let booted = self.slotHandles.count
            let queued = self.pending
            self.pending.removeAll()
            let live = self.running
            self.lock.unlock()

            NSLog("AsyncTaskExecutor: started (%d/%d slots)", booted, self.poolSize)

            // Anything dispatched while we were booting runs now. If the pool
            // never came up, their watchdogs are what will unblock the UI.
            for taskId in queued {
                if live {
                    _ = self.submit(taskId: taskId)
                } else {
                    NSLog("AsyncTaskExecutor: task %@ dropped — pool failed to boot", taskId)
                }
            }
        }
    }

    /// Enqueue a dispatched task id on the next slot. Returns immediately.
    ///
    /// A non-zero `timeout` arms a watchdog: if the deadline passes with nothing
    /// delivered, `timeoutEvent`/`timeoutPayloadJson` — built by PHP at dispatch
    /// time, so this stays a dumb courier — are posted into the UI runloop and
    /// `->failed()` fires instead of the UI waiting forever. The deadline covers
    /// queue time as well as run time.
    ///
    /// Returns false when the task was NOT accepted, so the bridge function can
    /// tell PHP and the dispatch can fail loudly at the call site.
    @discardableResult
    func dispatch(
        taskId: String,
        timeout: Int = 0,
        timeoutEvent: String? = nil,
        timeoutPayloadJson: String? = nil
    ) -> Bool {
        // Arm before submitting, so the deadline covers the wait for a slot.
        if timeout > 0, let event = timeoutEvent, let payloadJson = timeoutPayloadJson {
            armWatchdog(taskId: taskId, timeout: timeout, event: event, payloadJson: payloadJson)
        }

        guard submit(taskId: taskId) else {
            disarmWatchdog(taskId: taskId)
            return false
        }

        return true
    }

    /// Hand a task to a slot, or hold it if the pool is still booting.
    private func submit(taskId: String) -> Bool {
        lock.lock()

        if starting {
            pending.append(taskId)
            lock.unlock()
            return true
        }

        let slotCount = slotHandles.count
        guard running, slotCount > 0 else {
            lock.unlock()
            NSLog("AsyncTaskExecutor: dispatch(%@) dropped — no booted slots", taskId)
            return false
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
            // The run is over either way — a completion (or a failure) has
            // already been posted from PHP.
            self.disarmWatchdog(taskId: taskId)
        }

        return true
    }

    /// Fail a task that has outrun its deadline. The PHP interpreter running it
    /// can't be interrupted safely, so the task keeps going; what this does is
    /// unblock the UI. A late completion for an already-failed task is discarded
    /// on the PHP side (its callbacks are gone by then).
    private func armWatchdog(taskId: String, timeout: Int, event: String, payloadJson: String) {
        let item = DispatchWorkItem { [weak self] in
            guard let self else { return }
            self.lock.lock()
            let armed = self.watchdogs.removeValue(forKey: taskId) != nil
            self.lock.unlock()
            guard armed else { return }

            NSLog("AsyncTaskExecutor: task %@ timed out; reporting failure to the UI", taskId)
            NativeElementBridge.sendNativeEvent(eventName: event, payloadJson: payloadJson)
        }

        lock.lock()
        watchdogs[taskId] = item
        lock.unlock()

        watchdogQueue.asyncAfter(deadline: .now() + .seconds(timeout), execute: item)
    }

    private func disarmWatchdog(taskId: String) {
        lock.lock()
        let item = watchdogs.removeValue(forKey: taskId)
        lock.unlock()
        item?.cancel()
    }

    /// Stop the pool and shut down every slot's context. MUST be called before a
    /// persistent-runtime reboot (php_embed_shutdown destroys shared Zend module
    /// state the slots' live TSRM contexts reference).
    func stop() {
        lock.lock()
        let wasStarting = starting
        // Tell an in-flight boot loop to stop adding slots.
        starting = false
        let hasSlots = !slotHandles.isEmpty
        guard running || wasStarting || hasSlots else {
            lock.unlock()
            return
        }
        running = false
        lock.unlock()

        // A boot in progress owns C slots we can't see yet. Wait for it rather
        // than returning early: bailing here would leave those slots allocated
        // forever, and with `start()` refusing to run twice every later dispatch
        // would be dropped for the life of the app.
        if wasStarting, bootFinished.wait(timeout: .now() + bootWaitTimeout) == .timedOut {
            NSLog("AsyncTaskExecutor: timed out waiting for boot to settle; stopping anyway")
        }

        lock.lock()
        let handles = slotHandles
        let slotQueues = queues
        let pendingWatchdogs = watchdogs
        let dropped = pending
        slotHandles.removeAll()
        queues.removeAll()
        watchdogs.removeAll()
        pending.removeAll()
        nextSlot = 0
        running = false
        lock.unlock()

        if !dropped.isEmpty {
            NSLog("AsyncTaskExecutor: %d queued task(s) dropped on stop", dropped.count)
        }

        pendingWatchdogs.values.forEach { $0.cancel() }

        // Run each slot's shutdown ON its own serial queue, so it serializes
        // AFTER any in-flight task rather than racing a blocked async_php_run
        // on the slot's semaphore.
        for (handle, queue) in zip(handles, slotQueues) {
            queue.sync {
                _async_php_stop(handle)
            }
        }
        NSLog("AsyncTaskExecutor: stopped (%d slot(s))", handles.count)
    }
}
