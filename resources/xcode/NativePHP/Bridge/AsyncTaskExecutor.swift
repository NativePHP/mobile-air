import Foundation

// C functions from PHP.c — async task lane (pool of TSRM contexts)
@_silgen_name("async_php_boot")
private func _async_php_boot(_ bootstrapPath: UnsafePointer<CChar>?) -> Int32

@_silgen_name("async_php_run")
private func _async_php_run(_ handle: Int32, _ taskId: UnsafePointer<CChar>?) -> UnsafePointer<CChar>?

@_silgen_name("async_php_stop")
private func _async_php_stop(_ handle: Int32)

@_silgen_name("async_php_on_lane_thread")
private func _async_php_on_lane_thread() -> Int32

/// Runs dispatched async tasks immediately on a small pool of background PHP
/// contexts — the lane behind `AsyncTask::dispatch()`.
///
/// Distinct from `PHPQueueWorker`: it never polls `queue:work` or touches the
/// standard queue, and it is concurrent (one in-flight task per slot). Each slot
/// is a C worker thread with its own booted TSRM context; here each slot handle
/// is pinned to a dedicated serial `DispatchQueue`, so a slot is only ever driven
/// by one thread and its context stays thread-local.
///
/// A task goes to an IDLE slot, and waits in one shared FIFO only when every slot
/// is busy — the slot that frees up first takes it. Never to a slot by turn: a
/// slot's queue is serial, so a task handed to a busy slot waits out whatever is
/// running there even while the other slots sit idle. Android's executor gets the
/// same behaviour from its threads sharing one blocking queue.
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

    private let lock = NSLock()

    /// Indices of booted slots with no task running or queued on them.
    private var idleSlots: [Int] = []

    /// Tasks dispatched while every slot was busy, oldest first. A slot takes the
    /// next one as it finishes, so a long task delays only itself.
    private var waiting: [String] = []

    /// All mutable state below is guarded by `lock`.
    private var running = false

    /// Incremented on every `start()` and `stop()` so a slot boot that outlives
    /// a stop timeout can never attach itself to a later generation.
    private var generation: UInt64 = 0

    /// Resolved bootstrap path captured in `start()` for on-demand slot boots.
    private var bootstrapPath = ""

    /// Number of slots currently queued or running `_async_php_boot`.
    /// `slotHandles.count + bootingSlots` never exceeds `poolSize`.
    private var bootingSlots = 0

    /// Serializes `_async_php_boot()` calls so two lazy boots never race inside
    /// `setup_persistent_sapi()` / `php_embed_module.startup()`.
    private let bootQueue = DispatchQueue(label: "nativephp-async-boot", qos: .utility)
    private let bootFinished = DispatchGroup()

    /// Timeout work items for dispatched tasks, keyed by task id.
    private var watchdogs: [String: DispatchWorkItem] = [:]
    private let watchdogQueue = DispatchQueue(label: "nativephp-async-watchdog")

    private init() {}

    var isRunning: Bool {
        lock.lock()
        defer { lock.unlock() }
        return running
    }

    /// Whether the calling thread is the thread of an async slot, where a
    /// dispatched task runs. A bridge call that PHP makes runs on the thread
    /// of the PHP that made it, so this tells a call from a task apart
    /// from the same call made by JavaScript in a web view.
    static var onLaneThread: Bool {
        _async_php_on_lane_thread() != 0
    }

    /// Arm the pool to accept dispatches. Slots boot their PHP context lazily
    /// on demand (up to `poolSize`), mirroring Android's `AsyncTaskExecutor`
    /// so apps that never dispatch async tasks pay zero extra Laravel boots.
    func start() {
        lock.lock()
        guard !running else {
            lock.unlock()
            NSLog("AsyncTaskExecutor: already running")
            return
        }
        bootstrapPath = AppUpdateManager.shared.getAppPath() + "/vendor/nativephp/mobile/bootstrap/ios/persistent.php"
        generation &+= 1
        running = true
        lock.unlock()

        NSLog("AsyncTaskExecutor: ready (lazy pool, max %d slots)", poolSize)
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

    /// Hand a task to an idle slot, or queue it and boot a new slot on demand
    /// if the pool has not yet reached `poolSize`.
    private func submit(taskId: String) -> Bool {
        lock.lock()

        guard running else {
            lock.unlock()
            NSLog("AsyncTaskExecutor: dispatch(%@) dropped — executor not running", taskId)
            return false
        }

        guard let index = idleSlots.popLast() else {
            // Never silently over-subscribe: surface when tasks are queueing
            // behind a saturated pool, as Android does.
            let ahead = waiting.count
            waiting.append(taskId)
            let shouldBoot = (slotHandles.count + bootingSlots) < poolSize
            let path = bootstrapPath
            let bootGeneration = generation
            let slotCount = slotHandles.count
            if shouldBoot {
                bootingSlots += 1
                bootFinished.enter()
            }
            lock.unlock()

            if shouldBoot {
                bootSlot(bootstrapPath: path, bootGeneration: bootGeneration)
            } else {
                NSLog("AsyncTaskExecutor: task %@ queued behind %d others (pool=%d)", taskId, ahead, slotCount)
            }
            return true
        }

        let handle = slotHandles[index]
        let queue = queues[index]
        lock.unlock()

        run(taskId: taskId, slot: index, handle: handle, on: queue)

        return true
    }

    /// Boot one slot on the serial boot queue, then take the oldest waiting
    /// task or park the new slot in `idleSlots`.
    private func bootSlot(bootstrapPath: String, bootGeneration: UInt64) {
        bootQueue.async {
            defer { self.bootFinished.leave() }

            self.lock.lock()
            let activeBeforeBoot = self.running && self.generation == bootGeneration
            if !activeBeforeBoot {
                self.bootingSlots -= 1
                self.lock.unlock()
                return
            }
            self.lock.unlock()

            let handle = bootstrapPath.withCString { _async_php_boot($0) }

            self.lock.lock()
            self.bootingSlots -= 1

            if handle < 0 {
                let stranded = self.slotHandles.isEmpty && self.bootingSlots == 0
                let droppedCount = stranded ? self.waiting.count : 0
                if stranded {
                    self.waiting.removeAll()
                }
                self.lock.unlock()
                NSLog("AsyncTaskExecutor: lazy slot boot FAILED (%d)", handle)
                if droppedCount > 0 {
                    NSLog("AsyncTaskExecutor: %d task(s) dropped — no booted slots", droppedCount)
                }
                return
            }

            guard self.running && self.generation == bootGeneration else {
                // `stop()` arrived while `_async_php_boot()` was running: tear
                // this handle down immediately so the C slot cannot leak.
                self.lock.unlock()
                _async_php_stop(handle)
                return
            }

            let index = self.slotHandles.count
            let queue = DispatchQueue(label: "nativephp-async-\(handle)", qos: .utility)
            self.slotHandles.append(handle)
            self.queues.append(queue)

            guard !self.waiting.isEmpty else {
                self.idleSlots.append(index)
                self.lock.unlock()
                return
            }

            let next = self.waiting.removeFirst()
            self.lock.unlock()

            self.run(taskId: next, slot: index, handle: handle, on: queue)
        }
    }

    /// Run one task on its slot, then give the slot the next waiting task — or
    /// mark it idle when there is none.
    private func run(taskId: String, slot index: Int, handle: Int32, on queue: DispatchQueue) {
        queue.async {
            if let resultPtr = _async_php_run(handle, taskId) {
                free(UnsafeMutableRawPointer(mutating: resultPtr))
            }
            // The run is over either way — a completion (or a failure) has
            // already been posted from PHP.
            self.disarmWatchdog(taskId: taskId)

            self.lock.lock()

            // Stopping: the slot arrays are about to be cleared, so this index
            // means nothing any more, and nothing new may start.
            guard self.running else {
                self.lock.unlock()
                return
            }

            guard !self.waiting.isEmpty else {
                self.idleSlots.append(index)
                self.lock.unlock()
                return
            }

            let next = self.waiting.removeFirst()
            self.lock.unlock()

            // Lands on this slot's own serial queue behind the block now
            // finishing, so the slot is still only ever driven by one thread.
            self.run(taskId: next, slot: index, handle: handle, on: queue)
        }
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

    /// Stop the pool and shut down every booted slot's context. MUST be called
    /// before a persistent-runtime reboot (php_embed_shutdown destroys shared
    /// Zend module state the slots' live TSRM contexts reference).
    func stop() {
        lock.lock()
        let wasBooting = bootingSlots > 0
        let hasSlots = !slotHandles.isEmpty
        guard running || wasBooting || hasSlots else {
            lock.unlock()
            return
        }
        running = false
        generation &+= 1
        lock.unlock()

        // Wait for any in-flight lazy slot boot so it sees `!running` and tears
        // down its own C handle before php_embed_shutdown() runs.
        if wasBooting, bootFinished.wait(timeout: .now() + bootWaitTimeout) == .timedOut {
            NSLog("AsyncTaskExecutor: timed out waiting for boot to settle; stopping anyway")
        }

        lock.lock()
        let handles = slotHandles
        let slotQueues = queues
        let pendingWatchdogs = watchdogs
        let dropped = waiting
        slotHandles.removeAll()
        queues.removeAll()
        watchdogs.removeAll()
        waiting.removeAll()
        idleSlots.removeAll()
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
