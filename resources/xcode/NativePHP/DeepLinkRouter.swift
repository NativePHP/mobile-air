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
        DebugLogger.shared.log("🔗 processePendingURLIfReady() - WebView: \(isWebViewReady), PHP: \(isPhpReady), Pending: \(pendingURL != nil)")
        // Only process pending URL when both WebView and PHP are ready
        if isWebViewReady && isPhpReady, let pendingURL = pendingURL {
            DebugLogger.shared.log("🔗 Processing pending URL: \(pendingURL)")
            self.redirectToURL(pendingURL)
            self.pendingURL = nil
        }
    }
    
    func hasPendingURL() -> Bool {
        return pendingURL != nil
    }
    
    func handle(url: URL) {
        DebugLogger.shared.log("🔗 DeepLinkRouter.handle() called with: \(url)")
        DebugLogger.shared.log("🔗 Current state - WebView ready: \(isWebViewReady), PHP ready: \(isPhpReady)")
        
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
        
        // 3. Either navigate immediately or store for later
        if isWebViewReady && isPhpReady {
            // App is already running — use Inertia router for SPA navigation
            // This prevents Inertia from returning raw JSON on subsequent navigations
            // (e.g. second OAuth login after logout)
            DebugLogger.shared.log("🔗 Both ready, navigating with Inertia")
            navigateWithInertia(normalizedRoute)
        } else {
            DebugLogger.shared.log("🔗 Not ready, storing as pending URL")
            // Store the URL to handle once both WebView and PHP are ready
            pendingURL = newURLString
        }
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
