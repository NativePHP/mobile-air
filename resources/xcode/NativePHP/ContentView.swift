import SwiftUI
import WebKit

extension NSNotification.Name {
    public static let reloadWebViewNotification = NSNotification.Name("ReloadWebViewNotification")
    public static let redirectToURLNotification = NSNotification.Name("RedirectToURLNotification")
    public static let navigateWithInertiaNotification = NSNotification.Name("NavigateWithInertiaNotification")
}

struct ContentView: View {
    @State private var phpOutput = ""
    @StateObject private var uiState = NativeUIState.shared
    @ObservedObject private var nativeUIBridge = NativeUIBridge.shared
    @Environment(\.colorScheme) private var colorScheme

    var body: some View {
        // When native UI is active, render JUST the native tree —
        // unmount the WebView entirely. Keeping the WebView alive
        // alongside the SwiftUI overlay caused iOS 26's
        // `Tab(role: .search)` floating Liquid Glass capsule to lose
        // single-tap activation (likely WKWebView's UIKit hit-testing
        // racing the capsule's gesture recognizer). The trade-off is a
        // WebView remount when transitioning back from native UI; the
        // WebView's `SharedWebView.shared` cache keeps that fast.
        Group {
            if nativeUIBridge.isActive, let tree = nativeUIBridge.currentTree {
                // Two-layer swap. Screens are keyed by their screenKey in a
                // ForEach so a screen keeps its IDENTITY when it changes
                // role from "current" to "outgoing" — no removal transition
                // fires at swap time and its view state survives. The
                // incoming screen (new key) animates in via its insertion
                // transition — the reliable half of SwiftUI's transition
                // system — while the held outgoing screen's exit (parallax
                // drift + dim, or static hold) is driven by ordinary
                // animated modifiers in `ScreenExitModifier`. The outgoing
                // entry is dropped by the bridge after the transition,
                // invisibly beneath the opaque new screen.
                ZStack {
                    ForEach(activeScreens(currentTree: tree)) { screen in
                        NativeTreeRenderer(tree: screen.tree)
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                            .background(Color(.systemBackground))
                            .modifier(ScreenExitModifier(
                                isExiting: screen.isOutgoing,
                                transition: nativeUIBridge.outgoingScreen?.transition
                            ))
                            // Parallax depth cue that survives dark mode:
                            // the INCOMING screen casts a soft shadow onto
                            // the outgoing one at its leading edge (the
                            // scrim alone is black-on-black there). Active
                            // only during the parallax transition window.
                            .shadow(
                                color: .black.opacity(incomingShadowOpacity(for: screen)),
                                radius: 18,
                                x: -10
                            )
                            .transition(nativeScreenTransition(for: nativeUIBridge.pendingTransition))
                            // Each new screen sits above the previous one
                            // (keys increment), so slides cover in push
                            // order and a fade has a defined front/back.
                            .zIndex(Double(screen.id))
                    }
                }
            } else {
                NativeSideNavigation(onNavigate: handleNavigation) {
                    WebViewLayoutContainer(onTabSelected: handleNavigation)
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                        .safeAreaInset(edge: .top, spacing: 0) {
                            if uiState.hasTopBar() {
                                NativeTopBar(onNavigate: handleNavigation)
                            }
                        }
                }
            }
        }
        .overlay(alignment: .top) {
            // Hot-reload indicator. Mirrors iOS 26's Liquid Glass pill
            // language so it feels native and doesn't intrude on the
            // user's content. Fades in/out around the ~500ms PHP
            // reboot window. Bridge state owned by `NativeUIBridge.isReloading`.
            if nativeUIBridge.isReloading {
                HotReloadIndicator()
                    .padding(.top, 8)
                    .transition(.move(edge: .top).combined(with: .opacity))
                    .zIndex(1)
            }
        }
        // Per-transition swap animation — this ambient animation is what
        // actually paces `.move` insertions (attached transition animations
        // are ignored for `.move`; see nativeScreenSwapAnimation).
        .animation(
            nativeScreenSwapAnimation(for: nativeUIBridge.pendingTransition),
            value: nativeUIBridge.screenKey
        )
        .animation(.easeInOut(duration: 0.2), value: nativeUIBridge.isActive)
        .animation(.easeInOut(duration: 0.2), value: nativeUIBridge.isReloading)
        // Global 3-finger swipe-right escape hatch (attaches a recognizer to the
        // key window). Fires `JumpEscapeHatch`; the Jump client exits any
        // connected demo app back to the Jump home.
        .background(EscapeHatchGesture())
        // Push a native AppearanceChanged event to PHP when the system theme
        // flips (Control Center toggle, sunset auto-switch). Drives the
        // reactive `System::appearance()` / `#[On(AppearanceChanged)]` path.
        // ContentView is always mounted, so this observes every change.
        .onChange(of: colorScheme) { newScheme in
            let mode = newScheme == .dark ? "dark" : "light"
            LaravelBridge.shared.send?("Native\\Mobile\\Events\\System\\AppearanceChanged", ["mode": mode])
        }
    }

