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
/// Slots boot on demand, one at a time on a serial boot queue. None exist at
/// launch, since each is a full Laravel boot with its own heap. The first
/// dispatch boots one, and another boots only while more tasks are waiting than
/// slots are booting, up to `poolSize`. A slot with no work for `idleTimeout`
/// seconds shuts its context down and frees it. Both come from bundle_meta.json
/// (`config('nativephp.async')`); the C lane's `ASYNC_PHP_MAX_SLOTS` stays the
/// hard ceiling.
///
/// A task goes to an IDLE slot, and waits in one shared FIFO only when every slot
/// is busy (or still booting) — the slot that frees up first takes it. Never to a
/// slot by turn: a slot's queue is serial, so a task handed to a busy slot waits
/// out whatever is running there even while the other slots sit idle. Android's
/// executor gets the same behaviour from its threads sharing one queue.
///
/// The task payload and result travel via the PHP temp-file transport plus the
/// `AsyncTask.Complete` bridge function (which wakes the UI runloop).
///
/// Android twin: `AsyncTaskExecutor.kt`.
final class AsyncTaskExecutor {
    static let shared = AsyncTaskExecutor()

    /// `ASYNC_PHP_MAX_SLOTS` in PHP.c. No config can go past it.
    private static let hardMaxSlots = 4
    private static let defaultMaxSlots = 2
    private static let defaultIdleTimeout = 60

    /// One booted C slot and the serial queue that drives it.
    private final class Slot {
        let handle: Int32
        let queue: DispatchQueue
        /// Bumped (under `lock`) each time the slot takes a task, so an idle
        /// timer armed before that can tell it is stale.
        var useCount = 0
        /// Set once `_async_php_stop` has run. Only touched on `queue`, so an
        /// idle shutdown and `stop()` can't both stop the same handle.
        var isShutDown = false

        init(handle: Int32) {
            self.handle = handle
            self.queue = DispatchQueue(label: "nativephp-async-\(handle)", qos: .utility)
        }

        /// Shut the context down. Call on `queue`.
        func shutDown() {
            guard !isShutDown else { return }
            isShutDown = true
            _async_php_stop(handle)
        }
    }

    /// Most slots the pool will boot: `config('nativephp.async.max_slots')`,
    /// capped at `hardMaxSlots`.
    private let poolSize: Int

    /// Seconds an idle slot lives before it shuts down. 0 keeps slots for the
    /// life of the pool.
    private let idleTimeout: Int

    /// How long `stop()` waits for an in-flight boot before tearing down anyway.
    private let bootWaitTimeout: TimeInterval = 30

    private let lock = NSLock()

    // All mutable state below is guarded by `lock`.

    /// Whether dispatches are taken. Set by `start()`, cleared by `stop()`.
    private var running = false

    /// Incremented on every `start()` and `stop()` so a slot boot (or an idle
    /// timer) that outlives a stop can never attach itself to a later generation.
    private var generation: UInt64 = 0

    /// Resolved bootstrap path captured in `start()` for on-demand slot boots.
    private var bootstrapPath = ""

    /// Booted slots, busy, idle or shutting down after an idle spell. A slot
    /// leaves this list only once its context is stopped, so the pool never
    /// holds more C slots than `poolSize`.
    private var slots: [Slot] = []

    /// Booted slots with no task running or queued on them. Only these can be
    /// retired by their idle timer.
    private var idleSlots: [Slot] = []

    /// Number of slots currently queued or running `_async_php_boot`.
    /// `slots.count + bootingSlots` never exceeds `poolSize`.
    private var bootingSlots = 0

    /// Tasks waiting for a slot, oldest first. A slot takes the next one as it
    /// boots or finishes, so a long task delays only itself.
    private var waiting: [String] = []

    /// When each task was dispatched, for the timing log line.
    private var dispatchedAt: [String: DispatchTime] = [:]

    /// Serializes `_async_php_boot()` calls so two lazy boots never race inside
    /// `setup_persistent_sapi()` / `php_embed_module.startup()`.
    private let bootQueue = DispatchQueue(label: "nativephp-async-boot", qos: .utility)
    private let bootFinished = DispatchGroup()

    /// Timeout work items for dispatched tasks, keyed by task id.
    private var watchdogs: [String: DispatchWorkItem] = [:]
    private let watchdogQueue = DispatchQueue(label: "nativephp-async-watchdog")

    private init() {
        let meta = AsyncTaskExecutor.readBundleMeta()
        let configured = (meta?["async_max_slots"] as? NSNumber)?.intValue ?? AsyncTaskExecutor.defaultMaxSlots
        poolSize = min(AsyncTaskExecutor.hardMaxSlots, max(1, configured))
        idleTimeout = max(0, (meta?["async_idle_timeout"] as? NSNumber)?.intValue ?? AsyncTaskExecutor.defaultIdleTimeout)
    }

