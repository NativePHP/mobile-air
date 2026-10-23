import Foundation

// C functions declared in PHP.h / PHP.c — linked directly, bypassing the Bridge module
// which has issues with umbrella parsing of php_embed.h transitive includes.
@_silgen_name("persistent_php_boot")
private func _persistent_php_boot(_ bootstrapPath: UnsafePointer<CChar>?) -> Int32

// Binary-safe dispatch: the body and header block go in as (pointer, length),
// and the raw HTTP response comes back as a malloc'd buffer plus its length
// (size_t is Int on Apple's 64-bit platforms).
@_silgen_name("persistent_php_dispatch_bytes")
private func _persistent_php_dispatch_bytes(
    _ method: UnsafePointer<CChar>?,
    _ uri: UnsafePointer<CChar>?,
    _ body: UnsafeRawPointer?,
    _ bodyLen: Int,
    _ contentType: UnsafePointer<CChar>?,
    _ cookieHeader: UnsafePointer<CChar>?,
    _ headers: UnsafeRawPointer?,
    _ headersLen: Int,
    _ scriptPath: UnsafePointer<CChar>?,
    _ outLen: UnsafeMutablePointer<Int>
) -> UnsafeMutablePointer<CChar>?

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

        // Re-open the window shutdown() closed, so webview contexts
        // suspended for this reboot can boot again. No-op on a cold launch.
        WebviewPHPRuntime.resumeAfterRuntimeReboot()

        return isBooted
    }

    /// Re-boot the persistent runtime (shutdown then boot).
    func reboot() -> Bool {
        shutdown()
        return boot()
    }


    /// Dispatch a web request through the persistent runtime.
    /// Returns the raw HTTP response (headers + body) as text. Kept for
    /// callers that only read the status line and headers; a binary body is
    /// decoded lossily. Use `dispatchData(request:)` for the exact bytes.
    func dispatch(request: RequestData) -> String {
        String(decoding: dispatchData(request: request), as: UTF8.self)
    }

    /// Dispatch a web request through the persistent runtime.
    /// Returns the raw HTTP response (headers + body) byte for byte.
    /// Blocks until the C worker thread completes the request.
    func dispatchData(request: RequestData) -> Data {
        // Detect stale state: Swift thinks we're booted but C layer disagrees
        if isBooted && _persistent_php_is_booted() == 0 {
            print("PersistentPHPRuntime: stale isBooted detected, attempting re-boot")
            isBooted = false
            let rebooted = boot()
            if !rebooted {
                print("PersistentPHPRuntime: re-boot failed, falling back to error response")
                return Data("HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nPersistent runtime re-boot failed.".utf8)
            }
        }

        guard isBooted else {
            return Data("HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nPersistent runtime not booted.".utf8)
        }

        var uri = request.uri
        if let query = request.query, !query.isEmpty {
            uri += "?" + query
        }

        let appPath = AppUpdateManager.shared.getAppPath()
        let scriptPath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/native.php"

        // Every request header reaches PHP through the header block.
        // BridgeDispatcher::handle() no longer reads HTTP_* from the
        // environment, so nothing is setenv()'d per request any more.
        let cookieHeader = request.header("Cookie") ?? ""
        let contentType = request.header("Content-Type") ?? ""
        let headerBlock = request.headerBlock
        let body = request.bodyBytes ?? Data()

        // This blocks until the C worker thread completes dispatch. The body
        // and header pointers are only borrowed for the call: C copies the
        // body into php://input before it returns.
        var outLen = 0
        let resultPtr = body.withUnsafeBytes { bodyBuffer in
            headerBlock.withUnsafeBytes { headerBuffer in
                _persistent_php_dispatch_bytes(
                    request.method,
                    uri,
                    bodyBuffer.baseAddress,
                    bodyBuffer.count,
                    contentType,
                    cookieHeader,
                    headerBuffer.baseAddress,
                    headerBuffer.count,
                    scriptPath,
                    &outLen
                )
            }
        }

        guard let resultPtr else {
            return Data("HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nNull response from persistent dispatch.".utf8)
        }

        // Take ownership of C's buffer without copying it.
        return Data(bytesNoCopy: resultPtr, count: outLen, deallocator: .free)
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
        // Embedded php-mode webviews each own a PHP context on their own
        // thread, built on the process-wide Zend module state that
        // php_embed_shutdown() is about to destroy. Take them down first or
        // they are left dereferencing freed memory — same hazard as the
        // queue worker, and why a hot reload with a php webview on screen
        // crashed. They re-boot on their next request once boot() reopens
        // the window.
        WebviewPHPRuntime.suspendAllForRuntimeReboot()

        _persistent_php_shutdown()
        isBooted = false
    }

    // MARK: - Helpers

    private func createPhpIni(caPath: String) -> String {
        let supportDir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        let iniPath = supportDir.appendingPathComponent("php.ini")

        // upload_max_filesize and post_max_size match the bridge's 16MB
        // response cap instead of PHP's 2MB/8MB defaults; BridgeDispatcher
        // applies them to request bodies the way PHP does. They go here, not
        // in the embed SAPI's ini_entries: php_embed_init() replaces those.
        let phpIni = """
        curl.cainfo="\(caPath)"
        openssl.cafile="\(caPath)"
        upload_max_filesize=16M
        post_max_size=16M
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
