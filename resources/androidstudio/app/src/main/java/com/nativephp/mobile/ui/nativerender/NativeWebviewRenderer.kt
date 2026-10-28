package com.nativephp.mobile.ui.nativerender

import android.annotation.SuppressLint
import android.graphics.Bitmap
import android.net.Uri
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.webkit.WebChromeClient
import android.webkit.CookieManager
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.viewinterop.AndroidView
import com.nativephp.mobile.bridge.LaravelEnvironment
import com.nativephp.mobile.bridge.PHPBridge
import com.nativephp.mobile.bridge.WebviewPHPRuntime
import com.nativephp.mobile.network.WebViewManager
import com.nativephp.mobile.ui.MainActivity

/**
 * Locked-down WebView primitive, the renderer for the `webview` element.
 *
 * It ships with the shell. Releases of nativephp/mobile-ui before the move
 * still carry their own copy (`native_ui.ui.WebviewRenderer`); plugin
 * renderers register after this one, so that copy wins while it is installed.
 *
 * Defaults are paranoid by design: JS off, no DOM storage, no file access,
 * no JS bridge to the host, no new windows, mixed content blocked, cookies
 * non-persistent (cleared on attach). Hosts opt back into individual
 * capabilities via attributes (`javascript`, `dom-storage`) on the Blade
 * tag.
 *
 * Top-frame navigations fire `on_navigated(url)` once committed. External
 * schemes (mailto, tel, intent, …) and target=_blank attempts are denied.
 *
 * The `php` attribute swaps the sandbox for the app's own enriched Laravel
 * webview (see [PhpWebView]): pages served per-request by the embedded PHP
 * runtime, with the full JS bridge and the process-wide cookie session.
 */
object NativeWebviewRenderer {

    @Composable
    fun Render(node: NativeUINode, modifier: Modifier) {
        if (node.props.getBool("php", false)) {
            PhpWebView(node, modifier)
            return
        }

        // Snapshot of the values we care about. `remember(key)` rebuilds
        // the WebView only when src/html actually change — avoids
        // recreating the surface on every recomposition (which would
        // reset scroll position and flicker).
        val src = node.props.getString("src", "")
        val html = node.props.getString("html", "")
        val jsEnabled = node.props.getBool("javascript", false)
        val domStorage = node.props.getBool("dom_storage", false)
        val onNavigatedCb = node.props.getCallbackId("on_navigated")
        val nodeId = node.id

        // Single content signature drives WebView reload decisions. We
        // don't include callback ids — those only affect dispatch, not
        // displayed content.
        val contentKey = remember(src, html, jsEnabled, domStorage) {
            "$src$html$jsEnabled$domStorage"
        }

        AndroidView(
            modifier = modifier.clipToBounds(),
            factory = { ctx ->
                @SuppressLint("SetJavaScriptEnabled")
                val webView = WebView(ctx).apply {
                    applyLockdownSettings(settings, jsEnabled = jsEnabled, domStorage = domStorage)

                    // No persistent cookies. CookieManager is process-
                    // wide, so clearing here also wipes cookies a sibling
                    // webview tried to set — acceptable while everything
                    // is locked down by default.
                    CookieManager.getInstance().setAcceptCookie(true)
                    CookieManager.getInstance().setAcceptThirdPartyCookies(this, false)

                    webViewClient = LockdownClient(onNavigatedCb, nodeId)
                    webChromeClient = NoPopupChromeClient()

                    setBackgroundColor(0)
                }

                webView.loadContent(src, html)
                webView
            },
            update = { webView ->
                applyLockdownSettings(
                    webView.settings,
                    jsEnabled = jsEnabled,
                    domStorage = domStorage
                )
                (webView.webViewClient as? LockdownClient)?.let {
                    it.navigatedCallbackId = onNavigatedCb
                    it.nodeId = nodeId
                }
                if (webView.tag != contentKey) {
                    webView.tag = contentKey
                    webView.loadContent(src, html)
                }
            },
            onRelease = { webView ->
                webView.stopLoading()
                webView.webViewClient = WebViewClient()
                webView.webChromeClient = null
                webView.destroy()
            }
        )
    }
}

