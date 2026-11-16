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

    /// A JS/JSON string literal that evaluates back to `value` verbatim — the
    /// value JSON-encoded, e.g. `a"b` → `"a\"b"`. Used to emit the event name
    /// and the request body into injected JS without a chance of breaking out.
    private static func jsonStringLiteral(_ value: String) -> String {
        let data = try! JSONSerialization.data(withJSONObject: [value])
        var literal = String(data: data, encoding: .utf8)!
        literal.removeFirst()
        literal.removeLast()
        return literal
    }

    /// Fire a `native-event` on the page (which `addNativeHelper` forwards to
    /// Livewire) and POST it to /_native/api/events for PHP listeners.
    @MainActor
    private static func dispatch(event rawEventName: String, payloadJson jsonString: String, to webView: WKWebView) {
        let event = jsonStringLiteral(rawEventName)

        // The exact body bytes PHP reads back as `request()->getContent()` and,
        // when enforcing, verifies the signature against. Built by
        // concatenation and posted verbatim — never rebuilt by `JSON.stringify`,
        // which could reorder keys or renumber values and break the match.
        // `event` is already the JSON-quoted class string; `jsonString` the
        // payload JSON.
        let body = "{\"event\":\(event),\"payload\":\(jsonString)}"

        // A JS string literal that evaluates back to `body` verbatim, so
        // `fetch` posts exactly these bytes.
        let bodyLiteral = jsonStringLiteral(body)

        // Sign on device. Under Jump the page talks to the dev-machine PHP,
        // a separate process that is not enforcing, so those events stay
        // unsigned and keep working.
        var signatureHeaders = ""
        if !JumpWebViewSession.shared.isActive, let signed = NativeEventSignature.sign(body: body) {
            signatureHeaders = """
            ,
                    '\(NativeEventSignature.nonceHeader)': '\(signed.nonce)',
                    '\(NativeEventSignature.timestampHeader)': '\(signed.timestamp)',
                    '\(NativeEventSignature.signatureHeader)': '\(signed.signature)'
            """
        }

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
                    'X-Requested-With': 'XMLHttpRequest'\(signatureHeaders)
                },
                body: \(bodyLiteral)
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
