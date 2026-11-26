import Foundation

/// Debug-build trace log: prints each message and appends it to
/// Application Support/nativephp_debug.log. Release builds compile `log` to
/// nothing and never build the message string. Use NativeLog.error for
/// anything a release build should keep.
class DebugLogger {
    static let shared = DebugLogger()

    private let logFile: URL

    #if DEBUG
    private let queue = DispatchQueue(label: "com.nativephp.debuglogger", qos: .utility)
    private var handle: FileHandle?
    private let formatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss.SSS"
        return formatter
    }()
    #endif

    private init() {
        let appSupportPath = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        logFile = appSupportPath.appendingPathComponent("nativephp_debug.log")

        #if DEBUG
        try? FileManager.default.createDirectory(at: appSupportPath, withIntermediateDirectories: true)
        log("=== NEW APP SESSION ===")
        #endif
    }

    @inline(__always)
    func log(_ message: @autoclosure () -> String) {
        #if DEBUG
        let text = message()
        let now = Date()
        print("🐛 \(text)")

        // File writes happen off the caller's thread, through one open handle.
        queue.async { [self] in
            let entry = "[\(formatter.string(from: now))] \(text)\n"
            guard let data = entry.data(using: .utf8) else { return }

            if handle == nil {
                if !FileManager.default.fileExists(atPath: logFile.path) {
                    FileManager.default.createFile(atPath: logFile.path, contents: nil)
                }
                handle = try? FileHandle(forWritingTo: logFile)
                _ = try? handle?.seekToEnd()
            }
            try? handle?.write(contentsOf: data)
        }
        #endif
    }

    func getLogPath() -> String {
        return logFile.path
    }

    func clearLog() {
        #if DEBUG
        queue.sync {
            try? handle?.close()
            handle = nil
            try? FileManager.default.removeItem(at: logFile)
        }
        log("=== LOG CLEARED ===")
        #else
        try? FileManager.default.removeItem(at: logFile)
        #endif
    }
}

extension DateFormatter {
    func apply(_ closure: (DateFormatter) -> Void) -> DateFormatter {
        closure(self)
        return self
    }
}
