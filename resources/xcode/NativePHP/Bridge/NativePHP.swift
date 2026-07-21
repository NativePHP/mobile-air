import Foundation

final class LaravelBridge {
    static let shared = LaravelBridge()

    /// Delivers device-API events (plugins, system events) into PHP. WebView
    /// boots overwrite this in makeUIView with the coordinator closure (JS
    /// injection + element queue). This default covers native-direct boots,
    /// which never construct a WKWebView — without it, every plugin dispatch
    /// (`LaravelBridge.shared.send?(...)`) is an optional call on nil and
    /// events like ServerFound / CodeScanned are silently dropped.
    var send: ((_ event: String, _ payload: [String: Any?]) -> Void)? = { event, payload in
        let dict = payload.reduce(into: [String: Any]()) { $0[$1.key] = $1.value ?? NSNull() }
        let json = (try? JSONSerialization.data(withJSONObject: dict))
            .flatMap { String(data: $0, encoding: .utf8) } ?? "{}"
        NativeElementBridge.sendNativeEvent(eventName: event, payloadJson: json)
    }
}
