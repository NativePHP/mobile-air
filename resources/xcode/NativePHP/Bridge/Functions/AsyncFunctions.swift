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
    ///   - timeout: number (optional) — seconds before the task is treated as hung
    ///   - timeoutEvent: string (optional) — event FQCN to post on timeout
    ///   - timeoutPayload: object (optional) — event data to post on timeout
    ///
    /// The `success` flag in the reply is load-bearing: PHP fails the dispatch
    /// (and fires `->failed()`) when it isn't true, so a task that never started
    /// can't leave the UI waiting on a result that isn't coming.
    class Dispatch: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let id = parameters["id"] as? String else {
                return ["success": false, "error": "missing id"]
            }

            let timeout = (parameters["timeout"] as? NSNumber)?.intValue ?? 0
            let timeoutEvent = parameters["timeoutEvent"] as? String
            let timeoutPayload = (parameters["timeoutPayload"] as? [String: Any]).map(jsonString)

            let accepted = AsyncTaskExecutor.shared.dispatch(
                taskId: id,
                timeout: timeout,
                timeoutEvent: timeoutEvent,
                timeoutPayloadJson: timeoutPayload
            )

            guard accepted else {
                return ["success": false, "error": "task not accepted"]
            }

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

            // Thread-safe: sendNativeEvent is the event producer and may be
            // called from any thread (here, an async slot's queue).
            NativeElementBridge.sendNativeEvent(eventName: event, payloadJson: jsonString(payload))
            return ["success": true]
        }
    }
}

/// Re-serialize a decoded bridge parameter back to a JSON object string.
private func jsonString(_ value: [String: Any]) -> String {
    guard let data = try? JSONSerialization.data(withJSONObject: value),
          let json = String(data: data, encoding: .utf8) else {
        return "{}"
    }

    return json
}
