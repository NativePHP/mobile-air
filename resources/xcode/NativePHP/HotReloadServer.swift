import Foundation
import Network
import WebKit

/// Owns the actual reload work triggered by `reloadWebViewNotification`
/// (hot reload connections in DEBUG, OTA updates via `AppUpdateManager`).
///
/// This observer used to live on the WebView's Coordinator, registered inside
/// `WebView.makeUIView` — but a native-direct boot withholds the WebView
/// entirely (and ContentView unmounts it whenever native UI is active), so
/// fully-native apps never registered an observer and reload triggers were
/// silently dropped. Registering here at app launch decouples reload handling
/// from the WebView lifecycle; the WebView, when one exists, is resolved
/// lazily through `SharedWebView.shared`.
class HotReloadCoordinator {
    static let shared = HotReloadCoordinator()

    private var reloadInProgress = false

    /// A trigger that arrived while a reload was in flight. The watcher emits
    /// one event per changed file (and one for the containing directory), so
    /// dropping the extras outright can discard the *last* file's trigger and
    /// leave the app running stale code until the next save.
    private var reloadPending = false
    private var activated = false

    private init() {}

    /// Register for reload notifications. Called once at app launch —
    /// unconditionally, not only in DEBUG, because `AppUpdateManager`
    /// posts the same notification after applying an OTA update.
    func activate() {
        guard !activated else { return }
        activated = true

        NotificationCenter.default.addObserver(
            self,
            selector: #selector(reload),
            name: .reloadWebViewNotification,
            object: nil
        )
    }

    /// Release the in-flight guard and, if a trigger arrived while we were
    /// busy, run exactly one more pass so the newest files are picked up.
    private func finishReload() {
        reloadInProgress = false

        guard reloadPending else { return }
        reloadPending = false

        DispatchQueue.main.async { [weak self] in
            self?.reload()
        }
    }

    @objc func reload() {
        // Guard against rapid-fire file change events (file + directory)
        // triggering concurrent reboots that race on php_embed_shutdown.
        guard !reloadInProgress else {
            reloadPending = true

            return
        }
        reloadInProgress = true
        reloadPending = false

        let isNativeUI = NativeUIBridge.shared.isActive
        // Capture current route for native UI re-execution
        let currentPath = SharedWebView.shared.webView?.url?.path ?? NativePHPApp.getStartURL()

        if isNativeUI {
            // Show the "Reloading…" overlay (ContentView watches this).
            // Cleared in `NativeElementBridge.postTreeUpdateFromRegion`
            // when the first new tree from the rebooted PHP arrives.
            DispatchQueue.main.async {
                NativeUIBridge.shared.isReloading = true
            }

            // CRITICAL: Send the hot reload event BEFORE queuing on the serial
            // dispatch queue. For native UI routes the serial queue is blocked by
            // the component's event-loop dispatch (PHPSchemeHandler also uses
            // executeOnPHPThreadAsync). Writing the event to the mmap region here
            // wakes nativephp_element_wait_event(), causing PHP to exit the event
            // loop and return from dispatch() — which frees the serial queue so
            // the block below can execute.
            NativeUIBridge.sendHotReloadEvent()
        }

        // All reboot work runs off the main thread — persistent_php_shutdown
        // blocks on a semaphore and must not run on the main queue.
        PersistentPHPRuntime.shared.executeOnPHPThreadAsync { [weak self] in
            // By the time this block runs, the native route dispatch has already
            // returned (the hot reload event caused PHP to exit its event loop).
            if isNativeUI {
                // `preserveTree: true` keeps the last published tree
                // on screen while PHP reboots (~500ms). The next
                // publish from the dispatch below replaces it
                // atomically — no white flash through the WebView
                // root.
                NativeElementBridge.stopWatching(preserveTree: true)
                NativeElementBridge.unregisterRegion()
            }

            // Reboot persistent runtime to pick up changed code.
            // The queue worker MUST be stopped first — php_embed_shutdown()
            // destroys global Zend module state, and the worker's live TSRM
            // context would reference freed memory, causing a heap-corruption crash.
            // Embedded php-mode webviews own TSRM contexts with the same
            // hazard; `PersistentPHPRuntime.shutdown()` suspends those itself
            // (see `WebviewPHPRuntime.suspendAllForRuntimeReboot`).
            if PersistentPHPRuntime.shared.isBooted {
                PHPQueueWorker.shared.stopAndWait()
                _ = PersistentPHPRuntime.shared.reboot()
                // Clear compiled Blade views so templates are recompiled from
                // the updated source files copied by the watcher.
                _ = PersistentPHPRuntime.shared.artisan(command: "view:clear")
                PHPQueueWorker.shared.start()
            } else {
                _ = NativePHPApp.shared?.artisan(additionalArgs: ["view:clear"])
            }

            if isNativeUI {
                // Allow future hot reloads BEFORE re-entering the event loop.
                // The serial queue will be blocked by the new dispatch, but the
                // next reload() can still send a hot reload event (above)
                // to break out of it.
                self?.finishReload()

                // Prefer the URI PHP wrote into .hot_restart over the WebView's
                // URL — for native-chrome routes the WebView URL isn't kept in
                // sync with the active component, so otherwise we'd lose the
                // screen on every reload and land on `/`. Android already does
                // the same (MainActivity.kt::startHotReloadWatcher).
                //
                // The file is written by `NativeComponent::runLoop` after the
                // EVENT_HOT_RELOAD event fires (PHP-side), and by this point
                // PHP has already exited the event loop and the file is on
                // disk. Read once, delete, then use.
                // Peek at the URI PHP wrote into `.hot_restart`
                // (full stack + top URI). We don't delete the
                // file here — the PHP-side `Route::native` macro
                // handler is the sole consumer; it reads the full
                // stack and removes the file. Otherwise we'd lose
                // the back-history payload.
                let hotRestartUri: String? = {
                    let storageDir = FileManager.default
                        .urls(for: .applicationSupportDirectory, in: .userDomainMask)
                        .first
                    guard let path = storageDir?
                        .appendingPathComponent("storage/framework/.hot_restart")
                        .path,
                        let data = FileManager.default.contents(atPath: path),
                        let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                        let uri = json["uri"] as? String,
                        !uri.isEmpty
                    else { return nil }
                    return uri
                }()

                // Native element mode: re-execute the route directly through
                // the persistent runtime (same as Android's executeNativeRoute).
                // PHP re-registers the mmap region and publishes a new tree.
                let request = RequestData(
                    method: "GET",
                    uri: hotRestartUri ?? currentPath,
                    data: nil,
                    query: nil,
                    headers: [:]
                )
                _ = PersistentPHPRuntime.shared.dispatch(request: request)
            } else {
                // WebView mode: reload with cache-bust
                DispatchQueue.main.async {
                    guard let webView = SharedWebView.shared.webView else {
                        self?.finishReload()
                        return
                    }
                    webView.stopLoading()
                    let currentUrl = webView.url?.absoluteString ?? "php://127.0.0.1/"
                    let separator = currentUrl.contains("?") ? "&" : "?"
                    let cacheBustUrl = "\(currentUrl)\(separator)_cb=\(Int(Date().timeIntervalSince1970 * 1000))"
                    if let url = URL(string: cacheBustUrl) {
                        webView.load(URLRequest(url: url))
                    }
                    self?.finishReload()
                }
            }
        }
    }
}

