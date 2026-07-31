import Foundation

final class LaravelBridge {
    static let shared = LaravelBridge()

    /// The CURRENT local delivery channel for device-API events. Starts as
    /// the element-queue forwarder (native-direct boots have no WKWebView);
    /// ContentView.makeUIView upgrades it to the coordinator closure (JS
    /// injection + element queue) when a WebView materializes.
    ///
    /// Wrappers around `send` (e.g. Jump's remote-session fork) must fall
    /// back to THIS, read at dispatch time — capturing `send` at install
    /// time goes stale when the WebView materializes mid-session, and the
    /// captured element-queue closure then swallows every event meant for
    /// the page (async device results in a forwarded WebView app).
    var localSend: (_ event: String, _ payload: [String: Any?]) -> Void = { event, payload in
        let dict = payload.reduce(into: [String: Any]()) { $0[$1.key] = $1.value ?? NSNull() }
        let json = (try? JSONSerialization.data(withJSONObject: dict))
            .flatMap { String(data: $0, encoding: .utf8) } ?? "{}"
        NativeElementBridge.sendNativeEvent(eventName: event, payloadJson: json)
    }

    /// Public dispatch every plugin calls. Defaults to routing through
    /// `localSend` dynamically — never nil, so native-direct boots deliver
    /// events from birth (ServerFound / CodeScanned were silently dropped
    /// when this was only assigned at WebView creation).
    var send: ((_ event: String, _ payload: [String: Any?]) -> Void)? = { event, payload in
        LaravelBridge.shared.localSend(event, payload)
    }
}
