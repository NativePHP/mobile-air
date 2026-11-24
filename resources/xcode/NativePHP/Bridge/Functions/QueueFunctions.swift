import Foundation

/// Bridge functions for the background queue worker (`PHPQueueWorker`).
/// Namespace: "Queue.*"
///
/// Android twin: `bridge/functions/QueueFunctions.kt`.
enum QueueFunctions {

    /// Start the background queue worker on demand (in lazy mode) or interrupt
    /// its idle sleep immediately when PHP pushes a job onto the queue.
    ///
    /// Takes no parameters and runs no caller-supplied input; no-ops when the
    /// queue worker is disabled in `bundle_meta.json`.
    class Wake: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let woken = PHPQueueWorker.shared.wake()
            return ["success": true, "woken": woken]
        }
    }
}
