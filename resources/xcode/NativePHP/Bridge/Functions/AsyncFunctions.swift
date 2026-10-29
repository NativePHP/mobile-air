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
/// JavaScript in a web view can make both calls too, through
/// `/_native/api/call`. So Dispatch takes only a UUID for an id, and posts a
/// timeout under one fixed name, and Complete answers only the async lane.
///
/// Android twin: `bridge/functions/AsyncFunctions.kt`.
enum AsyncFunctions {

    /// The event posted when a task outruns its deadline. It must equal
    /// `AsyncTaskFailed::class` on the PHP side.
    static let timeoutEvent = "Native\\Mobile\\Events\\Async\\AsyncTaskFailed"

    /// Whether a task id has the shape of the one PHP makes for a task with
    /// `Str::uuid()`: hex digits in groups of 8-4-4-4-12, split by hyphens.
    static func isTaskId(_ id: String) -> Bool {
        let bytes = Array(id.utf8)

        guard bytes.count == 36 else {
            return false
        }

        for (i, byte) in bytes.enumerated() {
            if i == 8 || i == 13 || i == 18 || i == 23 {
                guard byte == UInt8(ascii: "-") else { return false }
            } else {
                guard (byte >= UInt8(ascii: "0") && byte <= UInt8(ascii: "9"))
                    || (byte >= UInt8(ascii: "a") && byte <= UInt8(ascii: "f"))
                    || (byte >= UInt8(ascii: "A") && byte <= UInt8(ascii: "F")) else { return false }
            }
        }

        return true
    }

    // MARK: - AsyncTask.Dispatch

    /// Start a dispatched task on the async pool.
    /// Parameters:
    ///   - id: string — the dispatched task id (payload already spooled to disk)
    ///   - timeout: number (optional) — seconds before the task is treated as hung
    ///   - timeoutEvent: string (optional) — not read; a timeout always posts
    ///     `timeoutEvent` above. Older native projects read it, so PHP sends it.
    ///   - timeoutPayload: object (optional) — event data to post on timeout,
    ///     posted with this task's id whatever `id` it holds
    ///
    /// The id ends up spliced into PHP that the async lane evaluates (see
    /// `do_async_run` in PHP.c), so anything but a UUID is refused here.
    ///
    /// The `success` flag in the reply is load-bearing: PHP fails the dispatch
    /// (and fires `->failed()`) when it isn't true, so a task that never started
    /// can't leave the UI waiting on a result that isn't coming.
    class Dispatch: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let id = parameters["id"] as? String else {
                return ["success": false, "error": "missing id"]
            }

            guard AsyncFunctions.isTaskId(id) else {
                return ["success": false, "error": "invalid id"]
            }

            let timeout = (parameters["timeout"] as? NSNumber)?.intValue ?? 0

            var timeoutPayload = parameters["timeoutPayload"] as? [String: Any]
            timeoutPayload?["id"] = id

            let accepted = AsyncTaskExecutor.shared.dispatch(
                taskId: id,
                timeout: timeout,
                timeoutEvent: AsyncFunctions.timeoutEvent,
                timeoutPayloadJson: timeoutPayload.map(bridgeJsonString)
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
    ///
    /// Only a call from the thread of an async slot is taken. The event goes
    /// to the runloop of a native screen under the name the call gives, so
    /// a call from anywhere else, such as a page, would post any event.
    class Complete: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard AsyncTaskExecutor.onLaneThread else {
                return ["success": false, "error": "not called from the async lane"]
            }

            guard let event = parameters["event"] as? String else {
                return ["success": false, "error": "missing event"]
            }

            let payload = parameters["payload"] as? [String: Any] ?? [:]

            // Thread-safe: sendNativeEvent is the event producer and may be
            // called from any thread (here, an async slot's queue).
            NativeElementBridge.sendNativeEvent(eventName: event, payloadJson: bridgeJsonString(payload))
            return ["success": true]
        }
    }
}

/// Re-serialize a decoded bridge parameter back to a JSON object string.
/// It is shared with `EventFunctions` and prefixed, as plugin sources
/// are compiled into this module and may bring a `jsonString` too.
func bridgeJsonString(_ value: [String: Any]) -> String {
    guard let data = try? JSONSerialization.data(withJSONObject: value),
          let json = String(data: data, encoding: .utf8) else {
        return "{}"
    }

    return json
}