class HotReloadServer {
    private var listener: NWListener?
    private let port: NWEndpoint.Port = 9999
    private let queue = DispatchQueue(label: "HotReloadServer")
    private var retryCount = 0
    private let maxRetries = 15

    static let shared = HotReloadServer()

    private init() {}

    func start() {
        guard listener == nil else { return }

        do {
            let params = NWParameters.tcp
            // SO_REUSEADDR: lets us rebind immediately if a just-terminated
            // previous instance left port 9999 in TIME_WAIT.
            params.allowLocalEndpointReuse = true

            let listener = try NWListener(using: params, on: port)
            listener.newConnectionHandler = { [weak self] connection in
                self?.handleConnection(connection)
            }
            // NWListener.start() does NOT throw on bind failure — the error
            // ("Address already in use" when a stale instance still holds the
            // port) arrives asynchronously here. Without this handler the
            // server silently isn't listening while claiming it started, so
            // host reload triggers hit the stale instance and the live app
            // never reloads. Detect it, tear down, and retry a bounded number
            // of times so a transient hold resolves on its own.
            listener.stateUpdateHandler = { [weak self] state in
                guard let self = self else { return }
                switch state {
                case .ready:
                    self.retryCount = 0
                    print("🔥 Hot reload server listening on port \(self.port.rawValue)")
                case .failed(let error):
                    print("❌ Hot reload server bind failed: \(error)")
                    self.listener?.cancel()
                    self.listener = nil
                    if self.retryCount < self.maxRetries {
                        self.retryCount += 1
                        print("🔁 Retrying hot reload server bind (\(self.retryCount)/\(self.maxRetries)) in 1s…")
                        self.queue.asyncAfter(deadline: .now() + 1) { self.start() }
                    } else {
                        print("⛔️ Hot reload server gave up binding port \(self.port.rawValue) — is another app instance still running?")
                    }
                default:
                    break
                }
            }

            self.listener = listener
            listener.start(queue: queue)
        } catch {
            print("❌ Failed to start hot reload server: \(error)")
        }
    }
    
    func stop() {
        listener?.cancel()
        listener = nil
        print("🔥 Hot reload server stopped")
    }
    
    private func handleConnection(_ connection: NWConnection) {
        connection.start(queue: queue)
        
        // Any connection triggers a reload
        DispatchQueue.main.async {
            NotificationCenter.default.post(name: .reloadWebViewNotification, object: nil)
        }
        
        // Immediately close the connection
        connection.cancel()
        print("🔄 Hot reload triggered")
    }
}

