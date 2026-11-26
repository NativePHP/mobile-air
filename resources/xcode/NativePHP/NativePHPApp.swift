import SwiftUI
import Foundation
import PHP
import Bridge
import UIKit

/// Text output of the last `NativePHPApp.artisan(additionalArgs:)` call.
/// Only filled while that call runs; responses never go through it.
var output = ""

@_cdecl("pipe_php_output")
public func pipe_php_output(_ cString: UnsafePointer<CChar>?) {
    guard let cString = cString else { return }

    output += String(cString: cString)
}

/// Classic-mode response bytes, filled by `pipe_php_output_bytes` while
/// `NativePHPApp.laravelData(request:)` runs. Classic requests run one at a
/// time on the persistent runtime's serial queue.
var classicOutput = Data()

@_cdecl("pipe_php_output_bytes")
public func pipe_php_output_bytes(_ bytes: UnsafePointer<CChar>?, _ length: Int) {
    guard let bytes, length > 0 else { return }

    bytes.withMemoryRebound(to: UInt8.self, capacity: length) {
        classicOutput.append($0, count: length)
    }
}

@main
struct NativePHPApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) var appDelegate
    @StateObject private var appState = AppState.shared

    static var shared: NativePHPApp?

    init() {
        Self.shared = self

        DebugLogger.shared.log("📱 NativePHPApp.init() starting (minimal)")

        // Only register bridge functions in init - this is fast and doesn't block
        // All heavy initialization is deferred to after the splash view is visible
        DebugLogger.shared.log("📱 NativePHPApp.init() registering bridge functions")
        registerBridgeFunctions()
        ThermalStateMonitor.start()
        AppLifecycleMonitor.start()
        SystemFunctions.startAppearanceOverride()

        DebugLogger.shared.log("📱 NativePHPApp.init() completed (minimal)")
    }

    /// Performs heavy initialization work after the splash view is visible.
    /// This runs on a background thread to avoid blocking the main thread.
    private func performDeferredInitialization() {
        NativeLog.perf("[NativePHP] Deferred initialization starting")

        // 1–3. Prepare, extract and boot PHP, or wait for a boot that work
        // without a screen (an App Intent, a background task) already started.
        let booted = Self.bootRuntime(timeout: .infinity)

        continueAfterBoot(booted: booted)
    }

    private enum RuntimeBoot {
        case notStarted
        case booting
        case finished(Bool)
    }

    private static var runtimeBoot = RuntimeBoot.notStarted
    private static let runtimeBootCondition = NSCondition()

    /// Makes the app's PHP usable without a screen, for work iOS runs with
    /// the app closed: an App Intent, a background task, a notification
    /// action. Returns whether the persistent runtime is booted. To run a
    /// command from there, use runArtisan(_:timeout:).
    ///
    /// PHP boots once per process, through the same steps the app takes when
    /// its splash appears: environment, extraction of the bundle (including
    /// a DEBUG build's and a pending update's), the persistent boot, and the
    /// plugins' onAppReady callbacks. Whichever of the app and a caller gets
    /// here first runs them; the other waits for that boot and gets its
    /// result. Returns false in classic mode, which has no persistent
    /// runtime, when the boot failed, and after `timeout` seconds of waiting
    /// for another caller's boot.
    ///
    /// Blocks while PHP boots, a second or more on a cold start, so call it
    /// off the main thread.
    static func bootRuntime(timeout: TimeInterval = 30) -> Bool {
        runtimeBootCondition.lock()

        if case .notStarted = runtimeBoot {
            // The app sets `shared` in init(), before iOS hands it any work.
            guard let app = shared else {
                runtimeBootCondition.unlock()
                return false
            }

            runtimeBoot = .booting
            runtimeBootCondition.unlock()

            let booted = app.bootPHP()

            runtimeBootCondition.lock()
            runtimeBoot = .finished(booted)
            runtimeBootCondition.broadcast()
            runtimeBootCondition.unlock()

            return booted
        }

        let deadline = timeout.isFinite ? Date().addingTimeInterval(timeout) : .distantFuture

        while case .booting = runtimeBoot {
            if !runtimeBootCondition.wait(until: deadline) {
                break
            }
        }

        defer { runtimeBootCondition.unlock() }

        if case .finished(let booted) = runtimeBoot {
            return booted
        }

        return false
    }

    /// Runs an artisan command for work without a screen, booting PHP first
    /// as bootRuntime(timeout:) does, and returns its output. Nil when PHP
    /// isn't available, so the command didn't run.
    ///
    /// The command runs in the ephemeral runtime, a PHP context beside the
    /// app's, booted on first use. Not in the persistent runtime: while a
    /// native screen is live, its event loop holds that runtime's only
    /// thread, and a command sent there waits until the screen goes.
    ///
    /// Blocks until the command finishes, so call it off the main thread.
    static func runArtisan(_ command: String, timeout: TimeInterval = 30) -> String? {
        guard bootRuntime(timeout: timeout) else {
            return nil
        }

        if ephemeral_php_is_booted() == 0 {
            let bootstrap = AppUpdateManager.shared.getAppPath() + "/vendor/nativephp/mobile/bootstrap/ios/persistent.php"

            guard ephemeral_php_boot(bootstrap) == 0 else {
                return nil
            }
        }

        guard let result = ephemeral_php_artisan(command) else {
            return nil
        }

        defer { free(UnsafeMutableRawPointer(mutating: result)) }

        return String(cString: result)
    }

    /// Steps 1–3 of startup. Runs once per process, through bootRuntime().
    private func bootPHP() -> Bool {
        // 1. Initialize PHP environment (env vars, php.ini, database)
        NativeLog.debug("[NativePHP] preparePhpEnvironment START")
        _ = preparePhpEnvironment()
        NativeLog.debug("[NativePHP] preparePhpEnvironment DONE")

        // 2. Ensure app is extracted from bundle if needed
        NativeLog.debug("[NativePHP] ensureAppExists START")
        let didExtract = AppUpdateManager.shared.ensureAppExists()
        NativeLog.debug("[NativePHP] ensureAppExists DONE (didExtract=\(didExtract))")

        // 3. Boot persistent PHP runtime (one-time Laravel boot) — unless classic mode
        let runtimeMode = Self.getRuntimeMode()
        NativeLog.debug("[NativePHP] Runtime mode: \(runtimeMode)")

        let booted: Bool
        if runtimeMode == "classic" {
            NativeLog.debug("[NativePHP] Classic mode configured — skipping persistent runtime boot")
            booted = false
            createStorageLink()
        } else {
            NativeLog.debug("[NativePHP] PersistentPHPRuntime.boot() START")
            booted = PersistentPHPRuntime.shared.boot()
            NativeLog.perf("[NativePHP] PersistentPHPRuntime.boot() DONE, booted=\(booted)")

            if booted {
                // Only run artisan commands when app was extracted or updated.
                // Migrate already ran in ensureAppExists, once per extraction.
                if didExtract {
                    NativeLog.debug("[NativePHP] artisan storage:link START")
                    _ = PersistentPHPRuntime.shared.artisan(command: "storage:link")
                    NativeLog.debug("[NativePHP] artisan storage:link DONE")
                } else {
                    NativeLog.debug("[NativePHP] Skipping artisan commands — no extraction needed")
                }

                // Execute plugin post-boot callbacks
                NativePHPPluginRegistry.shared.executeOnAppReady()
            } else {
                NativeLog.error("[NativePHP] persistent boot failed, falling back to classic mode")
                createStorageLink()
            }
        }

        return booted
    }

    /// Steps 4–8 of startup, which only the app runs: the first screen, the
    /// plugins' onAppLaunch callbacks, hot reload and the queue workers.
    private func continueAfterBoot(booted: Bool) {
        // 4. Now that PHP is booted, decide the boot transport (native-first).
        // NATIVE_DIRECT: dispatch the first route straight into the persistent
        // runtime — no WKWebView, no WebContent/Networking processes — and let
        // the first published native tree dismiss the splash (honest first
        // content; ContentView flips it via onChange(isActive)). WEB_LEGACY:
        // byte-identical to the old flow.
        let boot = planBoot(startPath: NativePHPApp.getStartURL())

        switch boot.plan {
        case .nativeDirect:
            DispatchQueue.main.async {
                NativeLog.debug("TRACE[15]: native-direct boot — WebView withheld")
                BootState.shared.webViewAllowed = false
                // Forget the link this boot opens before PHP counts as ready,
                // or the replay would push its screen a second time.
                if boot.opensLink {
                    DeepLinkRouter.shared.clearPendingURL()
                }
                DeepLinkRouter.shared.markPhpReady()
                AppState.shared.markReadyToLoad()
                // markInitialized deliberately NOT called here — the splash
                // dismisses on first native content, or via the watchdog.
            }
            startNativeSession(boot.path)
            startFirstContentWatchdog()
        case .webLegacy:
            DispatchQueue.main.async {
                NativeLog.debug("TRACE[15]: markPhpReady + markReadyToLoad + markInitialized on main thread")
                DeepLinkRouter.shared.markPhpReady()
                AppState.shared.markReadyToLoad()
                AppState.shared.markInitialized()
            }
        }

        // 5. Execute plugin initialization callbacks (on main thread)
        DispatchQueue.main.async {
            NativePHPPluginRegistry.shared.executeOnAppLaunch()
        }

        // 6. Reload handling + hot reload server. The coordinator must be
        // registered before anything can post reloadWebViewNotification —
        // HotReloadServer triggers (DEBUG) — and independently of the WebView,
        // which a native-direct boot never mounts.
        HotReloadCoordinator.shared.activate()
        #if DEBUG
        HotReloadServer.shared.start()
        #endif

        // 7. OTA check/download lives in the mobile-ota plugin. Core only
        // applies pending zips from Documents/updates on boot (ensureAppExists
        // → applyPendingUpdates), and only when that plugin is installed
        // (NATIVEPHP_OTA). Do not re-enable a Bifrost client here.

        // 8. Background lanes. Neither boots PHP here: the queue worker boots
        //    when a job is pushed (or one was left queued last run), and the
        //    async pool boots a slot on the first AsyncTask::dispatch().
        if booted {
            NativeLog.debug("[NativePHP] PHPQueueWorker.start() (deferred)")
            PHPQueueWorker.shared.start()

            NativeLog.debug("[NativePHP] AsyncTaskExecutor.start() (deferred)")
            AsyncTaskExecutor.shared.start()
        } else {
            NativeLog.error("[NativePHP] Queue worker NOT started — persistent runtime boot failed")
        }

        NativeLog.perf("[NativePHP] Deferred initialization completed")
    }

    /// Picks how the first screen boots and which route it opens.
    ///
    /// A cold deep link to a native screen boots straight into that screen,
    /// query included, the same as Android. It is the only screen on the
    /// stack, as it was when cold links went through the WebView.
    private func planBoot(startPath: String) -> (plan: BootPlanner.Entry, path: String, opensLink: Bool) {
        guard let linkRoute = DeepLinkRouter.shared.pendingRoute() else {
            return (BootPlanner.plan(startPath: startPath), startPath, false)
        }

        if BootPlanner.plan(startPath: linkRoute) == .nativeDirect {
            return (.nativeDirect, linkRoute, true)
        }

        if BootPlanner.plan(startPath: startPath) == .nativeDirect {
            // A link to a path with no native screen could be a web route or
            // nothing at all, and only PHP can tell. Loading it in the WebView
            // shows a local 404 for the second kind, with no way back into the
            // app. Boot the start screen instead: the link is replayed as
            // __deeplink once native-ui is active, so PHP navigates to it,
            // gives a universal link back to Safari, or drops it and stays on
            // the start screen, the same as a link that arrives while the app
            // is open. Custom-scheme links and tapped notifications go this
            // way too.
            return (.nativeDirect, startPath, false)
        }

        // Any link loads in the WebView when the start route isn't native either.
        return (.webLegacy, startPath, false)
    }

    /// Native-first boot: dispatch the first route directly into the
    /// persistent runtime (same entry hot reload uses — the iOS analog of
    /// Android's executeNativeRoute). The dispatch blocks its queue for the
    /// life of the native session; the eventual response is the session's
    /// exit envelope: 3xx+Location = EXIT_WEB, 204 = hot-restart, else the
    /// stack emptied. If PHP dies part-way, NativeSession restarts it.
    private func startNativeSession(_ uri: String) {
        DispatchQueue.global(qos: .userInitiated).async {
            // URIs go through %@, never into NSLog's format string: a deep
            // link's query can hold %s, and enough of them crash the app.
            NativeLog.debug("[NativeBoot] 🚀 Direct native dispatch: \(uri)")
            let raw = NativeSession.run(uri: uri)
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

        NativeLog.debug("[NativeBoot] Native session exited: status=\(status) location=\(location ?? "nil")")

        if (300...399).contains(status), let location, !location.isEmpty {
            // Keep the query, and keep both it and the path percent-encoded so
            // PHP decodes them once. The web route reads the query from
            // request()->query(); Foundation's `query` is still encoded, but
            // `path` is not.
            let path = location.hasPrefix("http") || location.hasPrefix("php:")
                ? (URL(string: location).map { url in url.path(percentEncoded: true) + (url.query.map { "?" + $0 } ?? "") } ?? "/")
                : location
            DispatchQueue.main.async {
                NativeLog.debug("[NativeBoot] ⇄ EXIT_WEB → \(path)")
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
                NativeLog.error("[NativeBoot] ⏰ No content 10s after native boot — falling back to WebView")
                BootState.shared.allowWebView(loading: nil)
                AppState.shared.markInitialized()
            }
        }
    }

    var body: some Scene {
        WindowGroup {
            ZStack {
                // Phase 2: Once deferred init is complete, render ContentView so WebView can load
                // It renders underneath the splash until WebView finishes loading
                if appState.isReadyToLoad {
                    ContentView()
                }

                // Splash overlays until WebView finishes loading (Phase 3)
                if !appState.isInitialized {
                    SplashView()
                        .transition(.opacity)
                        .onAppear {
                            // Phase 1: Start deferred initialization on a background thread
                            // This runs AFTER the splash view is visible, avoiding watchdog timeout
                            DispatchQueue.global(qos: .userInitiated).async {
                                performDeferredInitialization()
                            }
                        }
                }
            }
            // Dev-mode perf overlay (top-right pill: fps / p99 / jank).
            // Driven by CADisplayLink via FrameTracker.shared. Sits on
            // top of ContentView AND the splash so it's visible during
            // boot / hot-reload too. Toggle off for production
            // screenshots via FrameTracker.shared.enabled = false.
            .perfOverlay()
            .animation(.easeInOut(duration: 0.3), value: appState.isInitialized)
            .onOpenURL { url in
                // Only handle if not already handled by AppDelegate during cold start
                if !DeepLinkRouter.shared.hasPendingURL() {
                    DeepLinkRouter.shared.handle(url: url)
                }
            }
            .onContinueUserActivity(NSUserActivityTypeBrowsingWeb) { activity in
                if activity.activityType == NSUserActivityTypeBrowsingWeb,
                let url = activity.webpageURL {
                    // Only handle if not already handled by AppDelegate during cold start
                    if !DeepLinkRouter.shared.hasPendingURL() {
                        DeepLinkRouter.shared.handle(url: url)   // Universal Links
                    }
                }
            }
        }
    }

    private func getAppSupportDir(dir: String) -> String {
        // Get the URL for the Library directory in the user domain
        let appSupportURL = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first

        // Append "Application Support" to the Library directory URL
        let destination = appSupportURL!.appendingPathComponent(dir)

        do {
            try FileManager.default.createDirectory(
                at: destination,
                withIntermediateDirectories: true,
                attributes: nil
            )
        } catch {
            // Handle the error
        }

        // If you need the path as a String
        return destination.path
    }

    /// Read runtime_mode from bundle_meta.json. Returns "persistent" (default) or "classic".
    static func getRuntimeMode() -> String {
        guard let path = Bundle.main.path(forResource: "bundle_meta", ofType: "json"),
              let data = FileManager.default.contents(atPath: path),
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let mode = json["runtime_mode"] as? String else {
            return "persistent"
        }
        return mode
    }

    /// Read the NATIVEPHP_START_URL from the .env file
    static func getStartURL() -> String {
        let appPath = AppUpdateManager.shared.getAppPath()
        let envPath = URL(fileURLWithPath: appPath).appendingPathComponent(".env")

        guard FileManager.default.fileExists(atPath: envPath.path),
              let envContent = try? String(contentsOf: envPath, encoding: .utf8) else {
            DebugLogger.shared.log("⚙️ No .env file found, using default start URL")
            return "/"
        }

        // Use regex to find NATIVEPHP_START_URL value
        let pattern = #"NATIVEPHP_START_URL\s*=\s*([^\r\n]+)"#
        if let regex = try? NSRegularExpression(pattern: pattern),
           let match = regex.firstMatch(in: envContent, range: NSRange(envContent.startIndex..., in: envContent)),
           let valueRange = Range(match.range(at: 1), in: envContent) {
            var value = String(envContent[valueRange])
                .trimmingCharacters(in: .whitespaces)
                .trimmingCharacters(in: CharacterSet(charactersIn: "\"'"))

            if !value.isEmpty {
                // Ensure path starts with /
                if !value.hasPrefix("/") {
                    value = "/" + value
                }
                DebugLogger.shared.log("⚙️ Found start URL in .env: \(value)")
                return value
            }
        }

        DebugLogger.shared.log("⚙️ No NATIVEPHP_START_URL found, using default: /")
        return "/"
    }

    private func createPhpIni() -> String {
        let caPath = Bundle.main.path(forResource: "cacert", ofType: "pem") ?? "Path not found"

        // Artisan and classic requests start PHP again for every call, so
        // they never use the file cache (see PHPIni.write).
        return PHPIni.write(caPath: caPath, fileCache: false)
    }

    private func createDatabase() {
        let fileManager = FileManager.default

        let databaseFileURL = fileManager.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
            .appendingPathComponent("database/database.sqlite")

        if !fileManager.fileExists(atPath: databaseFileURL.path) {
            // Create an empty SQLite file
            fileManager.createFile(
                atPath: databaseFileURL.path,
                contents: nil,
                attributes: nil
            )
        }
    }

    private func migrateDatabase() {
        _ = artisan(additionalArgs: ["migrate", "--force"])
    }

    private func clearCaches() {
        _ = artisan(additionalArgs: ["view:clear"])
    }

    private func createStorageLink() {
        _ = artisan(additionalArgs: ["storage:link"])
    }

    private func preparePhpEnvironment() -> String {
        let phpIniPath = createPhpIni()

        setenv("PHPRC", phpIniPath, 1)

        setupEnvironment()

        // Point PHP's output at the capture handler, with nothing forwarded
        // to Swift. Classic requests and artisan calls install their own
        // capture for as long as they run; a callback left in place here
        // would copy every persistent and webview response into `output`.
        override_embed_module_output(nil)

        createDatabase()

        return ""
    }

    /// Classic mode: run one request in a fresh interpreter and return the
    /// raw HTTP response as text. Kept for callers that parse text (the
    /// app-intents plugin reads JSON from it); a binary body is decoded
    /// lossily. Use `laravelData(request:)` for the exact bytes.
    static func laravel(request: RequestData) -> String? {
        laravelData(request: request).map { String(decoding: $0, as: UTF8.self) }
    }

    /// Classic mode: run one request in a fresh interpreter
    /// (bootstrap/ios/native.php) and return the raw HTTP response byte for
    /// byte. The body goes into php://input as exact bytes for any method,
    /// and its Content-Type reaches PHP as CONTENT_TYPE in the environment.
    static func laravelData(request: RequestData) -> Data? {
        let body = request.bodyBytes ?? Data()

        // PHP keeps pointers to these in SG(request_info) until
        // php_embed_shutdown(), so they are freed only after it.
        let methodC = strdup(request.method)
        let uriC = strdup(request.uri)

        defer {
            free(methodC)
            free(uriC)
        }

        classicOutput = Data()

        override_embed_module_output_bytes(pipe_php_output_bytes)

        var argv: [UnsafeMutablePointer<CChar>?] = [
            strdup("php")
        ]

        let argc = Int32(argv.count)

        let appPath = AppUpdateManager.shared.getAppPath()
        let phpFilePath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/native.php"

        NativeLog.debug()
        NativeLog.debug("=== FORWARDING REQUEST TO LARAVEL ===")
        NativeLog.debug()

        let query = request.query ?? ""
        var uri = request.uri
        if !query.isEmpty {
            uri += "?" + query
        }

        setenv("REMOTE_ADDR", "0.0.0.0", 1)
        setenv("REQUEST_URI", uri, 1)
        setenv("QUERY_STRING", query, 1)
        setenv("REQUEST_METHOD", request.method, 1)
        setenv("SCRIPT_FILENAME", phpFilePath, 1)
        setenv("PHP_SELF", "/native.php", 1)
        setenv("HTTP_HOST", "127.0.0.1", 1)
        setenv("ASSET_URL", "php://127.0.0.1/_assets/", 1)
        setenv("NATIVEPHP_RUNNING", "true", 1)
        setenv("APP_URL", "php://127.0.0.1", 1)

        var envKeys: [String] = []

        for (header, value) in request.headers {
            let formattedKey = "HTTP_" + header
                .replacingOccurrences(of: "-", with: "_")
                .uppercased()

            // Convert Swift strings to C strings
            guard let cKey = formattedKey.cString(using: .utf8),
                  let cValue = value.cString(using: .utf8) else {
                NativeLog.debug("Failed to convert \(header) or its value to C string.")
                continue
            }

            // Set this as env so that it will get picked up in $_SERVER
            setenv(cKey, cValue, 1)
            envKeys.append(formattedKey)
        }

        // The body's own Content-Type, boundary included. PHP's
        // SG(request_info).content_type stays NULL (see PHP.h).
        if let contentType = request.header("Content-Type"), !contentType.isEmpty {
            setenv("CONTENT_TYPE", contentType, 1)
        } else {
            unsetenv("CONTENT_TYPE")
        }
        envKeys.append("CONTENT_TYPE")

        // Equivalent to PHP_EMBED_START_BLOCK
        PHPIni.noteStartup()

        argv.withUnsafeMutableBufferPointer { bufferPtr in
            php_embed_init(argc, bufferPtr.baseAddress)

            body.withUnsafeBytes { bodyBuffer in
                initialize_php_with_request_bytes(
                    bodyBuffer.baseAddress?.assumingMemoryBound(to: CChar.self),
                    bodyBuffer.count,
                    methodC,
                    uriC
                )
            }

            var fileHandle = zend_file_handle()
            zend_stream_init_filename(&fileHandle, phpFilePath)

            php_execute_script(&fileHandle)

            // Equivalent to PHP_EMBED_END_BLOCK
            php_embed_shutdown()

            // Clean up env variables for headers
            for key in envKeys {
                unsetenv(key)
            }
            envKeys.removeAll()
        }

        // Stop forwarding output: nothing else reads it.
        override_embed_module_output_bytes(nil)

        // Free argv strings
        argv.forEach { free($0) }

        NativeLog.debug()
        NativeLog.debug("=== LARAVEL FINISHED ===")
        NativeLog.debug()

        let response = classicOutput
        classicOutput = Data()
        return response
    }

    private func setupEnvironment() {
        let storageDir = getAppSupportDir(dir: "storage")
        let viewCacheDir = getAppSupportDir(dir: "storage/framework/views")
        let databaseDir = getAppSupportDir(dir: "database")

        // Ensure other directories exist
        _ = getAppSupportDir(dir: "storage/framework/cache")
        _ = getAppSupportDir(dir: "storage/framework/sessions")
        _ = getAppSupportDir(dir: "storage/logs")

        // Get temporary directory
        let tempDir = FileManager.default.temporaryDirectory.path

        // Before any PHP runs, so the migrate after extraction loads the
        // package's migrations in both runtime modes.
        setenv("NATIVEPHP_RUNNING", "true", 1)
        setenv("NATIVEPHP_PLATFORM", "ios", 1)
        setenv("NATIVEPHP_OS_VERSION", NativeOSVersion.string, 1)
        setenv("NATIVEPHP_TEMPDIR", tempDir, 1)
        setenv("LARAVEL_STORAGE_PATH", storageDir, 1)
        setenv("VIEW_COMPILED_PATH", viewCacheDir, 1)
        setenv("DB_DATABASE", "\(databaseDir)/database.sqlite", 1)

        // Set APP_KEY from secure storage (generates on first run).
        // If a locked background launch hits an un-migrated WhenUnlocked item
        // (or a Before-First-Unlock launch), wait for protected data before
        // booting PHP so Laravel and event signing never start without APP_KEY.
        switch Self.resolveAppKey() {
        case .key(let appKey):
            setenv("APP_KEY", appKey, 1)
        case .locked:
            Self.waitForProtectedDataAvailable()
            if case .key(let appKey) = Self.resolveAppKey() {
                setenv("APP_KEY", appKey, 1)
                DebugLogger.shared.log("🔑 Recovered APP_KEY after protected data became available")
            }
        case .failed:
            break
        }

        // Tell the embedded PHP to enforce signatures on device events. This
        // shell signs every event it posts (see LaravelBridge.dispatch), so PHP
        // can reject a forged one. It is set only here, on the on-device shell
        // boot, so it never reaches a shared PHP bootstrap or the dev-machine
        // PHP under Jump — there the native setenv does not apply, events stay
        // unsigned, and enforcement stays off.
        setenv("NATIVEPHP_EVENTS_SIGNED", "1", 1)
    }

    private enum AppKeyLookupResult {
        case key(String)
        case locked
        case failed
    }

    private final class ObserverTokenBox: @unchecked Sendable {
        var token: NSObjectProtocol?
    }

    /// Retrieves APP_KEY from Keychain or generates a new one on first launch.
    /// Stored with `kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly` so a
    /// background launch while the screen is locked can still read the key.
    static func getOrGenerateAppKey() -> String? {
        if case .key(let appKey) = resolveAppKey() {
            return appKey
        }
        return nil
    }

    private static func resolveAppKey() -> AppKeyLookupResult {
        let service = Bundle.main.bundleIdentifier ?? "com.nativephp.app"
        let account = "APP_KEY"
        let targetAccessibility = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly as String

        // Retrieve both data and attributes so we only run SecItemUpdate once
        // when migrating legacy WhenUnlocked items.
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrAccount as String: account,
            kSecAttrService as String: service,
            kSecReturnAttributes as String: true,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]

        var result: AnyObject?
        let status = SecItemCopyMatching(query as CFDictionary, &result)

        if status == errSecSuccess,
           let item = result as? [String: Any],
           let data = item[kSecValueData as String] as? Data,
           let existingKey = String(data: data, encoding: .utf8) {
            let currentAccessibility = item[kSecAttrAccessible as String] as? String
            if currentAccessibility != targetAccessibility {
                let matchQuery: [String: Any] = [
                    kSecClass as String: kSecClassGenericPassword,
                    kSecAttrAccount as String: account,
                    kSecAttrService as String: service
                ]
                let updateAttributes: [String: Any] = [
                    kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
                ]
                let updateStatus = SecItemUpdate(matchQuery as CFDictionary, updateAttributes as CFDictionary)
                if updateStatus != errSecSuccess {
                    DebugLogger.shared.log("⚠️ Failed to migrate APP_KEY accessibility: \(updateStatus)")
                }
            }
            DebugLogger.shared.log("🔑 Retrieved existing APP_KEY from Keychain")
            return .key(existingKey)
        }

        if status == errSecInteractionNotAllowed {
            DebugLogger.shared.log("⚠️ Keychain locked while reading APP_KEY (errSecInteractionNotAllowed)")
            return .locked
        }

        // Only generate a new key when none exists in the Keychain. Any other
        // Keychain error must not overwrite or duplicate an existing key.
        guard status == errSecItemNotFound else {
            DebugLogger.shared.log("⚠️ Could not read APP_KEY from Keychain (status: \(status))")
            return .failed
        }

        // Generate new APP_KEY if not found
        DebugLogger.shared.log("🔑 Generating new APP_KEY for first launch")
        guard let newKey = generateAppKey() else {
            NativeLog.error("❌ Failed to generate APP_KEY")
            return .failed
        }

        // Store in Keychain
        guard let keyData = newKey.data(using: .utf8) else {
            NativeLog.error("❌ Failed to encode APP_KEY")
            return .failed
        }

        let addQuery: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrAccount as String: account,
            kSecAttrService as String: service,
            kSecValueData as String: keyData,
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        ]

        let addStatus = SecItemAdd(addQuery as CFDictionary, nil)

        if addStatus == errSecSuccess {
            DebugLogger.shared.log("✅ APP_KEY stored securely in Keychain")
            return .key(newKey)
        } else if addStatus == errSecInteractionNotAllowed {
            DebugLogger.shared.log("⚠️ Keychain locked while storing APP_KEY (errSecInteractionNotAllowed)")
            return .locked
        } else {
            NativeLog.error("❌ Failed to store APP_KEY in Keychain: \(addStatus)")
            return .failed
        }
    }

    /// Blocks the background init thread until iOS reports protected data is
    /// available. Checking `isProtectedDataAvailable` and registering the
    /// observer in the same main-queue block avoids missing an unlock between
    /// the failed Keychain read and observer registration.
    private static func waitForProtectedDataAvailable() {
        guard !Thread.isMainThread else { return }

        let semaphore = DispatchSemaphore(value: 0)

        DispatchQueue.main.async {
            if UIApplication.shared.isProtectedDataAvailable {
                semaphore.signal()
                return
            }

            let box = ObserverTokenBox()
            box.token = NotificationCenter.default.addObserver(
                forName: UIApplication.protectedDataDidBecomeAvailableNotification,
                object: nil,
                queue: .main
            ) { _ in
                if let token = box.token {
                    box.token = nil
                    NotificationCenter.default.removeObserver(token)
                    semaphore.signal()
                }
            }
        }

        semaphore.wait()
    }

    /// Generates a new Laravel-compatible APP_KEY (base64:...)
    private static func generateAppKey() -> String? {
        var keyData = Data(count: 32)
        let result = keyData.withUnsafeMutableBytes {
            SecRandomCopyBytes(kSecRandomDefault, 32, $0.baseAddress!)
        }

        guard result == errSecSuccess else {
            return nil
        }

        // Laravel expects format: base64:...
        let base64Key = keyData.base64EncodedString()
        return "base64:\(base64Key)"
    }

    func artisan(additionalArgs: [String] = []) -> String {
        NativeLog.debug("Running `php artisan \(additionalArgs.joined())`...")

        output = ""

        // Capture this call's output only, and stop once it is done.
        override_embed_module_output(pipe_php_output)
        defer { override_embed_module_output(nil) }

        var argv: [UnsafeMutablePointer<CChar>?] = [
            strdup("php")
        ]

        setenv("PHP_SELF", "artisan.php", 1)
        setenv("APP_RUNNING_IN_CONSOLE", "true", 1)

        let additionalCArgs = additionalArgs.map { strdup($0) }
        argv.append(contentsOf: additionalCArgs)

        let argc = Int32(argv.count)

        let appPath = AppUpdateManager.shared.getAppPath()
        let phpFilePath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/artisan.php"

        PHPIni.noteStartup()

        argv.withUnsafeMutableBufferPointer { bufferPtr in
            php_embed_init(argc, bufferPtr.baseAddress)

            var fileHandle = zend_file_handle()
            zend_stream_init_filename(&fileHandle, phpFilePath)

            php_execute_script(&fileHandle)

            php_embed_shutdown()
        }

        argv.forEach { free($0) }

        return output
    }
}
