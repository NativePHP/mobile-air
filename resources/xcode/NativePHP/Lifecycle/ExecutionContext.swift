import Foundation
import UIKit

/// Where this process is running, and why it started.
///
/// iOS cold-launches an app straight into the **background** for
/// BGTaskScheduler work, silent pushes and background fetch — routinely while
/// the device is locked and Data Protection has sealed the keychain and
/// protected files. Such a launch has to stay headless: the start route must
/// not be dispatched, no WKWebView may be created, no component may mount, and
/// no polling may start. Only the work the system actually woke us for should
/// run.
///
/// This is the single source of truth for that decision. The boot path gates
/// its interactive phase on `whenInteractive(_:)`; PHP reads the same state
/// through the `System.GetExecutionContext` bridge function, which backs
/// `Native\Mobile\Facades\ExecutionContext`.
///
/// Reads are safe from any thread: UIKit state is *mirrored* under a lock from
/// main-thread lifecycle notifications rather than read live. Bridge functions
/// run on the PHP worker thread, and hopping to main from there deadlocks
/// whenever main is itself waiting on PHP.
///
/// Android twin: `bridge/functions/SystemFunctions.kt` (GetExecutionContext).
final class ExecutionContext: @unchecked Sendable {
    static let shared = ExecutionContext()

    /// Why this process started.
    enum Launch: String {
        /// The user (or a deep link / notification tap) opened the app — UI
        /// is expected.
        case foreground
        /// The system started us headlessly for background work. No UI is
        /// expected until the app is actually brought to the foreground.
        case background
    }

    /// Mirror of `UIApplication.State`.
    enum RunState: String {
        case active, inactive, background

        init(_ state: UIApplication.State) {
            switch state {
            case .active: self = .active
            case .inactive: self = .inactive
            case .background: self = .background
            @unknown default: self = .background
            }
        }
    }

    private let lock = NSLock()

    private var _launch: Launch = .foreground
    private var _state: RunState = .inactive
    private var _hasBecomeActive = false
    private var _protectedDataAvailable = true
    private var _interactiveBootStarted = false

    /// Work parked until the app is interactive. Drained exactly once, in
    /// registration order, on the first `didBecomeActive`.
    private var pendingInteractive: [() -> Void] = []

    private var started = false

    private init() {}

    // MARK: - Reads (safe from any thread)

    var launch: Launch { lock.withLock { _launch } }

    /// "active" | "inactive" | "background".
    var state: RunState { lock.withLock { _state } }

    /// Foreground covers `.active` **and** `.inactive` — the latter is a
    /// transient on-screen state (app switcher, incoming call banner, system
    /// permission alert), not a background launch.
    var isForeground: Bool { lock.withLock { _state != .background } }

    var isBackground: Bool { !isForeground }

    var isActive: Bool { lock.withLock { _state == .active } }

    /// True when iOS cold-launched this process into the background.
    var launchedInBackground: Bool { launch == .background }

    /// True once the app has been active at least once in this process. A
    /// headless background launch never sets this.
    var hasBecomeActive: Bool { lock.withLock { _hasBecomeActive } }

    /// A background launch that has never been brought on screen — the state
    /// in which scheduled Artisan / queue work should run, and in which
    /// nothing may touch the UI.
    var isHeadless: Bool {
        lock.withLock { _launch == .background && !_hasBecomeActive }
    }

    /// False while the device is locked with Data Protection engaged:
    /// keychain items and protected files are unreadable until first unlock.
    var isProtectedDataAvailable: Bool { lock.withLock { _protectedDataAvailable } }

    /// True once the interactive boot (ContentView + `NATIVEPHP_START_URL`)
    /// has been kicked off.
    var interactiveBootStarted: Bool { lock.withLock { _interactiveBootStarted } }

    /// The payload `System.GetExecutionContext` returns to PHP. Keys are
    /// snake_case to match the rest of the bridge vocabulary.
    func snapshot() -> [String: Any] {
        lock.withLock {
            [
                "launch": _launch.rawValue,
                "state": _state.rawValue,
                "foreground": _state != .background,
                "active": _state == .active,
                "has_become_active": _hasBecomeActive,
                "headless": _launch == .background && !_hasBecomeActive,
                "protected_data_available": _protectedDataAvailable,
                "interactive_boot_started": _interactiveBootStarted,
            ]
        }
    }

