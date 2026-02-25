import Foundation
import WebKit

/**
 * JavaScript Bridge Message Handler for iOS
 */
class JavaScriptBridgeHandler: NSObject, WKScriptMessageHandler {

    weak var webView: WKWebView?

    init(webView: WKWebView) {
        self.webView = webView
        super.init()
    }

    func userContentController(_ userContentController: WKUserContentController,
                              didReceive message: WKScriptMessage) {
        guard message.name == "nativeBridge" else { return }

        guard let body = message.body as? [String: Any],
              let callId = body["callId"] as? Int,
              let method = body["method"] as? String else {
            print("❌ JavaScriptBridge: Invalid message format")
            return
        }

        let parameters = body["parameters"] as? [String: Any] ?? [:]

        print("🚀 JavaScriptBridge: Calling '\(method)' (callId: \(callId))")

        DispatchQueue.global(qos: .userInitiated).async { [weak self] in
            self?.executeNativeCall(callId: callId, method: method, parameters: parameters)
        }
    }

    private func executeNativeCall(callId: Int, method: String, parameters: [String: Any]) {
        guard BridgeFunctionRegistry.shared.exists(method) else {
            sendResultToJavaScript(callId: callId, result: [
                "status": "error",
                "code": "FUNCTION_NOT_FOUND",
                "message": "Function '\(method)' not found"
            ])
            return
        }

        guard let function = BridgeFunctionRegistry.shared.get(method) else {
            sendResultToJavaScript(callId: callId, result: [
                "status": "error",
                "code": "FUNCTION_NOT_FOUND",
                "message": "Function '\(method)' disappeared"
            ])
            return
        }

        do {
            let result = try function.execute(parameters: parameters)
            let response = BridgeResponse.success(data: result)
            print("✅ JavaScriptBridge: '\(method)' succeeded")
            sendResultToJavaScript(callId: callId, result: response)
        } catch let error as BridgeError {
            print("⚠️ JavaScriptBridge: '\(method)' failed: \(error.message)")
            let response = BridgeResponse.error(from: error)
            sendResultToJavaScript(callId: callId, result: response)
        } catch {
            print("❌ JavaScriptBridge: '\(method)' unexpected error")
            let response = BridgeResponse.error(
                code: "UNKNOWN_ERROR",
                message: "Unexpected error: \(error.localizedDescription)"
            )
            sendResultToJavaScript(callId: callId, result: response)
        }
    }

    private func sendResultToJavaScript(callId: Int, result: [String: Any]) {
        let js = "window.NativePHP._resolveCall(callId, result);"
        let arguments: [String: Any] = ["callId": callId, "result": result]

        DispatchQueue.main.async { [weak self] in
            self?.webView?.callAsyncJavaScript(
                js,
                arguments: arguments,
                in: nil,
                contentWorld: .page
            ) { _ in }
        }
    }
}
