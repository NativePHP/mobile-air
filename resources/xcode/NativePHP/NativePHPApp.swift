import SwiftUI
import Foundation
import PHP
import Bridge
import UIKit

var output = ""

@_cdecl("pipe_php_output")
public func pipe_php_output(_ cString: UnsafePointer<CChar>?) {
    guard let cString = cString else { return }

    output += String(cString: cString)
}

@main
struct NativePHPApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) var appDelegate
    @StateObject private var appState = AppState.shared
    @ObservedObject private var nativeUIState = NativeUIState.shared

    static var shared: NativePHPApp?

    init() {
        Self.shared = self

        DebugLogger.shared.log("📱 NativePHPApp.init() starting (minimal)")

        // Only register bridge functions in init - this is fast and doesn't block
        // All heavy initialization is deferred to after the splash view is visible
        DebugLogger.shared.log("📱 NativePHPApp.init() registering bridge functions")
        registerBridgeFunctions()

        DebugLogger.shared.log("📱 NativePHPApp.init() completed (minimal)")
    }

    /// Performs heavy initialization work after the splash view is visible.
    /// This runs on a background thread to avoid blocking the main thread.
    private func performDeferredInitialization() {
        NSLog("[NativePHP] Deferred initialization starting")

        // 1. Initialize PHP environment (env vars, php.ini, database)
        NSLog("[NativePHP] preparePhpEnvironment START")
        _ = preparePhpEnvironment()
        NSLog("[NativePHP] preparePhpEnvironment DONE")

        // 2. Ensure app is extracted from bundle if needed
        NSLog("[NativePHP] ensureAppExists START")
        let didExtract = AppUpdateManager.shared.ensureAppExists()
        NSLog("[NativePHP] ensureAppExists DONE (didExtract=\(didExtract))")

        // 3. Boot persistent PHP runtime (one-time Laravel boot) — unless classic mode
        let runtimeMode = Self.getRuntimeMode()
        NSLog("[NativePHP] Runtime mode: \(runtimeMode)")

        let booted: Bool
        if runtimeMode == "classic" {
            NSLog("[NativePHP] Classic mode configured — skipping persistent runtime boot")
            booted = false
            createStorageLink()
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

                // Execute plugin post-boot callbacks
                NativePHPPluginRegistry.shared.executeOnAppReady()
            } else {
                NSLog("[NativePHP] persistent boot failed, falling back to classic mode")
                createStorageLink()
            }
        }

        // 4. Now that PHP is booted, decide the boot transport (native-first).
        // NATIVE_DIRECT: dispatch the start route straight into the persistent
        // runtime — no WKWebView, no WebContent/Networking processes — and let
        // the first published native tree dismiss the splash (honest first
        // content; ContentView flips it via onChange(isActive)). WEB_LEGACY:
        // byte-identical to the old flow.
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

        // 8. Defer queue worker boot — start AFTER critical path completes
        //    so it doesn't compete for CPU/memory during first page render
        if booted {
            NSLog("[NativePHP] PHPQueueWorker.start() (deferred)")
            PHPQueueWorker.shared.start()
        } else {
            NSLog("[NativePHP] Queue worker NOT started — persistent runtime boot failed")
        }

        NSLog("[NativePHP] Deferred initialization completed")
    }

    /// Native-first boot: dispatch the start route directly into the
    /// persistent runtime (same entry hot reload uses — the iOS analog of
    /// Android's executeNativeRoute). The dispatch blocks its queue for the
    /// life of the native session; the eventual response is the session's
    /// exit envelope: 3xx+Location = EXIT_WEB, 204 = hot-restart, else the
    /// stack emptied.
    private func startNativeSession(_ uri: String) {
        DispatchQueue.global(qos: .userInitiated).async {
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
            // The effective layout direction for the WHOLE native shell —
            // top bar, side nav, bottom nav, gestures, and any UIKit
            // chrome — follows the device locale when the developer opted
            // into RTL support. SwiftUI flips logical leading/trailing
            // placements (and system components) automatically.
            .environment(\.layoutDirection, nativeUIState.layoutDirection)
            .onChange(of: nativeUIState.isRTL) { isRTL in
                // UIKit hosting views read semanticContentAttribute for
                // chrome SwiftUI doesn't own (window-level gestures, edge
                // insets). Keep the key windows in sync reactively.
                for scene in UIApplication.shared.connectedScenes {
                    guard let windowScene = scene as? UIWindowScene else { continue }
                    for window in windowScene.windows {
                        let semantic: UISemanticContentAttribute = isRTL ? .forceRightToLeft : .forceLeftToRight
                        if window.semanticContentAttribute != semantic {
                            window.semanticContentAttribute = semantic
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

        let phpIni = """
        curl.cainfo="\(caPath)"
        openssl.cafile="\(caPath)"
        """

        let supportDir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        let path = supportDir.appendingPathComponent("php.ini")

        do {
            try FileManager.default.createDirectory(at: supportDir, withIntermediateDirectories: true)

            try phpIni.write(to: path, atomically: true, encoding: .utf8)
        } catch {
            print("Couldn't create php.ini")
        }

        return supportDir.appendingPathComponent("php.ini").path(percentEncoded: false)
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

        output = ""

        override_embed_module_output(pipe_php_output)

        createDatabase()

        return output
    }

    static func laravel(request: RequestData) -> String? {
        // Convert Swift strings to C strings
        let postDataC = strdup(request.data ?? "")
        let methodC = strdup(request.method)
        let uriC = strdup(request.uri)

        // Free the duplicated C strings
        defer {
            free(postDataC)
            free(methodC)
            free(uriC)
        }

        output = ""

        override_embed_module_output(pipe_php_output)

        var argv: [UnsafeMutablePointer<CChar>?] = [
            strdup("php")
        ]

        let argc = Int32(argv.count)

        let appPath = AppUpdateManager.shared.getAppPath()
        let phpFilePath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/native.php"

        print()
        print("=== FORWARDING REQUEST TO LARAVEL ===")
        print()

        var uri = request.uri
        if let query = request.query {
            uri += "?" + query
        }

        setenv("REMOTE_ADDR", "0.0.0.0", 1)
        setenv("REQUEST_URI", uri, 1)
        setenv("QUERY_STRING", request.query, 1);
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
                print("Failed to convert \(header) or its value to C string.")
                continue
            }

            // Set this as env so that it will get picked up in $_SERVER
            setenv(cKey, cValue, 1)
            envKeys.append(formattedKey)
        }

        // Equivalent to PHP_EMBED_START_BLOCK
        argv.withUnsafeMutableBufferPointer { bufferPtr in
            php_embed_init(argc, bufferPtr.baseAddress)

            initialize_php_with_request(postDataC, methodC, uriC)

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

        // Free argv strings
        argv.forEach { free($0) }

        print()
        print("=== LARAVEL FINISHED ===")
        print()

        return output
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

        setenv("NATIVEPHP_PLATFORM", "ios", 1)
        setenv("NATIVEPHP_TEMPDIR", tempDir, 1)
        setenv("LARAVEL_STORAGE_PATH", storageDir, 1)
        setenv("VIEW_COMPILED_PATH", viewCacheDir, 1)
        setenv("DB_DATABASE", "\(databaseDir)/database.sqlite", 1)

        // Set APP_KEY from secure storage (generates on first run)
        if let appKey = getOrGenerateAppKey() {
            setenv("APP_KEY", appKey, 1)
        }
    }

    /// Retrieves APP_KEY from Keychain or generates a new one on first launch
    private func getOrGenerateAppKey() -> String? {
        let service = Bundle.main.bundleIdentifier ?? "com.nativephp.app"
        let account = "APP_KEY"

        // Try to retrieve existing APP_KEY from Keychain
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrAccount as String: account,
            kSecAttrService as String: service,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]

        var result: AnyObject?
        let status = SecItemCopyMatching(query as CFDictionary, &result)

        if status == errSecSuccess,
           let data = result as? Data,
           let existingKey = String(data: data, encoding: .utf8) {
            DebugLogger.shared.log("🔑 Retrieved existing APP_KEY from Keychain")
            return existingKey
        }

        // Generate new APP_KEY if not found
        DebugLogger.shared.log("🔑 Generating new APP_KEY for first launch")
        guard let newKey = generateAppKey() else {
            DebugLogger.shared.log("❌ Failed to generate APP_KEY")
            return nil
        }

        // Store in Keychain
        guard let keyData = newKey.data(using: .utf8) else {
            DebugLogger.shared.log("❌ Failed to encode APP_KEY")
            return nil
        }

        let addQuery: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrAccount as String: account,
            kSecAttrService as String: service,
            kSecValueData as String: keyData,
            kSecAttrAccessible as String: kSecAttrAccessibleWhenUnlockedThisDeviceOnly
        ]

        let addStatus = SecItemAdd(addQuery as CFDictionary, nil)

        if addStatus == errSecSuccess {
            DebugLogger.shared.log("✅ APP_KEY stored securely in Keychain")
            return newKey
        } else {
            DebugLogger.shared.log("❌ Failed to store APP_KEY in Keychain: \(addStatus)")
            return nil
        }
    }

    /// Generates a new Laravel-compatible APP_KEY (base64:...)
    private func generateAppKey() -> String? {
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
        print("Running `php artisan \(additionalArgs.joined())`...")

        output = ""

        override_embed_module_output(pipe_php_output)

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
