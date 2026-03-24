import Foundation

// C functions declared in PHP.h / PHP.c — linked directly, bypassing the Bridge module
// which has issues with umbrella parsing of php_embed.h transitive includes.
@_silgen_name("persistent_php_boot")
private func _persistent_php_boot(_ bootstrapPath: UnsafePointer<CChar>?) -> Int32

@_silgen_name("persistent_php_dispatch")
private func _persistent_php_dispatch(
    _ method: UnsafePointer<CChar>?,
    _ uri: UnsafePointer<CChar>?,
    _ postData: UnsafePointer<CChar>?,
    _ scriptPath: UnsafePointer<CChar>?,
    _ cookieHeader: UnsafePointer<CChar>?,
    _ contentType: UnsafePointer<CChar>?
) -> UnsafePointer<CChar>?

@_silgen_name("persistent_php_artisan")
private func _persistent_php_artisan(_ command: UnsafePointer<CChar>?) -> UnsafePointer<CChar>?

@_silgen_name("persistent_php_shutdown")
private func _persistent_php_shutdown()

@_silgen_name("persistent_php_is_booted")
private func _persistent_php_is_booted() -> Int32

@_silgen_name("persistent_php_boot_error")
private func _persistent_php_boot_error() -> UnsafePointer<CChar>

/// Persistent PHP Runtime for iOS.
/// Boots the PHP interpreter once and dispatches requests via zend_eval_string().
/// Equivalent to Android's PHPBridge persistent mode.
///
/// The C layer manages a dedicated pthread for all PHP work, guaranteeing
/// TSRM thread-local storage is always valid. Swift callers can call from
/// any thread — the C functions block until the PHP worker thread completes.
final class PersistentPHPRuntime {
    static let shared = PersistentPHPRuntime()

    private(set) var isBooted = false

    /// Serial queue for dispatch calls from WebView (prevents concurrent requests)
    private let dispatchQueue = DispatchQueue(label: "com.nativephp.persistent-php", qos: .userInitiated)

    private init() {}

    /// Execute a block on the dispatch queue asynchronously.
    /// The block will call dispatch() which internally routes to the C worker thread.
    func executeOnPHPThreadAsync(_ block: @escaping () -> Void) {
        dispatchQueue.async(execute: block)
    }

    /// Boot the persistent runtime. Call once during app initialization.
    /// This boots PHP, loads Composer, and boots the Laravel kernel.
    /// Blocks until boot is complete (work runs on dedicated C pthread).
    func boot() -> Bool {
        let appPath = AppUpdateManager.shared.getAppPath()

        // Set environment variables before PHP boots (env vars are process-wide)
        let storageDir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        let tempDir = FileManager.default.temporaryDirectory.path
        let databaseDir = storageDir.appendingPathComponent("database").path

        setenv("LARAVEL_STORAGE_PATH", storageDir.appendingPathComponent("storage").path, 1)
        setenv("VIEW_COMPILED_PATH", storageDir.appendingPathComponent("storage/framework/views").path, 1)
        setenv("DB_DATABASE", "\(databaseDir)/database.sqlite", 1)
        setenv("NATIVEPHP_TEMPDIR", tempDir, 1)
        setenv("NATIVEPHP_PLATFORM", "ios", 1)
        setenv("REMOTE_ADDR", "0.0.0.0", 1)

        // Composer autoloader and bootstrap paths (used by persistent.php)
        setenv("COMPOSER_AUTOLOADER_PATH", appPath + "/vendor/autoload.php", 1)
        setenv("LARAVEL_BOOTSTRAP_PATH", appPath + "/bootstrap", 1)

        // PHP INI
        let caPath = Bundle.main.path(forResource: "cacert", ofType: "pem") ?? ""
        setenv("PHPRC", createPhpIni(caPath: caPath), 1)

        // APP_KEY from Keychain
        if let appKey = getAppKey() {
            setenv("APP_KEY", appKey, 1)
        }

        let bootstrapPath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/persistent.php"

        print("PersistentPHPRuntime: booting with \(bootstrapPath)")

        // This blocks until the C worker thread completes boot
        let result = _persistent_php_boot(bootstrapPath)

        if result == 0 {
            isBooted = true
            print("PersistentPHPRuntime: boot succeeded")
        } else {
            let bootError = String(cString: _persistent_php_boot_error())
            print("PersistentPHPRuntime: boot FAILED (\(result)) error: \(bootError)")
        }

        return isBooted
    }

