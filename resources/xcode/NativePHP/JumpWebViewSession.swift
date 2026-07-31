import Foundation

/// PROTOTYPE — "render a remote WebView app over Jump."
///
/// When active, `PHPSchemeHandler` forwards `php://127.0.0.1` requests to the
/// remote Jump dev server (`host:port`) instead of running the local embedded
/// PHP. The shell's WebView keeps loading `php://127.0.0.1`, so there is NO
/// change to the rendering surface, no remote origin, and therefore no ATS /
/// nav-policy / bridge-origin work — the existing HTML render + JS bridge just
/// point at a different response source.
///
/// This is how a remote `Route::get()` (Livewire / classic WebView) app is
/// shown, alongside the native-ui `Element.*` streaming path used for
/// `Route::native` apps. v0 is triggered by a `jump://webview?host=&port=`
/// deep link; the discovery relay will start it automatically once it detects
/// a non-native-ui app.
final class JumpWebViewSession {
    static let shared = JumpWebViewSession()

    private let lock = NSLock()
    private var _host = ""
    private var _port = ""
    private var _active = false

    var host: String { lock.lock(); defer { lock.unlock() }; return _host }
    var port: String { lock.lock(); defer { lock.unlock() }; return _port }
    var isActive: Bool { lock.lock(); defer { lock.unlock() }; return _active }

    func start(host: String, port: String) {
        lock.lock()
        _host = host
        _port = port
        _active = true
        lock.unlock()
        DebugLogger.shared.log("🌐 JumpWebViewSession START \(host):\(port)")
    }

    func stop() {
        lock.lock()
        _active = false
        _host = ""
        _port = ""
        lock.unlock()
        DebugLogger.shared.log("🌐 JumpWebViewSession STOP")
    }
}