    /// One layer of the two-layer native screen swap. `id` is the screen's
    /// stable screenKey — ForEach identity, so a screen moving from the
    /// "current" role to the "outgoing" role is the SAME view to SwiftUI.
    private struct ActiveNativeScreen: Identifiable {
        let id: Int
        let tree: NativeUITree
        let isOutgoing: Bool
    }

    /// Outgoing (held) screen first, current screen last. The outgoing
    /// entry is present only during a transition window; the bridge clears
    /// it ~0.6s after the swap.
    private func activeScreens(currentTree: NativeUITree) -> [ActiveNativeScreen] {
        var screens: [ActiveNativeScreen] = []
        if let out = nativeUIBridge.outgoingScreen, out.key != nativeUIBridge.screenKey {
            screens.append(ActiveNativeScreen(id: out.key, tree: out.tree, isOutgoing: true))
        }
        screens.append(ActiveNativeScreen(
            id: nativeUIBridge.screenKey,
            tree: currentTree,
            isOutgoing: false
        ))
        return screens
    }

    /// Shadow the incoming screen casts on the held outgoing screen during
    /// a parallax_push — zero (no shadow layer) at all other times.
    private func incomingShadowOpacity(for screen: ActiveNativeScreen) -> Double {
        guard !screen.isOutgoing,
              let out = nativeUIBridge.outgoingScreen,
              out.transition == "parallax_push" else { return 0 }
        return 0.45
    }

    /// Handle navigation from any UI component
    /// Uses Inertia router if available for SPA-like navigation, falls back to full page load
    private func handleNavigation(_ url: String) {
        // In a Jump WebView session, native-chrome / side-nav / tab links point at
        // the dev-server host (absolute URLs). Route them through the WebView
        // forward (php://127.0.0.1) exactly like anchor taps — otherwise
        // isExternalUrl() sees a non-localhost host and opens them in the system
        // browser, and the Inertia path below doesn't exist for a plain WebView app.
        if JumpWebViewSession.shared.isActive {
            let path = extractPath(url)
            NotificationCenter.default.post(
                name: .redirectToURLNotification,
                object: nil,
                userInfo: ["url": "php://127.0.0.1\(path)"]
            )
            return
        }

        // Check if this is an external HTTP/HTTPS URL
        if isExternalUrl(url) {
            // Open external URLs in the default browser
            if let externalUrl = URL(string: url) {
                UIApplication.shared.open(externalUrl)
            }
            return
        }

        // Handle internal navigation using Inertia if available
        let path = extractPath(url)

        NotificationCenter.default.post(
            name: .navigateWithInertiaNotification,
            object: nil,
            userInfo: ["path": path]
        )
    }

