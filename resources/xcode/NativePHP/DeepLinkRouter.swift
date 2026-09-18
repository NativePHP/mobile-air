import Foundation

final class DeepLinkRouter {
    static let shared = DeepLinkRouter()

    private var pendingURL: String?
    private var isWebViewReady = false
    private var isPhpReady = false

    func markWebViewReady() {
        DebugLogger.shared.log("🔗 WebView marked as ready")
        isWebViewReady = true
        processePendingURLIfReady()
    }

    func markPhpReady() {
        DebugLogger.shared.log("🔗 PHP marked as ready")
        isPhpReady = true
        processePendingURLIfReady()
    }

    private func processePendingURLIfReady() {
        DebugLogger.shared.log("🔗 processePendingURLIfReady() - WebView: \(isWebViewReady), PHP: \(isPhpReady), NativeUI: \(NativeUIBridge.shared.isActive), Pending: \(pendingURL != nil)")
        // Native-ui apps never mark the WebView ready (see handle()) — once
        // PHP is up, replay through the __deeplink event instead of a WebView
        // load, which would queue behind the blocked native-ui loop.
        guard isPhpReady, let pendingURL = pendingURL else {
            return
        }

        if NativeUIBridge.shared.isActive {
            let route = pendingURL.replacingOccurrences(of: "php://127.0.0.1", with: "")
            DebugLogger.shared.log("🔗 Processing pending deep link via native-ui: \(route)")
            dispatchNativeUIDeepLink(route.isEmpty ? "/" : route)
            self.pendingURL = nil
        } else if isWebViewReady {
            DebugLogger.shared.log("🔗 Processing pending URL: \(pendingURL)")
            self.redirectToURL(pendingURL)
            self.pendingURL = nil
        }
    }

    func hasPendingURL() -> Bool {
        return pendingURL != nil
    }

    /// Re-attempt delivery of a parked deep link — called when native-ui
    /// activates, since a cold-start link can arrive before the runtime
    /// that handles __deeplink events is up.
    func replayPendingDeepLink() {
        processePendingURLIfReady()
    }

