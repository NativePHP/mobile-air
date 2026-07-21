import Foundation

@_silgen_name("webview_php_start")
private func _webview_php_start(_ bootstrapPath: UnsafePointer<CChar>) -> Int32

@_silgen_name("webview_php_request")
private func _webview_php_request(
    _ handle: Int32,
    _ method: UnsafePointer<CChar>,
    _ uri: UnsafePointer<CChar>,
    _ cookieHeader: UnsafePointer<CChar>,
    _ postData: UnsafePointer<CChar>,
    _ contentType: UnsafePointer<CChar>,
    _ scriptPath: UnsafePointer<CChar>
) -> UnsafeMutablePointer<CChar>?

@_silgen_name("webview_php_stop")
private func _webview_php_stop(_ handle: Int32)

/// A dedicated PHP context for one embedded php-mode webview.
///
/// The persistent runtime's serial queue is parked inside the native
/// screen's event-loop dispatch for the screen's whole lifetime, so it can
/// never answer php:// requests from an embedded webview. Each instance of
/// this class owns a separate thread + TSRM context in the C bridge
/// (`webview_php_start`), boots Laravel on it, serves that webview's
/// requests, and tears the whole context down in `release()` when the
/// webview leaves the view hierarchy.
///
/// All work is serialized on a private queue: requests naturally queue
/// behind the (asynchronous) boot, and `release()` behind in-flight
/// requests — no explicit state machine needed.
final class WebviewPHPRuntime {
    private let queue = DispatchQueue(label: "com.nativephp.webview-php", qos: .userInitiated)
    private var handle: Int32 = -1
    private var released = false

    init() {
        queue.async { [self] in
            let appPath = AppUpdateManager.shared.getAppPath()
            let bootstrap = appPath + "/vendor/nativephp/mobile/bootstrap/ios/persistent.php"
            handle = _webview_php_start(bootstrap)
            print("[NativePHP] webview runtime: boot \(handle >= 0 ? "OK (slot \(handle))" : "FAILED (\(handle))")")
        }
    }

    /// Dispatch a request on this webview's own PHP context. The completion
    /// receives the raw HTTP response (headers + body), matching the format
    /// `PHPSchemeHandler` already parses.
    func dispatch(request: RequestData, completion: @escaping (String) -> Void) {
        queue.async { [self] in
            guard !released, handle >= 0 else {
                completion("HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/plain\r\n\r\nWebview PHP runtime unavailable.")
                return
            }

            var uri = request.uri
            if let query = request.query, !query.isEmpty {
                uri += "?" + query
            }

            let appPath = AppUpdateManager.shared.getAppPath()
            let scriptPath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/native.php"
            let cookieHeader = request.headers["Cookie"] ?? ""
            let contentType = request.headers["Content-Type"] ?? request.headers["content-type"] ?? ""

            let start = CFAbsoluteTimeGetCurrent()
            NSLog("%@", "[NativePHP] [WEBVIEW:\(handle)] --> \(request.method) \(uri)")

            guard let resultPtr = _webview_php_request(
                handle, request.method, uri, cookieHeader,
                request.data ?? "", contentType, scriptPath
            ) else {
                completion("HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\n\r\nNull response from webview runtime.")
                return
            }

            let response = String(cString: resultPtr)
            free(UnsafeMutableRawPointer(mutating: resultPtr))

            let elapsed = (CFAbsoluteTimeGetCurrent() - start) * 1000
            let statusLine = response.prefix(while: { $0 != "\r" && $0 != "\n" })
            NSLog("%@", "[NativePHP] [WEBVIEW:\(handle)] <-- \(statusLine) (\(String(format: "%.1f", elapsed))ms)")

            completion(response)
        }
    }

    /// Stop this webview's PHP thread and free its slot. Queued after any
    /// in-flight request; further dispatches answer 503. Idempotent.
    func release() {
        queue.async { [self] in
            guard !released else { return }
            released = true
            if handle >= 0 {
                _webview_php_stop(handle)
                print("[NativePHP] webview runtime: slot \(handle) released")
                handle = -1
            }
        }
    }

    deinit {
        release()
    }
}
