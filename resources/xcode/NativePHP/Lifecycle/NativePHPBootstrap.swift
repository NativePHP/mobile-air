import Foundation

/// Owns the app's boot sequence, split into two phases that are gated
/// independently:
///
///  * **Runtime phase** — PHP environment, bundle extraction, the persistent
///    Laravel runtime, plugin callbacks, hot reload. Headless and safe to run
///    whenever the process starts, including a background cold launch on a
///    locked device.
///  * **Interactive phase** — the boot transport (`NATIVEPHP_START_URL` is
///    dispatched, `ContentView` is rendered, a WKWebView may be created), plus
///    the queue worker, whose second PHP context is memory a background wake
///    never asked for. Held behind `ExecutionContext.whenInteractive` until
///    the app is actually on screen.
///
/// Splitting them is what makes a BGTaskScheduler wake safe: iOS cold-launches
/// the whole SwiftUI app to run one scheduled command, and without the gate
/// the start route would run its middleware, mount components and start
/// polling while the process is backgrounded and the device is locked.
///
/// Two entry points call `start()` — `SplashView.onAppear` for a normal
/// launch, and `AppDelegate` for a background launch (which has no scene, so
/// no `onAppear` fires and nothing else would boot the runtime the background
/// work needs). `start()` is idempotent; whichever fires first wins.
final class NativePHPBootstrap: @unchecked Sendable {
    static let shared = NativePHPBootstrap()

    private let lock = NSLock()
    private var started = false

    private init() {}

    /// Kick off the boot sequence on a background thread. Safe to call more
    /// than once and from any thread — only the first call does work.
    func start() {
        let alreadyStarted: Bool = lock.withLock {
            defer { started = true }
            return started
        }

        guard !alreadyStarted else {
            NSLog("[NativePHP] Bootstrap already started — ignoring duplicate start()")
            return
        }

        DispatchQueue.global(qos: .userInitiated).async { [self] in
            bootRuntime()
        }
    }

    // MARK: - Runtime phase (headless-safe)

