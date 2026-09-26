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

    /// Provisional until `classifyLaunch()` settles it one runloop turn in.
    /// Starts pessimistic: assume no UI until we have seen one.
    private var _launch: Launch = .background
    private var _launchResolved = false
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

    /// True when iOS started this process for background work rather than
    /// because someone opened the app. Provisionally true for the first
    /// runloop turn of any launch, until `classifyLaunch()` settles it.
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

    /// Begin mirroring lifecycle changes. Called from
    /// `AppDelegate.application(_:didFinishLaunchingWithOptions:)`.
    ///
    /// `launchState` is only the state at that instant, which is `.background`
    /// on every launch because no scene has connected yet. Why the process
    /// started is settled a runloop turn later by `classifyLaunch()`.
    /// Idempotent.
    @MainActor
    func start(launchState: UIApplication.State) {
        let run = RunState(launchState)
        let protectedData = UIApplication.shared.isProtectedDataAvailable

        // Claim and record together, so nothing can observe the defaults
        // (`foreground` / `inactive`) after `start()` has begun. A reader that
        // caught that window on a background launch would judge itself
        // interactive and boot the UI — the exact thing this type exists to
        // prevent.
        let alreadyStarted: Bool = lock.withLock {
            guard !started else { return true }
            started = true
            _state = run
            _hasBecomeActive = run == .active
            _protectedDataAvailable = protectedData
            return false
        }

        guard !alreadyStarted else { return }

        NSLog("[ExecutionContext] start: state=\(run.rawValue) protectedData=\(isProtectedDataAvailable)")

        classifyLaunch()

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

    /// Decide why the process started — on the next main-runloop turn, never
    /// synchronously.
    ///
    /// Inside `didFinishLaunchingWithOptions` no scene has connected yet, so
    /// `UIApplication.applicationState` reads `.background` for EVERY launch,
    /// ordinary foreground ones included. Measured on a simulator: `.background`
    /// with zero scenes at t=0, then `.inactive` with one connected foreground
    /// scene ~160ms later, `.active` at ~500ms. Classifying at t=0 therefore
    /// marks every single launch headless, which is the opposite of useful.
    ///
    /// UIKit connects the scene before it returns to the runloop, so a block
    /// queued here runs after that has happened — it is an ordering guarantee,
    /// not a timing race. A real background launch connects no scene at all, so
    /// it still reads `.background` here and stays classified that way.
    private func classifyLaunch() {
        DispatchQueue.main.async { [self] in
            let app = UIApplication.shared
            let live = RunState(app.applicationState)
            let hasForegroundScene = app.connectedScenes.contains {
                $0.activationState == .foregroundActive
                    || $0.activationState == .foregroundInactive
            }
            let foreground = live != .background || hasForegroundScene

            lock.withLock {
                // Refresh the mirrored state too: no notification fires for the
                // initial background → inactive step, so without this the first
                // half-second of every launch reports `background`.
                _state = live

                guard !_launchResolved else { return }
                _launchResolved = true
                _launch = foreground ? .foreground : .background
            }

            NSLog("[ExecutionContext] launch=\(launch.rawValue) state=\(state.rawValue) scenes=\(app.connectedScenes.count)")
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
            // Becoming active proves a foreground launch, if classifyLaunch
            // has not already said otherwise (a background launch the user
            // later opened stays classified `.background`).
            if !_launchResolved {
                _launchResolved = true
                _launch = .foreground
            }
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
    /// wake never asked for (the queue worker and async pool's PHP contexts).
    ///
    /// Runs immediately when the app is already on screen (a foreground launch
    /// that hasn't been backgrounded, or any launch that has reached
    /// `.active`). A headless background launch parks the block until the app
    /// is actually opened — so a BGTaskScheduler wake never boots the
    /// interactive route.
    ///
    /// When the app is already on screen the block runs INLINE, on the calling
    /// thread. Callers are on the boot queue, and running there keeps the boot
    /// steps in the order they were tuned in: the transport is decided before
    /// the extra PHP runtimes start competing for CPU during first render.
    /// A parked block instead runs later on a background queue, in
    /// registration order. Either way it is the block's job to hop to main for
    /// anything UIKit or `@MainActor`.
    func whenInteractive(_ block: @escaping () -> Void) {
        // Decide AND park under a single lock. Splitting them lets a
        // `didBecomeActive` land in between: it drains an empty queue, and the
        // block appended a moment later is then owned by nobody — the app sits
        // on the splash for the rest of the process with no watchdog to save
        // it (the first-content watchdog only arms inside the boot this gate
        // is withholding).
        let runNow: Bool = lock.withLock {
            // Purely a question of whether anything is on screen right now.
            // It deliberately does NOT consult the launch classification:
            // at t=0 that is still provisional, and every launch starts out
            // looking like a background one.
            let interactive = _state != .background

            if !interactive {
                pendingInteractive.append(block)
            }

            return interactive
        }

        guard runNow else {
            NSLog("[ExecutionContext] deferring interactive work until the app becomes active")
            return
        }

        block()
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
