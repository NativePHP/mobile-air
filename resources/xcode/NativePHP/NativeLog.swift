import Foundation
import os

/// Logging for the NativePHP shell.
///
/// - `debug` is for chatter. It compiles to nothing in release builds, and the
///   message is an autoclosure, so release builds never even build the string.
/// - `perf` is for boot and timing markers that perf tooling counts. It logs in
///   debug builds, and in release builds only when the app was launched with
///   `NATIVEPHP_PERF_TIMING=1` in its environment (an Xcode scheme env var, or
///   `SIMCTL_CHILD_NATIVEPHP_PERF_TIMING=1 xcrun simctl launch ...`).
/// - `error` and `fault` always log, through the unified log, for failures a
///   developer needs to see from a user's device. Keep user data out of them.
enum NativeLog {
    static let logger = Logger(subsystem: "com.nativephp.mobile", category: "NativePHP")

    /// True in debug builds, or when NATIVEPHP_PERF_TIMING is set to a truthy value.
    static let perfTiming: Bool = {
        #if DEBUG
        return true
        #else
        let value = ProcessInfo.processInfo.environment["NATIVEPHP_PERF_TIMING"]?.lowercased()
        return value == "1" || value == "true" || value == "yes"
        #endif
    }()

    @inline(__always)
    static func debug(_ message: @autoclosure () -> String = "") {
        #if DEBUG
        NSLog("%@", message())
        #endif
    }

    @inline(__always)
    static func perf(_ message: @autoclosure () -> String) {
        guard perfTiming else { return }
        NSLog("%@", message())
    }

    static func error(_ message: @autoclosure () -> String) {
        let text = message()
        logger.error("\(text, privacy: .public)")
    }

    static func fault(_ message: @autoclosure () -> String) {
        let text = message()
        logger.fault("\(text, privacy: .public)")
    }
}