    /// Performs heavy initialization work after the splash view is visible.
    /// This runs on a background thread to avoid blocking the main thread.
    private func bootRuntime() {
        NSLog("[NativePHP] Deferred initialization starting (headless=\(ExecutionContext.shared.isHeadless))")

        // 1. Initialize PHP environment (env vars, php.ini, database)
        NSLog("[NativePHP] preparePhpEnvironment START")
        _ = NativePHPApp.preparePhpEnvironment()
        NSLog("[NativePHP] preparePhpEnvironment DONE")

        // 2. Ensure app is extracted from bundle if needed
        NSLog("[NativePHP] ensureAppExists START")
        let didExtract = AppUpdateManager.shared.ensureAppExists()
        NSLog("[NativePHP] ensureAppExists DONE (didExtract=\(didExtract))")

        // 3. Boot persistent PHP runtime (one-time Laravel boot) — unless classic mode
        let runtimeMode = NativePHPApp.getRuntimeMode()
        NSLog("[NativePHP] Runtime mode: \(runtimeMode)")

        let booted: Bool
        if runtimeMode == "classic" {
            NSLog("[NativePHP] Classic mode configured — skipping persistent runtime boot")
            booted = false
            NativePHPApp.createStorageLink()
        } else {
            NSLog("[NativePHP] PersistentPHPRuntime.boot() START")
            booted = PersistentPHPRuntime.shared.boot()
            NSLog("[NativePHP] PersistentPHPRuntime.boot() DONE, booted=\(booted)")

            if booted {
                // Only run artisan commands when app was extracted or updated
                if didExtract {
                    NSLog("[NativePHP] artisan migrate START (post-extraction)")
                    _ = PersistentPHPRuntime.shared.artisan(command: "migrate --force")
                    NSLog("[NativePHP] artisan migrate DONE")

                    NSLog("[NativePHP] artisan storage:link START")
                    _ = PersistentPHPRuntime.shared.artisan(command: "storage:link")
                    NSLog("[NativePHP] artisan storage:link DONE")
                } else {
                    NSLog("[NativePHP] Skipping artisan commands — no extraction needed")
                }

                // Execute plugin post-boot callbacks. Background-task plugins
                // register their handlers here, so this must NOT be gated on
                // the app being interactive — a background launch exists
                // precisely to give them a runtime to run in.
                NativePHPPluginRegistry.shared.executeOnAppReady()
            } else {
                NSLog("[NativePHP] persistent boot failed, falling back to classic mode")
                NativePHPApp.createStorageLink()
            }
        }

        // 4. The runtime is up. Hand the first screen over to the interactive
        // phase — which only runs once there is a user to show it to. On a
        // headless background cold launch this parks until the app is opened.
        ExecutionContext.shared.whenInteractive { [self] in
            startInteractiveBoot()
        }

        // 5. Execute plugin initialization callbacks (on main thread)
        DispatchQueue.main.async {
            NativePHPPluginRegistry.shared.executeOnAppLaunch()
        }

        // 6. Reload handling + hot reload server. The coordinator must be
        // registered before anything can post reloadWebViewNotification —
        // HotReloadServer triggers (DEBUG) or AppUpdateManager after an OTA
        // update (production) — and independently of the WebView, which a
        // native-direct boot never mounts.
        HotReloadCoordinator.shared.activate()
        #if DEBUG
        HotReloadServer.shared.start()
        #endif

        // 7. OTA check commented out — parity with Android, where the boot-time
        // Bifrost request was disabled for its network latency on cold boot.
        // TODO: Re-enable on BOTH platforms together when OTA is ready for
        // production, as an async check after first content — never on the
        // boot path.
        // NSLog("[NativePHP] checkForUpdates START")
        // AppUpdateManager.shared.checkForUpdates()

        // 8. Defer queue worker boot — start AFTER the critical path completes
        //    so it doesn't compete for CPU/memory during first page render,
        //    and only once the app is on screen. The worker boots a SECOND PHP
        //    TSRM context; spending that memory during a headless background
        //    wake is what gets the process jetsammed mid-task, and the wake
        //    never asked for it. Jobs dispatched by background work still
        //    queue normally — they're picked up when the app is next opened.
        if booted {
            ExecutionContext.shared.whenInteractive {
                NSLog("[NativePHP] PHPQueueWorker.start() (deferred)")
                PHPQueueWorker.shared.start()
            }
        } else {
            NSLog("[NativePHP] Queue worker NOT started — persistent runtime boot failed")
        }

        NSLog("[NativePHP] Deferred initialization completed")
    }

    // MARK: - Interactive phase (app on screen)

    /// Decide the boot transport (native-first) and start the first screen.
    ///
    /// NATIVE_DIRECT: dispatch the start route straight into the persistent
    /// runtime — no WKWebView, no WebContent/Networking processes — and let
    /// the first published native tree dismiss the splash (honest first
    /// content; ContentView flips it via onChange(isActive)). WEB_LEGACY:
    /// byte-identical to the old flow.
    ///
    /// Runs at most once per process: a launch that deferred this until
    /// `didBecomeActive` must not re-run it on every later foreground.
    /// Called on a background queue — only the state mutations hop to main.
    private func startInteractiveBoot() {
        guard ExecutionContext.shared.claimInteractiveBoot() else {
            NSLog("[NativePHP] Interactive boot already ran — skipping")
            return
        }

        // Resolved here rather than during the runtime phase so a deep link
        // that arrived while the app sat backgrounded is still honoured.
        let startPath = NativePHPApp.getStartURL()
        let hasPendingDeepLink = DeepLinkRouter.shared.hasPendingURL()
        let plan: BootPlanner.Entry = hasPendingDeepLink
            ? .webLegacy // cold deep links keep the proven WebView path for now
            : BootPlanner.plan(startPath: startPath)

        switch plan {
        case .nativeDirect:
            DispatchQueue.main.async {
                NSLog("TRACE[15]: native-direct boot — WebView withheld")
                BootState.shared.webViewAllowed = false
                DeepLinkRouter.shared.markPhpReady()
                AppState.shared.markReadyToLoad()
                // markInitialized deliberately NOT called here — the splash
                // dismisses on first native content, or via the watchdog.
            }
            startNativeSession(startPath)
            startFirstContentWatchdog()
        case .webLegacy:
            DispatchQueue.main.async {
                NSLog("TRACE[15]: markPhpReady + markReadyToLoad + markInitialized on main thread")
                DeepLinkRouter.shared.markPhpReady()
                AppState.shared.markReadyToLoad()
                AppState.shared.markInitialized()
            }
        }
    }

