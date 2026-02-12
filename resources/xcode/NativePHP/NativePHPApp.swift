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
    @Environment(\.scenePhase) private var scenePhase

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
        DebugLogger.shared.log("📱 Deferred initialization starting")

        // 1. Initialize PHP environment
        DebugLogger.shared.log("📱 Deferred init: preparing PHP environment")
        _ = preparePhpEnvironment()

        // 2. Ensure app is extracted from bundle if needed
        DebugLogger.shared.log("📱 Deferred init: checking for app")
        AppUpdateManager.shared.ensureAppExists()

        // 3. Create storage symlink
        DebugLogger.shared.log("📱 Deferred init: creating storage symlink")
        createStorageLink()

        // 4. Execute plugin initialization callbacks (on main thread)
        DispatchQueue.main.async {
            NativePHPPluginRegistry.shared.executeOnAppLaunch()
        }

        // 5. Start hot reload server for development
        #if DEBUG
        HotReloadServer.shared.start()
        #endif

        // 6. Check for OTA updates (after everything is set up)
        DebugLogger.shared.log("📱 Deferred init: checking for OTA update")
        AppUpdateManager.shared.checkForUpdates()

        // 7. Start the queue coordinator (PHP is now ready)
        DebugLogger.shared.log("📱 Deferred init: starting queue coordinator")
        NativeQueueCoordinator.shared.start()

        DebugLogger.shared.log("📱 Deferred initialization completed")

        // Notify that PHP is ready and allow WebView to be rendered
        // WebView will call markInitialized() when first page loads to dismiss splash
        DispatchQueue.main.async {
            DeepLinkRouter.shared.markPhpReady()
            AppState.shared.markReadyToLoad()
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
            .onChange(of: scenePhase) { oldPhase, newPhase in
                handleScenePhaseChange(from: oldPhase, to: newPhase)
            }
        }
    }

    /// Handle app lifecycle transitions for queue processing handoff.
    ///
    /// When the app enters background, we pause foreground processing and schedule
    /// a background task. When returning to foreground, we cancel any pending
    /// background task and resume foreground processing.
    private func handleScenePhaseChange(from oldPhase: ScenePhase, to newPhase: ScenePhase) {
        switch newPhase {
        case .active:
            DebugLogger.shared.log("📱 App became active - resuming foreground queue processing")
            BackgroundQueueWorker.shared.cancelPendingTask()
            NativeQueueCoordinator.shared.resume()

        case .background:
            DebugLogger.shared.log("📱 App entered background - scheduling background queue processing")
            NativeQueueCoordinator.shared.pause()
            BackgroundQueueWorker.shared.scheduleIfNeeded()

        case .inactive:
            // Transitional state, no action needed
            break

        @unknown default:
            break
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
        setenv("QUERY_STRING", request.query ?? "", 1);
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
