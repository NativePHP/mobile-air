import SwiftUI
import UIKit
@preconcurrency import WebKit

/// Locked-down WKWebView primitive, the renderer for the `webview` element.
///
/// It ships with the shell. Releases of nativephp/mobile-ui before the move
/// still carry their own copy (`NativeUIWebviewRenderer`); plugin renderers
/// register after this one, so that copy wins while it is installed.
///
/// Defaults are paranoid by design: JS off, no DOM storage, no link previews,
/// no JS bridge to the host, no back/forward swipe, no new windows, no
/// mixed content, non-persistent data store, media requires a user gesture.
/// Hosts opt back into individual capabilities via attributes
/// (`javascript`, `dom-storage`) on the Blade tag.
///
/// Top-frame navigations fire `on_navigated(url)` once committed. External
/// schemes (mailto, tel, sms, …) and target=_blank attempts are denied.
///
/// Two mode attributes change the posture entirely:
/// - `php` — swaps the sandbox for the app's own enriched Laravel webview
///   (`PHPWebviewContainer` below): served over the `php://` scheme by the
///   embedded runtime, sharing the shell webview's session store and
///   `window.Native` bridge scripts.
/// - `fullscreen` — the element arrives with fill layout from the PHP side;
///   here it additionally extends behind the safe areas, matching the old
///   v3 default-webview presentation.
struct NativeWebviewRenderer: View {
    let node: NativeUINode

    var body: some View {
        let content = Group {
            if node.props.getBool("php", default: false) {
                PHPWebviewContainer(node: node)
            } else {
                SandboxWebviewContainer(node: node)
            }
        }

        if node.props.getBool("fullscreen", default: false) {
            content.ignoresSafeArea(.container, edges: .all)
        } else {
            content
        }
    }
}

/// Enriched-mode container: an independent WKWebView wired exactly like the
/// shell's classic Laravel webview (`WebView.makeUIView` in ContentView).
/// Content is answered per-request by the embedded PHP runtime through
/// `PHPSchemeHandler`; `WebView.dataStore` is shared so this instance rides
/// the same Laravel session as the app's root webview, and
/// `WebView.installNativeHelpers` adds the same user scripts (safe-area CSS
/// variables + `window.Native`).
///
/// Unlike the shell's coordinator, ours never toggles `NativeUIBridge`
/// web/native mode on navigation — this webview lives *inside* the native
/// tree, so page loads here must not unmount it.
private struct PHPWebviewContainer: UIViewRepresentable {
    let node: NativeUINode

    func makeCoordinator() -> Coordinator {
        Coordinator(navigatedCallbackId: node.props.getCallbackId("on_navigated"),
                    nodeId: node.id)
    }

    /// Stop this webview's dedicated PHP thread the moment the webview
    /// leaves the view hierarchy — contexts are per-webview resources.
    static func dismantleUIView(_ uiView: WKWebView, coordinator: Coordinator) {
        coordinator.phpRuntime?.release()
        coordinator.phpRuntime = nil
    }

    func makeUIView(context: Context) -> WKWebView {
        // Dedicated PHP context for this webview — the persistent runtime's
        // queue is parked in the native screen's event loop and can never
        // serve our php:// requests. Released in dismantleUIView.
        let runtime = WebviewPHPRuntime()
        context.coordinator.phpRuntime = runtime

        let schemeHandler = PHPSchemeHandler()
        schemeHandler.dedicatedRuntime = runtime

        let config = WKWebViewConfiguration()
        config.setURLSchemeHandler(schemeHandler, forURLScheme: "php")
        config.websiteDataStore = WebView.dataStore
        config.allowsInlineMediaPlayback = true

        let webView = WKWebView(frame: .zero, configuration: config)
        webView.navigationDelegate = context.coordinator
        webView.scrollView.contentInsetAdjustmentBehavior = .never
        webView.backgroundColor = .clear
        webView.isOpaque = false

        WebView.installNativeHelpers(on: webView)

        let path = startPath()
        context.coordinator.lastPath = path
        load(path, into: webView)
        return webView
    }

    func updateUIView(_ webView: WKWebView, context: Context) {
        let path = startPath()
        if context.coordinator.lastPath != path {
            context.coordinator.lastPath = path
            load(path, into: webView)
        }
        context.coordinator.navigatedCallbackId = node.props.getCallbackId("on_navigated")
        context.coordinator.nodeId = node.id
    }

