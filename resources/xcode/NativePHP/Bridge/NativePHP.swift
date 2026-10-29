import Foundation
import WebKit

final class LaravelBridge {
    static let shared = LaravelBridge()

    /// Every web view showing app pages: the shell's own and each php-mode
    /// `<native:webview>`. `WebView.addNativeHelper` registers them. Main
    /// thread only.
    private let pageWebViews = NSHashTable<WKWebView>.weakObjects()

    func registerPageWebView(_ webView: WKWebView) {
        pageWebViews.add(webView)
    }

    /// The local delivery channel for device-API events: the element queue
    /// for native screens, and every page web view for `window.Native` and
    /// `#[OnNative]` listeners.
    ///
    /// Wrappers around `send` (e.g. Jump's remote-session fork) must fall
    /// back to THIS, read at dispatch time, rather than capture `send`.
    var localSend: (_ event: String, _ payload: [String: Any?]) -> Void = { event, payload in
        let dict = payload.reduce(into: [String: Any]()) { $0[$1.key] = $1.value ?? NSNull() }
        let json = (try? JSONSerialization.data(withJSONObject: dict))
            .flatMap { String(data: $0, encoding: .utf8) } ?? "{}"
        NativeElementBridge.sendNativeEvent(eventName: event, payloadJson: json)

        Task { @MainActor in
            for webView in LaravelBridge.shared.pageWebViews.allObjects {
                LaravelBridge.dispatch(event: event, payloadJson: json, to: webView)
            }
        }
    }

    /// Public dispatch every plugin calls. Defaults to routing through
    /// `localSend` dynamically — never nil, so native-direct boots deliver
    /// events from birth (ServerFound / CodeScanned were silently dropped
    /// when this was only assigned at WebView creation).
    var send: ((_ event: String, _ payload: [String: Any?]) -> Void)? = { event, payload in
        LaravelBridge.shared.localSend(event, payload)
    }

    /// Fire a `native-event` on the page (which `addNativeHelper` forwards to
    /// Livewire) and POST it to /_native/api/events for PHP listeners.
    @MainActor
    private static func dispatch(event rawEventName: String, payloadJson jsonString: String, to webView: WKWebView) {
        let event: String = {
            let data = try! JSONSerialization.data(withJSONObject: [rawEventName])
            var literal = String(data: data, encoding: .utf8)!
            literal.removeFirst()
            literal.removeLast()
            return literal
        }()

        let js = """
        (function() {
            const event = new CustomEvent(
                "native-event",
                {
                    detail: {
                        event: \(event),
                        payload: \(jsonString),
                    },
                }
            );
            document.dispatchEvent(event);

            fetch('/_native/api/events', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    event: \(event),
                    payload: \(jsonString),
                })
            }).then(response => response.json())
              .then(data => console.log("API Event Dispatch Success:", JSON.stringify(data, null, 2)))
              .catch(error => console.error("API Event Dispatch Error:", error));
        })();
        """

        webView.evaluateJavaScript(js) { result, error in
            if let error = error {
                print("JavaScript injection error injecting event '\(event)': \(error)")
            } else {
                print("JavaScript event '\(event)' dispatched.")
            }
        }
    }
}