    /// Check if URL is external (absolute HTTP/HTTPS not pointing to localhost)
    private func isExternalUrl(_ url: String) -> Bool {
        return (url.hasPrefix("http://") || url.hasPrefix("https://"))
            && !url.contains("127.0.0.1")
            && !url.contains("localhost")
    }


    /// Extract path and query from URL, handling both full URLs and relative paths
    private func extractPath(_ url: String) -> String {
        if url.hasPrefix("php://") {
            // Extract just the path from php://127.0.0.1/path
            if let parsedUrl = URL(string: url) {
                let path = parsedUrl.path.isEmpty ? "/" : parsedUrl.path
                let query = parsedUrl.query
                let result = query != nil ? "\(path)?\(query!)" : path

                return result
            }
        }

        if url.hasPrefix("http://") || url.hasPrefix("https://") {
            // Parse as full URL and extract path + query
            if let parsedUrl = URL(string: url) {
                let path = parsedUrl.path.isEmpty ? "/" : parsedUrl.path
                let query = parsedUrl.query
                let result = query != nil ? "\(path)?\(query!)" : path

                return result
            }
        } else if url.hasPrefix("/") {
            return url
        } else {
            let result = "/\(url)"

            return result
        }

        // Fallback
        let fallback = url.hasPrefix("/") ? url : "/\(url)"

        return fallback
    }
}

/// Liquid Glass pill shown briefly during hot reload. Two rows of
/// content: a spinner + label. Auto-dismisses when
/// `NativeUIBridge.isReloading` flips false (driven by the first
/// publish from the rebooted PHP runtime — see
/// `NativeElementBridge.postTreeUpdateFromRegion`).
///
/// Uses iOS 26's `.glassEffect` material when available; falls back
/// to a thin material on earlier OSes.
struct HotReloadIndicator: View {
    var body: some View {
        HStack(spacing: 10) {
            ProgressView()
                .controlSize(.regular)
                .tint(.accentColor)
            Text("Reloading…")
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(.primary)
        }
        .padding(.horizontal, 18)
        .padding(.vertical, 12)
        .modifier(GlassPillBackground())
        .shadow(color: .black.opacity(0.18), radius: 12, y: 4)
    }
}

/// Wraps the glass / thin-material material lookup behind an iOS-26
/// availability check. iOS 26+ gets the real Liquid Glass capsule;
/// earlier versions fall back to a Capsule-shaped `.thinMaterial`.
private struct GlassPillBackground: ViewModifier {
    func body(content: Content) -> some View {
        if #available(iOS 26.0, *) {
            content.glassEffect(in: .capsule)
        } else {
            content.background(.thinMaterial, in: .capsule)
        }
    }
}

/// Shared WKWebView instance holder to persist across view updates
class SharedWebView: ObservableObject {
    static let shared = SharedWebView()
    weak var webView: WKWebView?
    var coordinator: WebView.Coordinator?
}

/// Container that wraps a single WebView instance with appropriate layout based on UI state
struct WebViewLayoutContainer: View {
    @ObservedObject var uiState = NativeUIState.shared
    @Environment(\.horizontalSizeClass) var horizontalSizeClass
    let onTabSelected: (String) -> Void

    var body: some View {
        if uiState.hasBottomNav() {
            if #available(iOS 26.0, *) {
                // iOS 26+: WebView extends behind tab bar for Liquid Glass effect
                GeometryReader { geometry in
                    ZStack(alignment: .bottom) {
                        // Single WebView instance - extends to full screen
                        WebView(shared: SharedWebView.shared, horizontalSizeClass: horizontalSizeClass)
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                            .ignoresSafeArea()
                            // Add bottom padding so content isn't hidden behind tab bar
                            .safeAreaInset(edge: .bottom) {
                                Color.clear
                                    .frame(height: 49 + geometry.safeAreaInsets.bottom)
                            }

                        // Bottom navigation overlays at bottom
                        NativeBottomNavigation(onTabSelected: onTabSelected)
                    }
                    .ignoresSafeArea()
                }
            } else {
                // iOS 18 and below: WebView stops at tab bar
                ZStack(alignment: .bottom) {
                    // Single WebView instance - fills available space
                    WebView(shared: SharedWebView.shared, horizontalSizeClass: horizontalSizeClass)
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                        .ignoresSafeArea(.all, edges: uiState.hasTopBar() ? .horizontal : .all)

                    // Bottom navigation at bottom
                    NativeBottomNavigation(onTabSelected: onTabSelected)
                }
            }
        } else {
            // No bottom nav - WebView fills entire screen
            WebView(shared: SharedWebView.shared, horizontalSizeClass: horizontalSizeClass)
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .ignoresSafeArea(.all, edges: uiState.hasTopBar() ? [.horizontal, .bottom] : .all)
        }
    }
}

