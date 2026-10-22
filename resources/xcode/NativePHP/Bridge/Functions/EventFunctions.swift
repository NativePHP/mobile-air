import Foundation

// MARK: - Event Function Namespace

/// Bridge functions for PHP events marked `BroadcastsGlobally`.
/// Namespace: "Event.*"
///
/// One call, a courier like `AsyncTask.Complete`. PHP fires such an event
/// on a thread that is not running the native screens (a queue worker,
/// an async slot, an embedded web view) and native carries it over.
///
/// The event travels in the payload, serialized and signed by PHP. Native
/// neither reads nor checks it. The main PHP thread first verifies the
/// signature when the frame arrives and only then unserializes it.
///
/// Android twin: `bridge/functions/EventFunctions.kt`.
enum EventFunctions {

    /// The one name a frame is ever posted under. It must equal
    /// `GlobalEventTransport::EVENT_NAME` on the PHP side.
    static let globalEventName = "__global_event"

    // MARK: - Event.Broadcast

    /// Post a globally broadcast event into the element event queue.
    /// Parameters:
    ///   - payload: object, posted as it is. PHP sends the serialized event
    ///     as base64 `data` and its signature as hex `sig`. Native neither
    ///     reads nor checks them.
    /// Returns:
    ///   - success: boolean, false only when `payload` is not an object
    ///   - delivered: boolean, true when the event is in the queue of a live
    ///     native screen session. False means there is no such session, and
    ///     the event was dropped. A full queue does not answer false: it
    ///     takes the event and drops its oldest frame for it. So PHP asks
    ///     the queue for its counters before it calls, and keeps the event
    ///     when there is no room for it.
    ///
    /// The name is fixed and any `event` parameter is ignored, so nothing can
    /// be posted through here that the main PHP thread will accept without
    /// a valid signature. JavaScript in a web view can call this, too.
    ///
    /// The `delivered` flag in the reply is load-bearing: when it is true PHP
    /// runs no listeners in the firing interpreter, so an event which was
    /// dropped while reported as delivered would be lost for everyone.
    class Broadcast: BridgeFunction {
        private let post: (_ event: String, _ payloadJson: String) -> Bool

        /// The post is injected, so tests can run this without booting any PHP.
        /// Post to the element queue alone. `LaravelBridge.shared.send` also
        /// reaches the web view, which then loops the event back to PHP.
        init(
            post: @escaping (_ event: String, _ payloadJson: String) -> Bool = { event, payloadJson in
                NativeElementBridge.sendNativeEvent(eventName: event, payloadJson: payloadJson)
            }
        ) {
            self.post = post
        }

        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let payload = parameters["payload"] as? [String: Any] else {
                return ["success": false, "error": "missing payload"]
            }

            // No liveness check here: the extension answers queued or dropped
            // under its event mutex. That also makes this safe on the queue
            // worker, async slot and web view PHP threads that call it.
            let delivered = post(EventFunctions.globalEventName, bridgeJsonString(payload))

            return ["success": true, "delivered": delivered]
        }
    }
}