    /// Re-boot the persistent runtime (shutdown then boot).
    func reboot() -> Bool {
        shutdown()
        return boot()
    }


    /// Dispatch a web request through the persistent runtime.
    /// Returns the raw HTTP response (headers + body).
    /// Blocks until the C worker thread completes the request.
    func dispatch(request: RequestData) -> String {
        // Detect stale state: Swift thinks we're booted but C layer disagrees
        if isBooted && _persistent_php_is_booted() == 0 {
            print("PersistentPHPRuntime: stale isBooted detected, attempting re-boot")
            isBooted = false
            let rebooted = boot()
            if !rebooted {
                print("PersistentPHPRuntime: re-boot failed, falling back to error response")
                return "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nPersistent runtime re-boot failed."
            }
        }

        guard isBooted else {
            return "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nPersistent runtime not booted."
        }

        var uri = request.uri
        if let query = request.query, !query.isEmpty {
            uri += "?" + query
        }

        let appPath = AppUpdateManager.shared.getAppPath()
        let scriptPath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/native.php"

        // Set HTTP headers as env vars (like Android does)
        var envKeys: [String] = []
        for (header, value) in request.headers {
            let formattedKey = "HTTP_" + header
                .replacingOccurrences(of: "-", with: "_")
                .uppercased()
            setenv(formattedKey, value, 1)
            envKeys.append(formattedKey)
        }

        let cookieHeader = request.headers["Cookie"] ?? ""
        let contentType = request.headers["Content-Type"] ?? request.headers["content-type"] ?? ""

        // This blocks until the C worker thread completes dispatch
        let resultPtr = _persistent_php_dispatch(
            request.method,
            uri,
            request.data,
            scriptPath,
            cookieHeader,
            contentType
        )

        // Clean up HTTP header env vars
        for key in envKeys {
            unsetenv(key)
        }

        guard let resultPtr else {
            return "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nNull response from persistent dispatch."
        }

        let result = String(cString: resultPtr)
        free(UnsafeMutableRawPointer(mutating: resultPtr))
        return result
    }

    /// Run an artisan command through the persistent runtime.
    /// Blocks until the C worker thread completes the command.
    func artisan(command: String) -> String {
        guard isBooted else { return "Persistent runtime not booted." }

        guard let resultPtr = _persistent_php_artisan(command) else {
            return ""
        }

        let result = String(cString: resultPtr)
        free(UnsafeMutableRawPointer(mutating: resultPtr))
        return result
    }

    /// Shutdown the persistent runtime.
    func shutdown() {
        _persistent_php_shutdown()
        isBooted = false
    }

    // MARK: - Helpers

    private func createPhpIni(caPath: String) -> String {
        let supportDir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        let iniPath = supportDir.appendingPathComponent("php.ini")

        let phpIni = """
        curl.cainfo="\(caPath)"
        openssl.cafile="\(caPath)"
        """

        try? FileManager.default.createDirectory(at: supportDir, withIntermediateDirectories: true)
        try? phpIni.write(to: iniPath, atomically: true, encoding: .utf8)

        return iniPath.path(percentEncoded: false)
    }

    private func getAppKey() -> String? {
        let service = Bundle.main.bundleIdentifier ?? "com.nativephp.app"
        let account = "APP_KEY"
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrAccount as String: account,
            kSecAttrService as String: service,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne
        ]
        var result: AnyObject?
        let status = SecItemCopyMatching(query as CFDictionary, &result)
        if status == errSecSuccess, let data = result as? Data {
            return String(data: data, encoding: .utf8)
        }
        return nil
    }
}