struct WebView: UIViewRepresentable {
    static let dataStore = WKWebsiteDataStore.nonPersistent()
    let shared: SharedWebView
    let horizontalSizeClass: UserInterfaceSizeClass?

    func makeCoordinator() -> Coordinator {
        // Reuse existing coordinator if available to maintain LaravelBridge connection
        if let existingCoordinator = shared.coordinator {
            print("♻️ Reusing existing Coordinator")
            return existingCoordinator
        }

        print("🆕 Creating new Coordinator")
        let coordinator = Coordinator()
        shared.coordinator = coordinator
        return coordinator
    }

    static func dismantleUIView(_ uiView: WKWebView, coordinator: Coordinator) {
        // Don't remove observers or clear LaravelBridge when reusing the WebView
        // The shared instance will persist and we'll re-register in makeUIView if needed
        print("⚠️ dismantleUIView called - skipping observer removal for reused WebView")
    }

    class Coordinator: NSObject, WKNavigationDelegate {
        let logger = ConsoleLogger()
        var webView: WKWebView?
        var hasCompletedInitialLoad = false
        private var reloadInProgress = false

        func webView(_ webView: WKWebView,
                     decidePolicyFor navigationAction: WKNavigationAction,
                     decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
            guard let url = navigationAction.request.url else {
                decisionHandler(.allow)
                return
            }

            let scheme = url.scheme?.lowercased() ?? ""

            // Rewrite http(s)://127.0.0.1 to php:// scheme — PHP/Symfony only understands
            // http/https so redirect()->intended() and $request->fullUrl() will always
            // produce http:// URLs for the local server. Route through the scheme handler's
            // redirect path which handles cookie injection from WKHTTPCookieStore.
            //
            // PROTOTYPE: in a Jump WebView session, links the remote app emits point
            // at the dev-server host (Laravel builds absolute URLs from the request
            // Host). Treat that host like 127.0.0.1 — rewrite to php://127.0.0.1 and
            // forward through the scheme handler — so navigations stay in the WebView
            // instead of escaping to Safari. The forward's host:port comes from
            // JumpWebViewSession, so the rewritten URL drops the remote host + port.
            let isJumpHost = JumpWebViewSession.shared.isActive
                && url.host == JumpWebViewSession.shared.host

            if (scheme == "http" || scheme == "https"),
               url.host == "127.0.0.1" || isJumpHost {
                var components = URLComponents(url: url, resolvingAgainstBaseURL: false)
                components?.scheme = "php"
                components?.host = "127.0.0.1"
                components?.port = nil
                if let phpURL = components?.url {
                    NotificationCenter.default.post(
                        name: .redirectToURLNotification,
                        object: nil,
                        userInfo: ["url": phpURL.absoluteString]
                    )
                }
                decisionHandler(.cancel)
                return
            }

            // Open external URLs and system schemes with the system handler
            if ["http", "https", "tel", "mailto", "sms", "facetime", "facetime-audio"].contains(scheme) {
                UIApplication.shared.open(url)
                decisionHandler(.cancel)
            } else {
                decisionHandler(.allow)
            }
        }

