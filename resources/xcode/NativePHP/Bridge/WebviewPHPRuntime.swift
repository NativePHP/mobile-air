import Foundation

@_silgen_name("webview_php_start")
private func _webview_php_start(_ bootstrapPath: UnsafePointer<CChar>) -> Int32

@_silgen_name("webview_php_request")
private func _webview_php_request(
    _ handle: Int32,
    _ method: UnsafePointer<CChar>,
    _ uri: UnsafePointer<CChar>,
    _ cookieHeader: UnsafePointer<CChar>,
    _ postData: UnsafePointer<CChar>,
    _ contentType: UnsafePointer<CChar>,
    _ scriptPath: UnsafePointer<CChar>
) -> UnsafeMutablePointer<CChar>?

@_silgen_name("webview_php_stop")
private func _webview_php_stop(_ handle: Int32)

/// A dedicated PHP context for one embedded php-mode webview.
///
/// The persistent runtime's serial queue is parked inside the native
/// screen's event-loop dispatch for the screen's whole lifetime, so it can
/// never answer php:// requests from an embedded webview. Each instance of
/// this class owns a separate thread + TSRM context in the C bridge
/// (`webview_php_start`), boots Laravel on it, serves that webview's
/// requests, and tears the whole context down in `release()` when the
/// webview leaves the view hierarchy.
///
/// All work is serialized on a private queue: requests naturally queue
/// behind the (asynchronous) boot, and `release()` behind in-flight
/// requests — no explicit state machine needed.
///
/// A hot reload is the one event that reaches across those private queues:
/// `PersistentPHPRuntime.shutdown()` calls `php_embed_shutdown()`, which
/// destroys process-wide Zend module state every webview context is built
/// on. See `suspendAllForRuntimeReboot()`.
final class WebviewPHPRuntime {
    /// Queue-confined runtime state, boxed separately from the runtime
    /// object so queued blocks — including the deinit backstop — capture
    /// the box, never `self`. A cleanup block queued from `deinit` that
    /// captured `self` would resurrect a deallocating object ("deallocated
    /// with non-zero retain count") and crash.
    private final class State {
        var handle: Int32 = -1
        var released = false
        /// Context is gone but the runtime is still usable: the next
        /// request boots a fresh one. Set when a boot failed or when the
        /// context was suspended for a persistent-runtime reboot. Unlike
        /// `released`, which is terminal, this is recoverable.
        var needsBoot = false
    }

    private static let unavailableResponse =
        "HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/plain\r\n\r\nWebview PHP runtime unavailable."

    private let queue = DispatchQueue(label: "com.nativephp.webview-php", qos: .userInitiated)
    private let state = State()

    init() {
        Self.register(self)
        let state = self.state
        queue.async { Self.boot(state) }
    }

    /// Dispatch a request on this webview's own PHP context. The completion
    /// receives the raw HTTP response (headers + body), matching the format
    /// `PHPSchemeHandler` already parses.
    func dispatch(request: RequestData, completion: @escaping (String) -> Void) {
        let state = self.state
        queue.async {
            if !state.released, state.needsBoot {
                // No context right now — most likely a hot reload just
                // suspended it. Hold the request until the persistent
                // runtime is back rather than answering 503, so the webview
                // renders the reloaded code instead of an error page. Only
                // requests that actually need a context wait, so a request
                // already queued ahead of the suspend still completes on the
                // old context (and can't deadlock the suspend behind it).
                Self.waitForRuntimeReboot()
                Self.boot(state)
            }

            guard !state.released, state.handle >= 0 else {
                completion(Self.unavailableResponse)
                return
            }

            var uri = request.uri
            if let query = request.query, !query.isEmpty {
                uri += "?" + query
            }

            let appPath = AppUpdateManager.shared.getAppPath()
            let scriptPath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/native.php"
            let cookieHeader = request.headers["Cookie"] ?? ""
            let contentType = request.headers["Content-Type"] ?? request.headers["content-type"] ?? ""

            let start = CFAbsoluteTimeGetCurrent()
            NSLog("%@", "[NativePHP] [WEBVIEW:\(state.handle)] --> \(request.method) \(uri)")

            guard let resultPtr = _webview_php_request(
                state.handle, request.method, uri, cookieHeader,
                request.data ?? "", contentType, scriptPath
            ) else {
                completion("HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nNull response from webview runtime.")
                return
            }

            let response = String(cString: resultPtr)
            free(UnsafeMutableRawPointer(mutating: resultPtr))

            let elapsed = (CFAbsoluteTimeGetCurrent() - start) * 1000
            let statusLine = response.prefix(while: { $0 != "\r" && $0 != "\n" })
            NSLog("%@", "[NativePHP] [WEBVIEW:\(state.handle)] <-- \(statusLine) (\(String(format: "%.1f", elapsed))ms)")

            completion(response)
        }
    }

    /// Stop this webview's PHP thread and free its slot. Queued after any
    /// in-flight request; further dispatches answer 503. Idempotent.
    func release() {
        Self.deregister(self)
        Self.releaseAsync(state: state, queue: queue)
    }

    deinit {
        // Backstop for paths where dismantleUIView never ran. Captures only
        // the state box and queue — never `self`. No deregister call here:
        // the registry is weak (it has to be, or this backstop could never
        // fire) and drops the entry on dealloc.
        Self.releaseAsync(state: state, queue: queue)
    }

