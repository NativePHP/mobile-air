import Foundation

/// Runs a native session: the one persistent-runtime dispatch of a native
/// route that drives the native UI until the app leaves it.
///
/// PHP can die part-way through that dispatch. A fatal error (most often the
/// memory limit, since the session is a single request that lives as long as
/// the app) bails out of the runloop, and the dispatch returns early with
/// whatever PHP printed instead of an HTTP response. Nothing drives the screen
/// after that: it stays on its last frame, its polls stop, and every later
/// event goes unanswered.
///
/// So when that happens the runtime is rebooted and the start route
/// dispatched again. Rebooting matters: after a fatal error PHP keeps the dead
/// session's memory, marks every object as destructed and stops collecting
/// cycles, so the same runtime would only die again sooner. A session that
/// keeps dying is given up on after a few restarts, so a broken start route
/// can't loop forever.
enum NativeSession {
    private static let maxRestarts = 3
    private static let restartWindow: TimeInterval = 60

    private static let lock = NSLock()
    private static var restarts: [Date] = []

    /// Where the restarted session finds what PHP printed when it died, to
    /// log it. PHP can't log a fatal error itself here: Laravel reports those
    /// from a shutdown function, and the persistent runtime's request never
    /// shuts down. Must match `NativeRouter::CRASH_OUTPUT_PATH`.
    static let crashOutputPath = "storage/framework/.native_session_crash"

    /// Dispatch `uri` as a native session and block until it ends, restarting
    /// it if PHP dies part-way. Returns the session's exit response. Call it
    /// off the main thread.
    static func run(uri: String) -> String {
        var uri = uri

        while true {
            let request = RequestData(method: "GET", uri: uri, data: nil, query: nil, headers: [:])
            let response = PersistentPHPRuntime.shared.dispatchData(request: request)

            // Every session that ends on its own answers with an HTTP response:
            // EXIT_WEB's redirect, hot reload's 204, or an empty 200.
            if PHPRawResponse(response) != nil {
                return String(decoding: response, as: UTF8.self)
            }

            let output = String(decoding: response.prefix(8 * 1024), as: UTF8.self)
                .trimmingCharacters(in: .whitespacesAndNewlines)
            NSLog("[NativeSession] PHP stopped part-way through the native session at \(uri): \(output)")

            guard mayRestart() else {
                NSLog("[NativeSession] The native session died \(maxRestarts) times in \(Int(restartWindow))s, not restarting it again")
                return String(decoding: response, as: UTF8.self)
            }

            keepCrashOutput(output)
            rebootRuntime()

            uri = NativePHPApp.getStartURL()
            NSLog("[NativeSession] Restarting the native session at \(uri)")
        }
    }

    private static func mayRestart() -> Bool {
        lock.lock()
        defer { lock.unlock() }

        let now = Date()
        restarts = restarts.filter { now.timeIntervalSince($0) < restartWindow }
        guard restarts.count < maxRestarts else { return false }
        restarts.append(now)

        return true
    }

    private static func keepCrashOutput(_ output: String) {
        guard let supportDir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first else { return }

        try? output.write(to: supportDir.appendingPathComponent(crashOutputPath), atomically: true, encoding: .utf8)
    }

    /// The same reboot hot reload does, keeping the dead session's last frame
    /// on screen until the new session publishes its first.
    private static func rebootRuntime() {
        NativeElementBridge.stopWatching(preserveTree: true)
        NativeElementBridge.unregisterRegion()

        // php_embed_shutdown() frees the Zend module state the queue worker
        // and the async lane run on, so they stop first.
        PHPQueueWorker.shared.stopAndWait()
        AsyncTaskExecutor.shared.stop()
        let booted = PersistentPHPRuntime.shared.reboot()
        PHPQueueWorker.shared.start()
        AsyncTaskExecutor.shared.start()

        NSLog("[NativeSession] Runtime rebooted, booted=\(booted)")
    }
}