        func webView(_ webView: WKWebView, didCommit navigation: WKNavigation!) {
            // A committed WebView page load means we are back in WebView mode
            // (a Route::native screen never commits — its request blocks in the
            // PHP event loop and only returns a redirect/empty body on exit).
            // Without this, exit-to-web leaves `isActive == true`, so the frozen
            // native tree stays on screen over the loaded WebView page.
            NativeUIBridge.shared.isActive = false

            // Inject safe area insets IMMEDIATELY when navigation commits (before rendering)
            // This is the iOS equivalent of Android's onPageStarted
            injectSafeAreaInsets(webView)
        }

        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
            // On first load, dismiss the splash screen
            if !hasCompletedInitialLoad {
                hasCompletedInitialLoad = true
                DispatchQueue.main.async {
                    AppState.shared.markInitialized()
                }
            }

            // Fade in WebView smoothly once initial page load is complete
            DispatchQueue.main.async {
                UIView.animate(withDuration: 0.2) {
                    webView.alpha = 1.0
                }
            }

            // Re-inject safe area insets to ensure they're set (like Android does)
            injectSafeAreaInsets(webView)
        }

        private func injectSafeAreaInsets(_ webView: WKWebView) {
            // Get insets from window scene (more reliable than webView.window which can be nil)
            let windowScene = UIApplication.shared.connectedScenes
                .compactMap { $0 as? UIWindowScene }
                .first

            let insets = windowScene?.windows.first?.safeAreaInsets ?? webView.window?.safeAreaInsets ?? .zero

            // Also get color scheme for CSS variable
            let isDarkMode = windowScene?.windows.first?.traitCollection.userInterfaceStyle == .dark
            let colorScheme = isDarkMode ? "dark" : "light"

            let js = """
            (function() {
                // Set CSS variables directly on documentElement for immediate availability
                if (document.documentElement) {
                    document.documentElement.style.setProperty('--inset-top', '\(insets.top)px');
                    document.documentElement.style.setProperty('--inset-right', '\(insets.right)px');
                    document.documentElement.style.setProperty('--inset-bottom', '\(insets.bottom)px');
                    document.documentElement.style.setProperty('--inset-left', '\(insets.left)px');
                    document.documentElement.style.setProperty('--native-color-scheme', '\(colorScheme)');
                }
            })();
            """

            webView.evaluateJavaScript(js, completionHandler: nil)
        }

        @MainActor
        func notifyLaravel(
            event: String,
            payload: [String: Any]
        ) {
            let rawEventName = event
            let event: String = {
                let data = try! JSONSerialization.data(withJSONObject: [event])
                var literal = String(data: data, encoding: .utf8)!
                literal.removeFirst()
                literal.removeLast()
                return literal
            }()

            // 1. Inject JS event into the current web page
            if let jsonData = try? JSONSerialization.data(withJSONObject: payload, options: []),
               let jsonString = String(data: jsonData, encoding: .utf8) {

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

                self.webView?.evaluateJavaScript(js) { result, error in
                    if let error = error {
                        print("JavaScript injection error injecting event '\(event)': \(error)")
                    } else {
                        print("JavaScript event '\(event)' dispatched.")
                    }
                }

                // FUTURE: Send a request to Laravel backend directly
//                let request = RequestData(
//                    method: "POST",
//                    uri: "php://127.0.0.1/_native/api/events",
//                    data: jsonString,
//                    headers: [
//                        "Content-Type": "application/json"
//                    ])
//
//                _ = NativePHPApp.laravel(request: request)

            }

            // Also inject into the element event queue for #[OnNative] listeners
            if let jsonData = try? JSONSerialization.data(withJSONObject: payload, options: []),
               let jsonString = String(data: jsonData, encoding: .utf8) {
                NativeElementBridge.sendNativeEvent(eventName: rawEventName, payloadJson: jsonString)
            }
        }