    /// Boot a PHP context for this runtime. Runs on the runtime's own queue.
    private static func boot(_ state: State) {
        guard !state.released else { return }

        // Never boot across a persistent-runtime reboot. `webview_php_start`
        // gates on the persistent boot state, which is reset to
        // NEVER_STARTED while the runtime is down, so the boot would fail —
        // and a context created just before the reboot would be torn down
        // again moments later anyway. Defer to the next request.
        if isRebootingRuntime {
            state.needsBoot = true
            return
        }

        let appPath = AppUpdateManager.shared.getAppPath()
        let bootstrap = appPath + "/vendor/nativephp/mobile/bootstrap/ios/persistent.php"
        state.handle = _webview_php_start(bootstrap)
        // Leave the door open on failure — the next request retries rather
        // than leaving the webview permanently dead.
        state.needsBoot = state.handle < 0
        print("[NativePHP] webview runtime: boot \(state.handle >= 0 ? "OK (slot \(state.handle))" : "FAILED (\(state.handle))")")
    }

    private static func releaseAsync(state: State, queue: DispatchQueue) {
        queue.async {
            guard !state.released else { return }
            state.released = true
            state.needsBoot = false
            if state.handle >= 0 {
                _webview_php_stop(state.handle)
                print("[NativePHP] webview runtime: slot \(state.handle) released")
                state.handle = -1
            }
        }
    }

    // MARK: - Live registry & runtime-reboot barrier

    /// Every un-released runtime in the process. Weak on purpose: `deinit`
    /// is the backstop for renderers that never call `release()`, and a
    /// strong registry would keep those objects alive forever. Pointer
    /// personality so membership never sends `hash`/`isEqual:` to an entry
    /// that is mid-dealloc.
    private static let registry = NSHashTable<WebviewPHPRuntime>(
        options: [.weakMemory, .objectPointerPersonality],
        capacity: 0
    )
    private static let registryLock = NSLock()

    /// Guards the reboot window. Broadcast when the window closes.
    private static let rebootCondition = NSCondition()
    private static var rebootInFlight = false

    private static var isRebootingRuntime: Bool {
        rebootCondition.lock()
        defer { rebootCondition.unlock() }
        return rebootInFlight
    }

    private static func register(_ runtime: WebviewPHPRuntime) {
        registryLock.lock()
        registry.add(runtime)
        registryLock.unlock()
    }

    private static func deregister(_ runtime: WebviewPHPRuntime) {
        registryLock.lock()
        registry.remove(runtime)
        registryLock.unlock()
    }

    /// Close the reboot window and tear down every live webview context,
    /// blocking until they are all gone.
    ///
    /// This MUST run before `php_embed_shutdown()` — i.e. before
    /// `PersistentPHPRuntime.shutdown()`, which is what a hot reload does.
    /// That shutdown destroys the shared Zend module state every webview
    /// context is built on, so a context still live across it dereferences
    /// freed memory: the same heap corruption `PHPQueueWorker.stopAndWait()`
    /// exists to prevent, and the reason a hot reload with an embedded php
    /// webview on screen took the whole app down.
    ///
    /// The contexts are suspended, not retired. `resumeAfterRuntimeReboot()`
    /// re-opens the window and each runtime boots a fresh context on its
    /// next request, so a webview that survives the reload (the tree is
    /// preserved across it) keeps serving — on the reloaded code.
    static func suspendAllForRuntimeReboot() {
        rebootCondition.lock()
        rebootInFlight = true
        rebootCondition.unlock()

        registryLock.lock()
        let runtimes = registry.allObjects
        registryLock.unlock()

        guard !runtimes.isEmpty else { return }

        NSLog("%@", "[NativePHP] webview runtime: suspending \(runtimes.count) context(s) for runtime reboot")

        let group = DispatchGroup()
        for runtime in runtimes {
            group.enter()
            runtime.suspendForReboot { group.leave() }
        }

        // Bounded — a wedged context must not deadlock the reload. Timing
        // out means we are about to reboot with a live context attached, so
        // say so loudly: that is the crash this whole path prevents.
        if group.wait(timeout: .now() + 5) == .timedOut {
            NSLog("%@", "[NativePHP] ⚠️ webview runtime: suspend timed out — rebooting with a live context still attached")
        }
    }

    /// Re-open the reboot window once the persistent runtime is back up.
    /// Requests parked in `dispatch` wake and boot themselves a fresh
    /// context. No-op when no reboot was in flight.
    static func resumeAfterRuntimeReboot() {
        rebootCondition.lock()
        defer { rebootCondition.unlock() }
        guard rebootInFlight else { return }
        rebootInFlight = false
        rebootCondition.broadcast()
        NSLog("%@", "[NativePHP] webview runtime: reboot window closed — contexts re-boot on next request")
    }

    /// Park the calling lane while a persistent-runtime reboot is in flight.
    private static func waitForRuntimeReboot() {
        rebootCondition.lock()
        defer { rebootCondition.unlock() }

        // Bounded so a reload that never completes degrades to a 503 rather
        // than a webview that hangs forever.
        let deadline = Date().addingTimeInterval(15)
        while rebootInFlight {
            if !rebootCondition.wait(until: deadline) { return }
        }
    }

    /// Stop this runtime's context while keeping the runtime usable — the
    /// next request boots a fresh one. Queued behind any in-flight request,
    /// so a response already being generated still completes on the old
    /// context before it goes away.
    private func suspendForReboot(completion: @escaping () -> Void) {
        let state = self.state
        queue.async {
            defer { completion() }
            guard !state.released else { return }
            if state.handle >= 0 {
                _webview_php_stop(state.handle)
                print("[NativePHP] webview runtime: slot \(state.handle) suspended for runtime reboot")
                state.handle = -1
            }
            state.needsBoot = true
        }
    }
}