    // MARK: - Lifecycle wiring

    /// Capture how the process started and begin mirroring lifecycle changes.
    /// Called from `AppDelegate.application(_:didFinishLaunchingWithOptions:)`,
    /// which runs before any scene connects — so the launch reason is recorded
    /// before anything can boot off it. Idempotent.
    @MainActor
    func start(launchState: UIApplication.State) {
        let alreadyStarted: Bool = lock.withLock {
            defer { started = true }
            return started
        }
        guard !alreadyStarted else { return }

        let run = RunState(launchState)
        lock.withLock {
            _launch = run == .background ? .background : .foreground
            _state = run
            _hasBecomeActive = run == .active
            _protectedDataAvailable = UIApplication.shared.isProtectedDataAvailable
        }

        NSLog("[ExecutionContext] launch=\(launch.rawValue) state=\(run.rawValue) protectedData=\(isProtectedDataAvailable)")

        observe(UIApplication.didBecomeActiveNotification) { $0.didBecomeActive() }
        observe(UIApplication.willResignActiveNotification) { $0.set(state: .inactive) }
        observe(UIApplication.willEnterForegroundNotification) { $0.set(state: .inactive) }
        observe(UIApplication.didEnterBackgroundNotification) { $0.set(state: .background) }
        observe(UIApplication.protectedDataDidBecomeAvailableNotification) {
            $0.set(protectedDataAvailable: true)
        }
        observe(UIApplication.protectedDataWillBecomeUnavailableNotification) {
            $0.set(protectedDataAvailable: false)
        }
    }

    private func observe(_ name: Notification.Name, _ handler: @escaping (ExecutionContext) -> Void) {
        NotificationCenter.default.addObserver(
            forName: name,
            object: nil,
            queue: .main
        ) { _ in handler(ExecutionContext.shared) }
    }

    private func set(state: RunState) {
        lock.withLock { _state = state }
    }

    private func set(protectedDataAvailable: Bool) {
        lock.withLock { _protectedDataAvailable = protectedDataAvailable }
        NSLog("[ExecutionContext] protectedDataAvailable=\(protectedDataAvailable)")
    }

    private func didBecomeActive() {
        let parked: [() -> Void] = lock.withLock {
            _state = .active
            _hasBecomeActive = true
            defer { pendingInteractive.removeAll() }
            return pendingInteractive
        }

        guard !parked.isEmpty else { return }

        NSLog("[ExecutionContext] became active — running \(parked.count) deferred interactive task(s)")

        // One block, so the parked closures keep their registration order —
        // and off main, because this fires from a lifecycle notification and
        // the work behind it reads files before it touches any UI state.
        DispatchQueue.global(qos: .userInitiated).async {
            parked.forEach { $0() }
        }
    }

    // MARK: - Gating

    /// Run `block` once the app is on screen — either because the work
    /// touches the UI, or because it costs resources a headless background
    /// wake never asked for (the queue worker's second PHP context).
    ///
    /// Runs immediately when the app is already on screen (a foreground launch
    /// that hasn't been backgrounded, or any launch that has reached
    /// `.active`). A headless background launch parks the block until the app
    /// is actually opened — so a BGTaskScheduler wake never boots the
    /// interactive route.
    ///
    /// Blocks run in registration order, always on a background queue; each is
    /// responsible for hopping to main for anything UIKit or `@MainActor`.
    func whenInteractive(_ block: @escaping () -> Void) {
        let runNow: Bool = lock.withLock {
            guard _state != .background else { return false }
            // A foreground launch is on its way to `.active` by definition;
            // waiting for the notification would stall the normal boot.
            return _launch == .foreground || _hasBecomeActive
        }

        guard runNow else {
            lock.withLock { pendingInteractive.append(block) }
            NSLog("[ExecutionContext] deferring interactive work until the app becomes active")
            return
        }

        DispatchQueue.global(qos: .userInitiated).async(execute: block)
    }

    /// Claim the one-shot interactive boot. Returns false if it already ran,
    /// so a deferred boot can't double-fire against a foreground re-entry.
    func claimInteractiveBoot() -> Bool {
        lock.withLock {
            guard !_interactiveBootStarted else { return false }
            _interactiveBootStarted = true
            return true
        }
    }
}