    /// `src` is an app route path in php mode; anything not starting with
    /// `/` (including empty) falls back to the app's configured start URL.
    private func startPath() -> String {
        let src = node.props.getString("src")
        return src.hasPrefix("/") ? src : NativePHPApp.getStartURL()
    }

    private func load(_ path: String, into webView: WKWebView) {
        guard let url = URL(string: "php://127.0.0.1" + path) else {
            print("[NativePHP] webview(php): unloadable path '\(path)'")
            return
        }
        print("[NativePHP] webview(php): loading \(url.absoluteString)")
        webView.load(URLRequest(url: url))
    }

    final class Coordinator: NSObject, WKNavigationDelegate {
        var navigatedCallbackId: Int
        var nodeId: Int
        var lastPath: String = ""
        var phpRuntime: WebviewPHPRuntime?

        init(navigatedCallbackId: Int, nodeId: Int) {
            self.navigatedCallbackId = navigatedCallbackId
            self.nodeId = nodeId
        }

        func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) {
            print("[NativePHP] webview(php): provisional load failed — \(error)")
        }

        func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) {
            print("[NativePHP] webview(php): load failed — \(error)")
        }

        func webViewWebContentProcessDidTerminate(_ webView: WKWebView) {
            // Matches the RunningBoard entitlement chatter seen when helper
            // processes die. Reload once so a transient kill self-heals.
            print("[NativePHP] webview(php): content process terminated — reloading")
            webView.reload()
        }

        func webView(
            _ webView: WKWebView,
            decidePolicyFor navigationAction: WKNavigationAction,
            decisionHandler: @escaping (WKNavigationActionPolicy) -> Void
        ) {
            guard let url = navigationAction.request.url else {
                print("[NativePHP] webview(php): policy — nil URL, cancel")
                decisionHandler(.cancel)
                return
            }

            let isTopFrame = navigationAction.targetFrame?.isMainFrame ?? true
            if !isTopFrame {
                decisionHandler(.allow)
                return
            }

            switch url.scheme?.lowercased() {
            case "php", "about", "data":
                print("[NativePHP] webview(php): policy — allow \(url.absoluteString)")
                decisionHandler(.allow)
            default:
                // Links out of the app (https, mailto, tel, …) go to the
                // system, mirroring the classic webview's behavior.
                print("[NativePHP] webview(php): policy — external cancel \(url.absoluteString)")
                UIApplication.shared.open(url)
                decisionHandler(.cancel)
            }
        }

        func webView(_ webView: WKWebView, didStartProvisionalNavigation navigation: WKNavigation!) {
            print("[NativePHP] webview(php): provisional navigation started — \(webView.url?.absoluteString ?? "nil")")
        }

        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
            print("[NativePHP] webview(php): did finish — \(webView.url?.absoluteString ?? "nil")")
        }

        func webView(_ webView: WKWebView, didCommit navigation: WKNavigation!) {
            guard navigatedCallbackId != 0,
                  let url = webView.url?.absoluteString else { return }
            NativeElementBridge.sendTextChangeEvent(navigatedCallbackId, nodeId: nodeId, text: url)
        }
    }
}

private struct SandboxWebviewContainer: UIViewRepresentable {
    let node: NativeUINode

    func makeCoordinator() -> Coordinator {
        Coordinator(navigatedCallbackId: node.props.getCallbackId("on_navigated"),
                    nodeId: node.id)
    }

    func makeUIView(context: Context) -> WKWebView {
        let config = WKWebViewConfiguration()

        // Non-persistent: cookies / cache die with the view, so an
        // embedded page can't read state left by another embedded page
        // (or the host browser session). Hosts that need persistence
        // can graduate to a per-host data store later.
        config.websiteDataStore = .nonPersistent()

        // Opt-in JS. Off by default — most embeds the user controls
        // don't need it, and turning it on globally surrenders the
        // strongest single mitigation we have.
        let prefs = WKWebpagePreferences()
        prefs.allowsContentJavaScript = node.props.getBool("javascript", default: false)
        config.defaultWebpagePreferences = prefs

        // Block media autoplay — keeps third-party embeds from ringing
        // audio in the user's pocket on load.
        config.mediaTypesRequiringUserActionForPlayback = .all

        // No <input type=file> handler wired up; leave default.

        let webView = WKWebView(frame: .zero, configuration: config)
        webView.navigationDelegate = context.coordinator
        webView.uiDelegate = context.coordinator
        webView.allowsBackForwardNavigationGestures = false
        webView.allowsLinkPreview = false
        webView.backgroundColor = .clear
        webView.isOpaque = false
        webView.scrollView.bounces = true

        loadContent(into: webView)
        context.coordinator.attach(webView)
        return webView
    }

