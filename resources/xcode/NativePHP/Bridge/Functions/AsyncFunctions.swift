import Foundation

// MARK: - Async Task Function Namespace

/// Bridge functions for the async task lane (`AsyncTask::dispatch()`).
/// Namespace: "AsyncTask.*"
///
/// Two calls, both dumb couriers — neither interprets the task's work or result:
///   - Dispatch: the UI PHP context asks native to start a background run.
///   - Complete: the background PHP context reports the outcome, which native
///     relays into the UI runloop as a native event.
///
/// The task payload itself moves via a temp file on the shared filesystem
/// (`Native\Mobile\Support\AsyncTaskTransport`), so no payload crosses here.
///
/// Android twin: `bridge/functions/AsyncFunctions.kt`.
enum AsyncFunctions {

    // MARK: - AsyncTask.Dispatch

    /// Start a dispatched task on the async pool.
    /// Parameters:
    ///   - id: string — the dispatched task id (payload already spooled to disk)
    class Dispatch: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let id = parameters["id"] as? String else {
                return ["success": false, "error": "missing id"]
            }

            AsyncTaskExecutor.shared.dispatch(taskId: id)
            return ["success": true]
        }
    }

    // MARK: - AsyncTask.Complete

    /// Deliver a task's outcome back to the UI runloop.
    /// Parameters:
    ///   - event: string — the outcome event FQCN (AsyncTaskFinished / AsyncTaskFailed)
    ///   - payload: object — event data (always carries `id`)
    class Complete: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let event = parameters["event"] as? String else {
                return ["success": false, "error": "missing event"]
            }

            let payload = parameters["payload"] as? [String: Any] ?? [:]
            let payloadJson: String
            if let data = try? JSONSerialization.data(withJSONObject: payload),
               let json = String(data: data, encoding: .utf8) {
                payloadJson = json
            } else {
                payloadJson = "{}"
            }

            // Thread-safe: sendNativeEvent is the event producer and may be
            // called from any thread (here, an async slot's queue).
            NativeElementBridge.sendNativeEvent(eventName: event, payloadJson: payloadJson)
            return ["success": true]
        }
    }
}