/**
 * Enriched-mode webview: an independent WebView wired exactly like the app's
 * main Laravel webview. [WebViewManager.setup] provides the full stack —
 * settings, cookie manager, the request-intercepting client that answers
 * `http://127.0.0.1` from the embedded PHP runtime, the POST-capture JS
 * interface, and the `window.Native` injection.
 *
 * `embedded = true` keeps the stock client away from app-level state: it
 * never drops out of native-UI mode on a page load, which would unmount this
 * very renderer. [PhpEmbedClient] delegates to the stock client (keeping the
 * POST-inspector script injection it performs) and adds `on_navigated`.
 *
 * `setup()` also claims the process-wide [WebViewManager.shared] slot, which
 * belongs to the app's root webview, so it is restored immediately after.
 *
 * `src` is an app route path in this mode; anything not starting with `/`
 * (including empty) falls back to the app's configured start URL.
 */
@Composable
private fun PhpWebView(node: NativeUINode, modifier: Modifier) {
    val src = node.props.getString("src", "")
    val onNavigatedCb = node.props.getCallbackId("on_navigated")
    val nodeId = node.id

    AndroidView(
        modifier = modifier.clipToBounds(),
        factory = { ctx ->
            val activity = (ctx as? MainActivity) ?: MainActivity.instance
            if (activity == null) {
                // No shell activity — bare view, nothing to serve
                WebView(ctx)
            } else {
                val webView = WebView(activity)

                // Transparent until Chromium's first paint — a fresh WebView
                // surface otherwise renders opaque black over the window for
                // a beat (the "black flash"), hiding the native siblings.
                webView.setBackgroundColor(android.graphics.Color.TRANSPARENT)

                // Dedicated PHP context for this webview — phpExecutor is parked
                // in the native screen's event loop and can never serve our
                // requests. Released via PhpEmbedClient in onRelease.
                val bridge = PHPBridge(activity)
                val phpRuntime = WebviewPHPRuntime(bridge)
                bridge.dedicatedWebviewRuntime = phpRuntime

                val previousShared = WebViewManager.shared
                WebViewManager(activity, webView, bridge, embedded = true).setup()
                WebViewManager.shared = previousShared

                webView.webViewClient = PhpEmbedClient(webView.webViewClient, onNavigatedCb, nodeId, phpRuntime)

                val path = phpPath(src, activity)
                webView.tag = path
                webView.loadUrl("http://127.0.0.1$path")
                webView
            }
        },
        update = { webView ->
            (webView.webViewClient as? PhpEmbedClient)?.let {
                it.navigatedCallbackId = onNavigatedCb
                it.nodeId = nodeId
            }
            val activity = (webView.context as? MainActivity) ?: MainActivity.instance
            if (activity != null) {
                val path = phpPath(src, activity)
                if (webView.tag != path) {
                    webView.tag = path
                    webView.loadUrl("http://127.0.0.1$path")
                }
            }
        },
        onRelease = { webView ->
            // Stop this webview's dedicated PHP thread the moment the
            // webview leaves the view hierarchy.
            (webView.webViewClient as? PhpEmbedClient)?.phpRuntime?.release()
            webView.stopLoading()
            webView.webViewClient = WebViewClient()
            webView.webChromeClient = null
            webView.destroy()
        }
    )
}

private fun phpPath(src: String, context: android.content.Context): String =
    if (src.startsWith("/")) src else LaravelEnvironment.getStartURL(context)

private class PhpEmbedClient(
    private val inner: WebViewClient,
    var navigatedCallbackId: Int,
    var nodeId: Int,
    val phpRuntime: WebviewPHPRuntime? = null
) : WebViewClient() {

    override fun shouldInterceptRequest(
        view: WebView,
        request: WebResourceRequest
    ): WebResourceResponse? = inner.shouldInterceptRequest(view, request)

    override fun shouldOverrideUrlLoading(
        view: WebView,
        request: WebResourceRequest
    ): Boolean = inner.shouldOverrideUrlLoading(view, request)

    override fun onPageStarted(view: WebView, url: String, favicon: Bitmap?) {
        inner.onPageStarted(view, url, favicon)
    }

    override fun onPageFinished(view: WebView, url: String?) {
        inner.onPageFinished(view, url)
    }

    override fun onPageCommitVisible(view: WebView?, url: String?) {
        inner.onPageCommitVisible(view, url)
        // Surface-geometry nudge: a WebView created mid-composition can
        // paint its layer at a stale offset until the next layout pass.
        view?.requestLayout()
        val resolved = url.orEmpty()
        if (navigatedCallbackId != 0 && resolved.isNotEmpty()) {
            NativeUIBridge.sendTextChangeEvent(navigatedCallbackId, nodeId, resolved)
        }
    }
}