    func updateUIView(_ webView: WKWebView, context: Context) {
        let newSig = contentSignature()
        if context.coordinator.lastContentSignature != newSig {
            context.coordinator.lastContentSignature = newSig
            loadContent(into: webView)
        }
        context.coordinator.navigatedCallbackId = node.props.getCallbackId("on_navigated")
        context.coordinator.nodeId = node.id
    }

    private func contentSignature() -> String {
        let src = node.props.getString("src")
        let html = node.props.getString("html")
        return src + "\u{1F}" + html
    }

    private func loadContent(into webView: WKWebView) {
        let src = node.props.getString("src")
        let html = node.props.getString("html")

        if !html.isEmpty {
            // baseURL = nil → opaque origin. The HTML can't `fetch()` or
            // navigate to the host app's data on a same-origin basis.
            webView.loadHTMLString(html, baseURL: nil)
            return
        }

        guard !src.isEmpty, let url = URL(string: src) else { return }
        guard isLoadableScheme(url.scheme) else { return }
        webView.load(URLRequest(url: url))
    }

    final class Coordinator: NSObject, WKNavigationDelegate, WKUIDelegate {
        var navigatedCallbackId: Int
        var nodeId: Int
        var lastContentSignature: String = ""
        weak var webView: WKWebView?

        init(navigatedCallbackId: Int, nodeId: Int) {
            self.navigatedCallbackId = navigatedCallbackId
            self.nodeId = nodeId
        }

        func attach(_ webView: WKWebView) {
            self.webView = webView
        }

        // MARK: WKNavigationDelegate

        func webView(
            _ webView: WKWebView,
            decidePolicyFor navigationAction: WKNavigationAction,
            decisionHandler: @escaping (WKNavigationActionPolicy) -> Void
        ) {
            guard let url = navigationAction.request.url else {
                decisionHandler(.cancel)
                return
            }

            // Allow same-origin subresources (`navigationType == .other`
            // covers XHR / `<img>` / `<iframe>` loads) without
            // restriction — only top-frame navigations are gated.
            let isTopFrame = navigationAction.targetFrame?.isMainFrame ?? true
            if !isTopFrame {
                decisionHandler(.allow)
                return
            }

            if !isLoadableScheme(url.scheme) {
                decisionHandler(.cancel)
                return
            }

            decisionHandler(.allow)
        }

        func webView(_ webView: WKWebView, didCommit navigation: WKNavigation!) {
            // didCommit fires after the server has accepted the request
            // and we know the final URL. Fires once per top-frame
            // navigation; intermediate redirects don't fire it.
            guard navigatedCallbackId != 0,
                  let url = webView.url?.absoluteString else { return }
            NativeElementBridge.sendTextChangeEvent(navigatedCallbackId, nodeId: nodeId, text: url)
        }

        func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) {
            print("[NativePHP] webview: provisional load failed — \(error)")
        }

        func webViewWebContentProcessDidTerminate(_ webView: WKWebView) {
            print("[NativePHP] webview: content process terminated — reloading")
            webView.reload()
        }

        // MARK: WKUIDelegate

        func webView(
            _ webView: WKWebView,
            createWebViewWith configuration: WKWebViewConfiguration,
            for navigationAction: WKNavigationAction,
            windowFeatures: WKWindowFeatures
        ) -> WKWebView? {
            // target=_blank / window.open() → silently denied.
            // Returning nil drops the request rather than escalating
            // out of the embedded view.
            return nil
        }
    }
}

private func isLoadableScheme(_ scheme: String?) -> Bool {
    guard let scheme = scheme?.lowercased() else { return false }
    return scheme == "https" || scheme == "http" || scheme == "data" || scheme == "about"
}
