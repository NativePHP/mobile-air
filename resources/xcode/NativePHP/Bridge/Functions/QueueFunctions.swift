import Foundation

/// Functions related to background queue operations
/// Namespace: "Queue.*"
enum QueueFunctions {
    
    // MARK: - Queue.JobsAvailable
    
    /// Called by PHP when jobs are dispatched to the queue.
    /// Notifies the native queue coordinator to start processing.
    ///
    /// Parameters:
    ///   - pending: (required) int - Number of pending jobs
    ///   - queue: (optional) string - Queue name (default: "default")
    class JobsAvailable: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let pending = parameters["pending"] as? Int ?? 0
            let queue = parameters["queue"] as? String ?? "default"
            
            // Notify the queue coordinator
            NativeQueueCoordinator.shared.notifyJobsAvailable(count: pending, queue: queue)
            
            return ["acknowledged": true]
        }
    }
}