        @objc func reloadWebView() {
            // Guard against rapid-fire file change events (file + directory)
            // triggering concurrent reboots that race on php_embed_shutdown.
            guard !reloadInProgress else { return }
            reloadInProgress = true

            let isNativeUI = NativeUIBridge.shared.isActive
            // Capture current route for native UI re-execution
            let currentPath = self.webView?.url?.path ?? NativePHPApp.getStartURL()

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

                DispatchQueue.main.async {
                    NativeUIState.shared.clearAll()
                }

                if isNativeUI {
                    // Allow future hot reloads BEFORE re-entering the event loop.
                    // The serial queue will be blocked by the new dispatch, but the
                    // next reloadWebView() can still send a hot reload event (above)
                    // to break out of it.
                    self?.reloadInProgress = false

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
                        guard let webView = self?.webView else {
                            self?.reloadInProgress = false
                            return
                        }
                        webView.stopLoading()
                        let currentUrl = webView.url?.absoluteString ?? "php://127.0.0.1/"
                        let separator = currentUrl.contains("?") ? "&" : "?"
                        let cacheBustUrl = "\(currentUrl)\(separator)_cb=\(Int(Date().timeIntervalSince1970 * 1000))"
                        if let url = URL(string: cacheBustUrl) {
                            webView.load(URLRequest(url: url))
                        }
                        self?.reloadInProgress = false
                    }
                }
            }
        }

        // Swipe gestures disabled for back/forward navigation
        // @objc func handleSwipeLeft(_ gesture: UISwipeGestureRecognizer) {
        //     if let webView = gesture.view as? WKWebView, webView.canGoForward {
        //         webView.goForward()
        //     }
        // }

        // @objc func handleSwipeRight(_ gesture: UISwipeGestureRecognizer) {
        //     if let webView = gesture.view as? WKWebView, webView.canGoBack {
        //         webView.goBack()
        //     }
        // }

        @objc func redirectToURL(_ notification: Notification) {
            if let urlString = notification.userInfo?["url"] as? String {
                if let url = URL(string: urlString) {
                    // Stop any current loading before starting new request
                    if self.webView?.isLoading == true {
                        self.webView?.stopLoading()
                    }

                    self.webView?.load(URLRequest(url: url))
                }
            }
        }

        /// Navigate using Inertia router if available, otherwise fall back to location.href
        /// This allows native edge component clicks to integrate with Inertia.js for SPA-like navigation
        @objc func navigateWithInertia(_ notification: Notification) {
            guard let path = notification.userInfo?["path"] as? String else { return }

            // Escape the path for JavaScript string
            let escapedPath = path
                .replacingOccurrences(of: "\\", with: "\\\\")
                .replacingOccurrences(of: "\"", with: "\\\"")

            let js = """
            (function() {
                var path = "\(escapedPath)";
                console.log('[NativePHP] Navigation requested:', path);

                // Check if Inertia router is available
                if (typeof window.router !== 'undefined' && typeof window.router.visit === 'function') {
                    console.log('[NativePHP] Using Inertia router.visit():', path);
                    window.router.visit(path);
                } else {
                    console.log('[NativePHP] Inertia not available, using location.href');
                    window.location.href = path;
                }
            })();
            """

            self.webView?.evaluateJavaScript(js, completionHandler: nil)
        }

        @objc func keyboardWillShow(_ notification: Notification) {
            let js = "document.body.classList.add('keyboard-visible');"
            self.webView?.evaluateJavaScript(js, completionHandler: nil)
        }

        @objc func keyboardWillHide(_ notification: Notification) {
            let js = "document.body.classList.remove('keyboard-visible');"
            self.webView?.evaluateJavaScript(js, completionHandler: nil)
        }
    }

    func makeUIView(context: Context) -> WKWebView {
        let coordinator = context.coordinator

        // Reuse existing WebView if available (coordinator is also reused via makeCoordinator)
        if let existingWebView = shared.webView {
            print("♻️ Reusing existing WKWebView instance (coordinator already reused)")
            // Ensure coordinator has reference to webView
            coordinator.webView = existingWebView
            existingWebView.navigationDelegate = coordinator
            existingWebView.alpha = 1.0

            // Observers are still registered (we don't remove them in dismantleUIView)
            // LaravelBridge is still connected (we don't clear it in dismantleUIView)

            return existingWebView
        }

        print("🆕 Creating new WKWebView instance with new Coordinator")

        // Initialize the custom scheme handler
        let schemeHandler = PHPSchemeHandler()

        // Configure WKWebView with the custom scheme handler
        let webConfiguration = WKWebViewConfiguration()

        webConfiguration.websiteDataStore = WebView.dataStore
        webConfiguration.setURLSchemeHandler(schemeHandler, forURLScheme: "php")
        webConfiguration.allowsInlineMediaPlayback = true

        #if DEBUG
        // Allow the WebView to load HTTP subresources (e.g. Vite dev server assets)
        // without being blocked by WebKit's mixed content policy, since the custom
        // php:// scheme is treated as a secure context.
        // Uses responds(to:) to safely check the key exists before setting it,
        // since this is an internal WebKit preference not available on all platforms.
        let insecureContentSelector = NSSelectorFromString("setAllowRunningInsecureContent:")
        if webConfiguration.preferences.responds(to: insecureContentSelector) {
            webConfiguration.preferences.setValue(true, forKey: "allowRunningInsecureContent")
        }
        #endif

        let webView = WKWebView(frame: .zero, configuration: webConfiguration)

        // Store webView in coordinator and shared instance
        coordinator.webView = webView
        shared.webView = webView
        shared.coordinator = coordinator

        addDebugSupport(webView: webView, context: context)

        addNativeHelper(webView: webView)

        addSwipeGestureSupport(webView: webView, context: context)

        // Configure scrollView for proper safe area handling with viewport-fit=cover
        webView.scrollView.contentInsetAdjustmentBehavior = .never

        let fallbackPath = Bundle.main.path(forResource: "index", ofType: "html")
        let fallbackURL = URL(fileURLWithPath: fallbackPath!)

        // Set initial opacity to 0 for smooth fade-in (instead of hiding)
        webView.alpha = 0.0

        // Give AppDelegate time to process any launch deep links before deciding what to load
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.1) {
            DebugLogger.shared.log("🌐 WebView setup after AppDelegate delay")

            // Check for pending deep link BEFORE marking ready
            // (marking ready might process and clear the pending URL immediately)
            let hasPendingDeepLink = DeepLinkRouter.shared.hasPendingURL()

            // Mark WebView as ready (this may trigger pending URL processing)
            DeepLinkRouter.shared.markWebViewReady()

            // Only load default URL if there was no pending deep link
            if !hasPendingDeepLink {
                DebugLogger.shared.log("🌐 No pending deep link, loading default URL")
                let startPath = NativePHPApp.getStartURL()
                let startPage = URL(string: "php://127.0.0.1\(startPath)")
                webView.load(URLRequest(url: startPage ?? fallbackURL))
            } else {
                DebugLogger.shared.log("🌐 Pending deep link detected, skipping default URL load")
            }
        }

        // Setup Laravel bridge - use shared coordinator so it persists
        LaravelBridge.shared.send = { [weak shared] event, payload in
            Task { @MainActor in
                shared?.coordinator?.notifyLaravel(event: event, payload: payload as [String : Any])
            }
        }

        // Register NotificationCenter observers
        NotificationCenter.default.addObserver(
            context.coordinator,
            selector: #selector(Coordinator.reloadWebView),
            name: .reloadWebViewNotification,
            object: nil
        )

        NotificationCenter.default.addObserver(
            context.coordinator,
            selector: #selector(Coordinator.redirectToURL),
            name: .redirectToURLNotification,
            object: nil
        )

        NotificationCenter.default.addObserver(
            context.coordinator,
            selector: #selector(Coordinator.navigateWithInertia),
            name: .navigateWithInertiaNotification,
            object: nil
        )

        // Keyboard visibility observers
        NotificationCenter.default.addObserver(
            context.coordinator,
            selector: #selector(Coordinator.keyboardWillShow),
            name: UIResponder.keyboardWillShowNotification,
            object: nil
        )

        NotificationCenter.default.addObserver(
            context.coordinator,
            selector: #selector(Coordinator.keyboardWillHide),
            name: UIResponder.keyboardWillHideNotification,
            object: nil
        )

        return webView
    }

    func addDebugSupport(webView: WKWebView, context: Context) {
        #if DEBUG
        let userContentController = webView.configuration.userContentController
        let consoleLoggingScript = """
        (function() {
            function capture(type) {
                var old = console[type];
                console[type] = function() {
                    var message = Array.prototype.slice.call(arguments).join(" ");
                    window.webkit.messageHandlers.console.postMessage({ type: type, message: message });
                    old.apply(console, arguments);
                };
            }
            ['log', 'warn', 'error', 'debug'].forEach(capture);
        })();
        """

        let userScript = WKUserScript(source: consoleLoggingScript, injectionTime: .atDocumentStart, forMainFrameOnly: false)
        userContentController.addUserScript(userScript)
        userContentController.add(context.coordinator.logger, name: "console")

        webView.isInspectable = true
        #endif
    }

    func addNativeHelper(webView: WKWebView) {
        let contentController = webView.configuration.userContentController

        // Inject safe area CSS FIRST at document start to prevent layout jump
        let safeAreaCSS = """
        (function() {
            var style = document.createElement('style');
            style.textContent = ':root{--inset-top:env(safe-area-inset-top,0px);--inset-right:env(safe-area-inset-right,0px);--inset-bottom:env(safe-area-inset-bottom,0px);--inset-left:env(safe-area-inset-left,0px)}@media(orientation:landscape){.nativephp-safe-area{padding-right:var(--inset-right);padding-left:var(--inset-left)}}@media(orientation:portrait){.nativephp-safe-area{padding-top:var(--inset-top);padding-bottom:var(--inset-bottom)}}';
            (document.head || document.documentElement).appendChild(style);
        })();
        """
        let safeAreaScript = WKUserScript(
            source: safeAreaCSS,
            injectionTime: .atDocumentStart,
            forMainFrameOnly: true
        )
        contentController.addUserScript(safeAreaScript)

        // Inject Native helper and other functionality at document end
        let helper = """
        const Native = {
            on: (event, callback) => {
                document.addEventListener("native-event", function (e) {
                    event = event.replace(/^(\\\\)+/, '');
                    e.detail.event = e.detail.event.replace(/^(\\\\)+/, '');

                    if (event === e.detail.event) {
                        return callback(e.detail.payload, event);
                    }
                });
            },
        };

        window.Native = Native;

        document.addEventListener("native-event", function (e) {
            e.detail.event = e.detail.event.replace(/^(\\\\)+/, '');

            if (window.Livewire) {
                window.Livewire.dispatch('native:' + e.detail.event, e.detail.payload);
            }
        });

        (function() {
            // Add platform identifier class
            document.body.classList.add('nativephp-ios');

            // Disable text selection
            document.body.style.userSelect = "none";
        })();
        """
        let script = WKUserScript(
            source: helper,
            injectionTime: .atDocumentEnd,
            forMainFrameOnly: true
        )
        contentController.addUserScript(script)
    }

    func addSwipeGestureSupport(webView: WKWebView, context: Context) {
        webView.navigationDelegate = context.coordinator
    }

    func updateUIView(_ uiView: WKWebView, context: Context) {
        // No manual insets needed - safeAreaInset handles topbar automatically
        // Bottom nav uses its own safeAreaInset in WebViewLayoutContainer
    }
}

class ConsoleLogger: NSObject, WKScriptMessageHandler {
    func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
        if let body = message.body as? [String: Any],
           let type = body["type"] as? String,
           let logMessage = body["message"] as? String {
            print()
            print("JS \(type): \(logMessage)")
        }
    }
}