    func handle(url: URL) {
        DebugLogger.shared.log("🔗 DeepLinkRouter.handle() called with: \(url)")
        DebugLogger.shared.log("🔗 Current state - WebView ready: \(isWebViewReady), PHP ready: \(isPhpReady)")

        // Guard: DeepLinkRouter only handles deep links (custom URL schemes and universal links).
        // File URLs are delivered to the app when CFBundleDocumentTypes / LSSupportsOpeningDocumentsInPlace
        // are declared (e.g. when the user picks the app from another app's share sheet or "Open In…").
        // Without this guard, the file path is treated as a Laravel route and the WebView displays
        // a 404 like "route /private/var/mobile/.../filename.pdf could not be found". Plugins that
        // declare document types are responsible for handling file URLs before they reach this router.
        guard !url.isFileURL else {
            DebugLogger.shared.log("🔗 Ignoring file URL — DeepLinkRouter only handles deep links: \(url)")
            return
        }

        // PROTOTYPE: jump://native — leave WebView mode and return to native-ui.
        // The local native runloop kept running underneath (WebView sessions
        // don't touch it), so re-showing its tree is immediately interactive.
        if url.scheme == "jump", url.host == "native" {
            DebugLogger.shared.log("🌐 jump://native — leaving WebView mode")
            JumpWebViewSession.shared.stop()
            DispatchQueue.main.async {
                NativeUIBridge.shared.isActive = true
            }
            return
        }

        // PROTOTYPE: jump://webview?host=&port= — render a remote WebView app.
        // Flip the shell into WebView mode and start forwarding php://127.0.0.1
        // to the dev server (PHPSchemeHandler reads JumpWebViewSession). Loading
        // php://127.0.0.1/ then renders the remote app's GET / through the
        // existing WebView. Later this is started by the discovery relay.
        // ?stop=1 leaves WebView mode (same as jump://native).
        if url.scheme == "jump", url.host == "webview" {
            let comps = URLComponents(url: url, resolvingAgainstBaseURL: false)
            if comps?.queryItems?.first(where: { $0.name == "stop" })?.value == "1" {
                DebugLogger.shared.log("🌐 jump://webview?stop=1 — leaving WebView mode")
                JumpWebViewSession.shared.stop()
                DispatchQueue.main.async {
                    NativeUIBridge.shared.isActive = true
                }
                return
            }
            let host = comps?.queryItems?.first(where: { $0.name == "host" })?.value ?? ""
            let port = comps?.queryItems?.first(where: { $0.name == "port" })?.value ?? "8000"
            guard !host.isEmpty else {
                DebugLogger.shared.log("🌐 jump://webview missing host — ignoring")
                return
            }
            JumpWebViewSession.shared.start(host: host, port: port)
            DispatchQueue.main.async {
                NativeUIBridge.shared.isActive = false
                NotificationCenter.default.post(
                    name: .redirectToURLNotification,
                    object: nil,
                    userInfo: ["url": "php://127.0.0.1/"]
                )
            }
            return
        }

        // 1. Normalise the URL (strip scheme, keep host/path/query)
        var route = ""

        // For custom schemes, we need to handle the host + path
        if url.scheme != "https" && url.scheme != "http" {
            // For custom schemes like native://test/, the "test" becomes the host
            // We want to treat it as a path instead
            if let host = url.host, !host.isEmpty {
                route = "/\(host)"
                if !url.path.isEmpty && url.path != "/" {
                    route += url.path
                }
            } else {
                route = url.path.isEmpty || url.path == "/" ? "/" : url.path
            }
        } else {
            // For universal links, just use the path
            route = url.path.isEmpty || url.path == "/" ? "/" : url.path
        }

        // Add query parameters if present
        let fullRoute = route + (url.query.map { "?\($0)" } ?? "")

        // 2. Convert to php://127.0.0.1/{some_url} format
        // Ensure the route starts with a slash
        let normalizedRoute = fullRoute.hasPrefix("/") ? fullRoute : "/\(fullRoute)"
        let newURLString = "php://127.0.0.1\(normalizedRoute)"

        DebugLogger.shared.log("🔗 Normalized to: \(newURLString)")

        // 3. Either navigate immediately or store for later.
        // Native-UI (edge) apps never mark the WebView ready — it is only the
        // php:// transport, no page-load callback fires — so gating on
        // isWebViewReady would park every warm deep link as pending forever
        // (OAuth auth-session callbacks included). The native-ui dispatch
        // below goes through NativeElementBridge and never touches the
        // WebView; PHP readiness is all it needs.
        if isPhpReady && (isWebViewReady || NativeUIBridge.shared.isActive) {
            // App is already running. How we navigate depends on the runtime:
            if NativeUIBridge.shared.isActive {
                // Native-UI (edge) app: the single PHP event loop is blocked
                // running the current screen — a fresh webView.load(php://…)
                // for a Route::native screen never commits (it queues behind
                // the running loop), so warm deep/universal links would be
                // silently dropped.
                // Instead, wake the running loop with a native event carrying
                // the target route; NativeComponent::dispatchNativeEvent turns
                // it into a NavigationIntent::NAVIGATE and NativeRouter pushes
                // the screen — the same path an in-app @tap navigate uses.
                // Hand the original https URL along; PHP gives it back to
                // Safari when the app has no route for the path, instead of
                // exiting to the WebView and serving a local 404.
                let externalURL = (url.scheme == "https" || url.scheme == "http") ? url.absoluteString : nil
                dispatchNativeUIDeepLink(normalizedRoute, externalURL: externalURL)
            } else {
                // Inertia/WebView SPA: use the SPA router to preserve state.
                // This prevents Inertia from returning raw JSON on subsequent
                // navigations (e.g. second OAuth login after logout).
                DebugLogger.shared.log("🔗 Both ready, navigating with Inertia")
                navigateWithInertia(normalizedRoute)
            }
        } else {
            DebugLogger.shared.log("🔗 Not ready, storing as pending URL")
            // Store the URL to handle once the runtime is ready
            pendingURL = newURLString
        }
    }

    /// Wake the blocked native-ui PHP loop with the deep-link route (the
    /// same path an in-app @tap navigate uses).
    private func dispatchNativeUIDeepLink(_ route: String, externalURL: String? = nil) {
        let escaped = route
            .replacingOccurrences(of: "\\", with: "\\\\")
            .replacingOccurrences(of: "\"", with: "\\\"")
        var urlField = ""
        if let externalURL {
            let escapedURL = externalURL
                .replacingOccurrences(of: "\\", with: "\\\\")
                .replacingOccurrences(of: "\"", with: "\\\"")
            urlField = ",\"url\":\"\(escapedURL)\""
        }
        let json = "{\"uri\":\"\(escaped)\"\(urlField)}"
        DebugLogger.shared.log("🔗 native-ui: dispatching __deeplink event: \(route)")
        NativeElementBridge.sendNativeEvent(eventName: "__deeplink", payloadJson: json)
    }

    private func redirectToURL(_ urlString: String) {
        DebugLogger.shared.log("🔗 redirectToURL() posting notification for: \(urlString)")
        NotificationCenter.default.post(
            name: .redirectToURLNotification,
            object: nil,
            userInfo: ["url": urlString]
        )
        DebugLogger.shared.log("🔗 redirectToURL() notification posted successfully")
    }

    /// Navigate using Inertia router when the app is already running.
    /// Uses the path (not the full php:// URL) so window.router.visit() works correctly.
    private func navigateWithInertia(_ path: String) {
        DebugLogger.shared.log("🔗 navigateWithInertia() posting notification for path: \(path)")
        DispatchQueue.main.async {
            NotificationCenter.default.post(
                name: .navigateWithInertiaNotification,
                object: nil,
                userInfo: ["path": path]
            )
        }
        DebugLogger.shared.log("🔗 navigateWithInertia() notification posted")
    }
}