private fun WebView.loadContent(src: String, html: String) {
    if (html.isNotEmpty()) {
        // null baseURL → opaque origin. Embedded HTML can't issue
        // same-origin requests against the host app's URLs.
        loadDataWithBaseURL(null, html, "text/html", "utf-8", null)
        return
    }
    if (src.isEmpty()) return
    if (!isLoadableUrl(src)) return
    loadUrl(src)
}

private fun applyLockdownSettings(
    settings: WebSettings,
    jsEnabled: Boolean,
    domStorage: Boolean
) {
    settings.javaScriptEnabled = jsEnabled
    settings.domStorageEnabled = domStorage
    settings.allowFileAccess = false
    settings.allowContentAccess = false
    @Suppress("DEPRECATION")
    settings.allowFileAccessFromFileURLs = false
    @Suppress("DEPRECATION")
    settings.allowUniversalAccessFromFileURLs = false
    settings.mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
    settings.javaScriptCanOpenWindowsAutomatically = false
    settings.setSupportMultipleWindows(false)
    settings.mediaPlaybackRequiresUserGesture = true
    settings.setGeolocationEnabled(false)
    // Deprecated in API 33 — the Web SQL Database API it gates was removed from
    // WebView entirely. Suppressed rather than deleted: `minSdk` is 26, and on
    // an older device carrying an older WebView the setting still does
    // something, so dropping it would quietly weaken the lockdown on exactly
    // the devices that need it most. Same reasoning as the two suppressions
    // above.
    @Suppress("DEPRECATION")
    settings.databaseEnabled = false
    settings.cacheMode = WebSettings.LOAD_NO_CACHE
}

private class LockdownClient(
    var navigatedCallbackId: Int,
    var nodeId: Int
) : WebViewClient() {

    override fun shouldOverrideUrlLoading(
        view: WebView,
        request: WebResourceRequest
    ): Boolean {
        val url: Uri = request.url ?: return true
        if (!request.isForMainFrame) {
            return false
        }
        if (!isLoadableScheme(url.scheme)) {
            // Drop external-scheme top-frame navigation (mailto, tel,
            // intent, …). Returning `true` cancels the load instead of
            // dispatching it to the system handler.
            return true
        }
        return false
    }

    override fun onPageStarted(view: WebView?, url: String?, favicon: Bitmap?) {
        // onPageStarted fires before the server has responded; we
        // prefer onPageCommitVisible / onPageFinished. Override left
        // empty so the default behavior doesn't proxy through.
    }

    override fun onPageCommitVisible(view: WebView?, url: String?) {
        // Most reliable "the user is now looking at this URL" hook on
        // Android. Mirrors iOS's `didCommit`.
        val resolved = url.orEmpty()
        if (navigatedCallbackId != 0 && resolved.isNotEmpty()) {
            NativeUIBridge.sendTextChangeEvent(navigatedCallbackId, nodeId, resolved)
        }
    }
}

private class NoPopupChromeClient : WebChromeClient() {
    override fun onCreateWindow(
        view: WebView,
        isDialog: Boolean,
        isUserGesture: Boolean,
        resultMsg: android.os.Message?
    ): Boolean {
        // target=_blank / window.open() → denied. Returning false drops
        // the new-window request silently.
        return false
    }
}

private fun isLoadableScheme(scheme: String?): Boolean {
    if (scheme.isNullOrEmpty()) return false
    val s = scheme.lowercase()
    return s == "https" || s == "http" || s == "data" || s == "about"
}

private fun isLoadableUrl(url: String): Boolean {
    val parsed = Uri.parse(url)
    return isLoadableScheme(parsed.scheme)
}