    private static func readBundleMeta() -> [String: Any]? {
        guard let path = Bundle.main.path(forResource: "bundle_meta", ofType: "json"),
              let data = FileManager.default.contents(atPath: path),
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            return nil
        }
        return json
    }

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

    /// Arm the pool to accept dispatches. Boots nothing: slots boot their PHP
    /// context lazily on demand (up to `poolSize`), mirroring Android's
    /// `AsyncTaskExecutor`, so apps that never dispatch pay no extra Laravel boot.
    func start() {
        lock.lock()
        guard !running else {
            lock.unlock()
            NativeLog.debug("AsyncTaskExecutor: already running")
            return
        }
        bootstrapPath = AppUpdateManager.shared.getAppPath() + "/vendor/nativephp/mobile/bootstrap/ios/persistent.php"
        generation &+= 1
        running = true
        lock.unlock()

        NativeLog.debug(String(format: "AsyncTaskExecutor: ready (lazy pool, max %d slots, idle timeout %ds)", poolSize, idleTimeout))
    }

    /// Enqueue a dispatched task id on a free slot. Returns immediately.
    ///
    /// A non-zero `timeout` arms a watchdog: if the deadline passes with nothing
    /// delivered, `timeoutEvent`/`timeoutPayloadJson` — built by PHP at dispatch
    /// time, so this stays a dumb courier — are posted into the UI runloop and
    /// `->failed()` fires instead of the UI waiting forever. The deadline covers
    /// queue and boot time as well as run time.
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
            NativeLog.error(String(format: "AsyncTaskExecutor: dispatch(%@) dropped — executor not running", taskId))
            return false
        }

        dispatchedAt[taskId] = .now()

        if let slot = idleSlots.popLast() {
            slot.useCount += 1
            lock.unlock()
            run(taskId: taskId, on: slot)
            return true
        }

        // Never silently over-subscribe: surface when tasks are queueing
        // behind a saturated pool, as Android does.
        let ahead = waiting.count
        waiting.append(taskId)
        let boot = claimBootLocked()
        let slotCount = slots.count
        let booting = bootingSlots
        lock.unlock()

        if let boot {
            bootSlot(bootstrapPath: boot.path, bootGeneration: boot.generation)
        } else {
            NativeLog.debug(String(format: "AsyncTaskExecutor: task %@ queued behind %d others (pool=%d, booting=%d, max=%d)",
                                   taskId, ahead, slotCount, booting, poolSize))
        }

        return true
    }

    /// Claim a lazy boot when more tasks are waiting than slots are booting and
    /// the pool has room for one more. A claimed boot is counted in
    /// `bootingSlots` and entered in `bootFinished` before `lock` is released,
    /// so a `stop()` that lands next always waits for it. Call with `lock` held,
    /// then pass the result to `bootSlot` after unlocking.
    private func claimBootLocked() -> (path: String, generation: UInt64)? {
        guard running,
              waiting.count > bootingSlots,
              slots.count + bootingSlots < poolSize else {
            return nil
        }
        bootingSlots += 1
        bootFinished.enter()
        return (bootstrapPath, generation)
    }

    /// Boot one slot on the serial boot queue, then take the oldest waiting
    /// task or park the new slot in `idleSlots`. Balances the `bootFinished`
    /// entry and `bootingSlots` count taken by `claimBootLocked`.
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

            let started = DispatchTime.now()
            let handle = bootstrapPath.withCString { _async_php_boot($0) }
            let ms = Double(DispatchTime.now().uptimeNanoseconds - started.uptimeNanoseconds) / 1_000_000

            self.lock.lock()
            self.bootingSlots -= 1

            if handle < 0 {
                // With nothing else booted or booting, the waiting tasks have
                // nowhere to run. Fail them now rather than at their deadline.
                var stranded: [String] = []
                if self.slots.isEmpty && self.bootingSlots == 0 {
                    stranded = self.waiting
                    self.waiting.removeAll()
                }
                self.lock.unlock()
                NativeLog.error(String(format: "AsyncTaskExecutor: lazy slot boot FAILED (%d)", handle))
                stranded.forEach { self.failNow(taskId: $0) }
                return
            }

            guard self.running && self.generation == bootGeneration else {
                // `stop()` arrived while `_async_php_boot()` was running: tear
                // this handle down immediately so the C slot cannot leak.
                self.lock.unlock()
                _async_php_stop(handle)
                return
            }

            let slot = Slot(handle: handle)
            self.slots.append(slot)
            let count = self.slots.count
            self.lock.unlock()

            NativeLog.perf(String(format: "AsyncTaskExecutor: slot booted (%d/%d slots) in %.0f ms", count, self.poolSize, ms))

            self.next(on: slot)
        }
    }

    /// Run one task on its slot, then give the slot the next waiting task — or
    /// mark it idle when there is none.
    private func run(taskId: String, on slot: Slot) {
        slot.queue.async {
            if let resultPtr = _async_php_run(slot.handle, taskId) {
                free(UnsafeMutableRawPointer(mutating: resultPtr))
            }
            // The run is over either way — a completion (or a failure) has
            // already been posted from PHP.
            self.disarmWatchdog(taskId: taskId)

            self.lock.lock()
            let since = self.dispatchedAt.removeValue(forKey: taskId)
            self.lock.unlock()
            if let since {
                let ms = Double(DispatchTime.now().uptimeNanoseconds - since.uptimeNanoseconds) / 1_000_000
                NativeLog.debug(String(format: "AsyncTaskExecutor: task %@ done %.0f ms after dispatch", taskId, ms))
            }

            self.next(on: slot)
        }
    }

    /// Give a free slot the oldest waiting task, or put it on the idle list
    /// and arm its idle timer.
    private func next(on slot: Slot) {
        lock.lock()

        // Stopping: the slot list is about to be cleared and nothing new may
        // start; stop() shuts the slot down.
        guard running, slots.contains(where: { $0 === slot }) else {
            lock.unlock()
            return
        }

        guard waiting.isEmpty else {
            let taskId = waiting.removeFirst()
            slot.useCount += 1
            lock.unlock()

            // Lands on this slot's own serial queue behind the block now
            // finishing, so the slot is still only ever driven by one thread.
            run(taskId: taskId, on: slot)
            return
        }

        idleSlots.append(slot)
        let use = slot.useCount
        let armedGeneration = generation
        lock.unlock()

        armIdleTimer(slot: slot, use: use, generation: armedGeneration)
    }

    /// Shut a slot down once it has sat idle for `idleTimeout` seconds.
    ///
    /// Only a slot still in `idleSlots`, in the same generation, that has taken
    /// no task since the timer was armed is retired. Taking it out of
    /// `idleSlots` under `lock` means no task can be handed to it afterwards,
    /// and a booting slot is never in `idleSlots`, so a slot is never stopped
    /// while it runs a task or boots. The stop itself runs on the slot's own
    /// serial queue, and the slot stays counted in `slots` until it is done, so
    /// `stop()` waits for it and the pool can't overshoot `poolSize`.
    private func armIdleTimer(slot: Slot, use: Int, generation armedGeneration: UInt64) {
        guard idleTimeout > 0 else { return }

        watchdogQueue.asyncAfter(deadline: .now() + .seconds(idleTimeout)) {
            self.lock.lock()
            guard self.running,
                  self.generation == armedGeneration,
                  slot.useCount == use,
                  let index = self.idleSlots.firstIndex(where: { $0 === slot }) else {
                self.lock.unlock()
                return
            }
            self.idleSlots.remove(at: index)
            self.lock.unlock()

            slot.queue.async {
                slot.shutDown()

                self.lock.lock()
                self.slots.removeAll { $0 === slot }
                let left = self.slots.count
                // A task that came in while this slot was on its way out may
                // have found the pool full. There is room now.
                let boot = self.claimBootLocked()
                self.lock.unlock()

                NativeLog.debug(String(format: "AsyncTaskExecutor: slot shut down after %ds idle (%d/%d slots)", self.idleTimeout, left, self.poolSize))

                if let boot {
                    self.bootSlot(bootstrapPath: boot.path, bootGeneration: boot.generation)
                }
            }
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

            NativeLog.error(String(format: "AsyncTaskExecutor: task %@ timed out; reporting failure to the UI", taskId))
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

    /// Report a task that can never run as failed now, through its watchdog,
    /// which holds the failure PHP built for it. With no watchdog there is no
    /// failure to post, so it is only logged.
    private func failNow(taskId: String) {
        lock.lock()
        let item = watchdogs[taskId]
        dispatchedAt.removeValue(forKey: taskId)
        lock.unlock()

        guard let item else {
            NativeLog.error(String(format: "AsyncTaskExecutor: task %@ dropped — no slot could boot", taskId))
            return
        }

        NativeLog.error(String(format: "AsyncTaskExecutor: task %@ failed — no slot could boot", taskId))
        watchdogQueue.async { item.perform() }
    }

    /// Stop the pool and shut down every booted slot's context. MUST be called
    /// before a persistent-runtime reboot (php_embed_shutdown destroys shared
    /// Zend module state the slots' live TSRM contexts reference).
    func stop() {
        lock.lock()
        let wasBooting = bootingSlots > 0
        let hasSlots = !slots.isEmpty
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
            NativeLog.error("AsyncTaskExecutor: timed out waiting for boot to settle; stopping anyway")
        }

        lock.lock()
        let live = slots
        let pendingWatchdogs = watchdogs
        let dropped = waiting
        slots.removeAll()
        idleSlots.removeAll()
        watchdogs.removeAll()
        waiting.removeAll()
        dispatchedAt.removeAll()
        running = false
        lock.unlock()

        if !dropped.isEmpty {
            NativeLog.error(String(format: "AsyncTaskExecutor: %d queued task(s) dropped on stop", dropped.count))
        }

        pendingWatchdogs.values.forEach { $0.cancel() }

        // Run each slot's shutdown ON its own serial queue, so it serializes
        // AFTER any in-flight task (or an idle shutdown already under way)
        // rather than racing a blocked async_php_run on the slot's semaphore.
        for slot in live {
            slot.queue.sync {
                slot.shutDown()
            }
        }
        NativeLog.debug(String(format: "AsyncTaskExecutor: stopped (%d slot(s))", live.count))
    }
}