    /// Native-first boot: dispatch the start route directly into the
    /// persistent runtime (same entry hot reload uses — the iOS analog of
    /// Android's executeNativeRoute). The dispatch blocks its queue for the
    /// life of the native session; the eventual response is the session's
    /// exit envelope: 3xx+Location = EXIT_WEB, 204 = hot-restart, else the
    /// stack emptied.
    private func startNativeSession(_ uri: String) {
        DispatchQueue.global(qos: .userInitiated).async { [self] in
            NSLog("[NativeBoot] 🚀 Direct native dispatch: \(uri)")
            let request = RequestData(
                method: "GET",
                uri: uri,
                data: nil,
                query: nil,
                headers: [:]
            )
            let raw = PersistentPHPRuntime.shared.dispatch(request: request)
            handleNativeSessionExit(raw)
        }
    }

    private func handleNativeSessionExit(_ rawResponse: String) {
        let head = rawResponse.components(separatedBy: "\r\n\r\n").first ?? rawResponse
        let lines = head.components(separatedBy: "\r\n")
        let status = lines.first?
            .components(separatedBy: " ")
            .dropFirst().first.flatMap { Int($0) } ?? 200
        let location = lines
            .first { $0.lowercased().hasPrefix("location:") }?
            .components(separatedBy: ":").dropFirst().joined(separator: ":")
            .trimmingCharacters(in: .whitespaces)

        NSLog("[NativeBoot] Native session exited: status=\(status) location=\(location ?? "nil")")

        if (300...399).contains(status), let location, !location.isEmpty {
            let path = location.hasPrefix("http") || location.hasPrefix("php:")
                ? (URL(string: location)?.path ?? "/")
                : location
            DispatchQueue.main.async {
                NSLog("[NativeBoot] ⇄ EXIT_WEB → \(path)")
                BootState.shared.allowWebView(loading: path)
                // Drop the native branch explicitly. The usual clearers can't
                // run here: didCommit needs the WebView to load, but the
                // WebView only MOUNTS once the native branch unmounts — and
                // the region teardown may be preserve-tree'd. Without this
                // the exit deadlocks on a frozen native screen.
                NativeUIBridge.shared.isActive = false
                AppState.shared.markInitialized()
            }
        }
        // 204 = hot restart in flight (the reload path re-dispatches);
        // anything else = the native stack emptied — iOS apps can't finish()
        // themselves, so the splash-free native background simply remains.
    }

    /// A broken PHP boot in native-direct mode would otherwise wedge on the
    /// splash forever (no WebView error page to fall back on). After 10s
    /// without content, fall back to the legacy WebView path.
    private func startFirstContentWatchdog() {
        DispatchQueue.main.asyncAfter(deadline: .now() + 10) {
            if !AppState.shared.isInitialized {
                NSLog("[NativeBoot] ⏰ No content 10s after native boot — falling back to WebView")
                BootState.shared.allowWebView(loading: nil)
                AppState.shared.markInitialized()
            }
        }
    }
}
