package com.nativephp.mobile.network

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.util.Log
import android.webkit.*
import android.widget.Toast
import android.view.View
import android.view.ViewGroup
import android.widget.FrameLayout
import android.content.pm.ActivityInfo
import android.app.Activity
import android.os.Message
import androidx.webkit.WebViewCompat
import androidx.webkit.WebViewFeature
import com.acsbendi.requestinspectorwebview.RequestInspectorWebViewClient
import com.nativephp.mobile.bridge.PHPBridge
import com.nativephp.mobile.ui.MainActivity
import org.json.JSONObject
import com.nativephp.mobile.security.LaravelSecurity
import java.io.ByteArrayOutputStream
import java.util.concurrent.ConcurrentHashMap

class WebViewManager(
    private val context: Context,
    private val webView: WebView,
    private val phpBridge: PHPBridge,
    // An embedded webview lives INSIDE the native tree (php-mode <webview>
    // element). It must never drive app-level state: no native/web mode
    // flips, no chrome updates from response headers — those belong to the
    // root webview alone.
    private val embedded: Boolean = false
) {
    private val TAG = "PHPMonitor"
    private var fullscreenView: View? = null
    private var customViewCallback: WebChromeClient.CustomViewCallback? = null

    companion object {
        var shared: WebViewManager? = null
    }

    fun setup() {
        configureWebViewSettings()
        setupCookieManager()
        setupWebViewClient()
        setupJavaScriptInterfaces()
        installRequestBodyShimAtDocumentStart()
        WebViewManager.shared = this // 👈 make this instance globally accessible
    }

    /**
     * Run the request body shim before any of the page's own scripts, so a
     * request sent while the page is still loading carries its body too.
     * Without DOCUMENT_START_SCRIPT support the shim only arrives with the
     * onPageFinished injection, and requests sent before that reach PHP with
     * no body.
     */
    private fun installRequestBodyShimAtDocumentStart() {
        if (!WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)) {
            Log.w(TAG, "⚠️ WebView lacks DOCUMENT_START_SCRIPT: the request body shim loads at onPageFinished, " +
                "so requests sent before then reach PHP without a body")
            return
        }
        try {
            WebViewCompat.addDocumentStartJavaScript(
                webView,
                RequestBodyShim.SCRIPT,
                setOf("http://127.0.0.1", "http://localhost")
            )
            Log.d(TAG, "✅ Request body shim installed at document start")
        } catch (e: Exception) {
            Log.w(TAG, "⚠️ Could not install the request body shim at document start: ${e.message}")
        }
    }

    private fun configureWebViewSettings() {
        // Don't clear cache on every setup - let it persist for performance
        // webView.clearCache(true)
        // webView.clearHistory()

        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            allowFileAccess = true
            allowContentAccess = true
            loadsImagesAutomatically = true
            blockNetworkImage = false
            mixedContentMode = WebSettings.MIXED_CONTENT_ALWAYS_ALLOW
            mediaPlaybackRequiresUserGesture = false // Allows autoplay
            setSupportMultipleWindows(true) // Required for fullscreen
            cacheMode = WebSettings.LOAD_CACHE_ELSE_NETWORK // Prefer cache for faster loads
        }

        WebView.setWebContentsDebuggingEnabled(true)
    }

    private fun setupCookieManager() {
        CookieManager.getInstance().apply {
            setAcceptCookie(true)
            setAcceptThirdPartyCookies(webView, true)
        }
    }

    private fun setupWebViewClient() {
        webView.webChromeClient = createWebChromeClient()
        webView.webViewClient = createCustomWebViewClient()
    }

    private fun createWebChromeClient(): WebChromeClient {
        return object : WebChromeClient() {
            override fun onShowCustomView(view: View, callback: CustomViewCallback) {
                fullscreenView?.let { onHideCustomView() }

                fullscreenView = view
                customViewCallback = callback

                (context as? Activity)?.let { activity ->
                    val decorView = activity.window.decorView as FrameLayout
                    decorView.addView(view,
                        FrameLayout.LayoutParams(
                            ViewGroup.LayoutParams.MATCH_PARENT,
                            ViewGroup.LayoutParams.MATCH_PARENT
                        )
                    )
                }

                webView.visibility = View.GONE

                (context as? Activity)?.requestedOrientation =
                    ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE
            }

            override fun onHideCustomView() {
                (context as? Activity)?.let { activity ->
                    val decorView = activity.window.decorView as FrameLayout

                    fullscreenView?.let { decorView.removeView(it) }
                    fullscreenView = null

                    webView.visibility = View.VISIBLE

                    activity.requestedOrientation =
                        ActivityInfo.SCREEN_ORIENTATION_UNSPECIFIED

                    customViewCallback?.onCustomViewHidden()
                    customViewCallback = null
                }
            }

            override fun onConsoleMessage(consoleMessage: ConsoleMessage): Boolean {
                Log.d(
                    "$TAG-Console",
                    "${consoleMessage.message()} -- From line ${consoleMessage.lineNumber()}"
                )
                return true
            }

            override fun onCreateWindow(
                view: WebView,
                isDialog: Boolean,
                isUserGesture: Boolean,
                resultMsg: Message
            ): Boolean {
                // target="_blank" links and window.open() land here because
                // multiple windows are enabled; without this override they are
                // silently dropped. There is no second window in the app, so
                // resolve the URL through a throwaway WebView and route it
                // like a normal navigation: external → system browser,
                // local-server → the main WebView.
                val transport = resultMsg.obj as? WebView.WebViewTransport ?: return false
                val popup = WebView(view.context)
                popup.webViewClient = object : WebViewClient() {
                    override fun shouldOverrideUrlLoading(
                        popupView: WebView,
                        request: WebResourceRequest
                    ): Boolean {
                        val url = request.url.toString()
                        Log.d(TAG, "🪟 onCreateWindow resolved: $url")
                        if ((url.startsWith("http://") || url.startsWith("https://")) &&
                            !url.contains("127.0.0.1") &&
                            !url.contains("localhost")
                        ) {
                            try {
                                val intent = Intent(Intent.ACTION_VIEW, Uri.parse(url))
                                intent.flags = Intent.FLAG_ACTIVITY_NEW_TASK
                                context.startActivity(intent)
                            } catch (e: ActivityNotFoundException) {
                                Toast.makeText(context, "No app can handle this link", Toast.LENGTH_SHORT).show()
                            }
                        } else {
                            webView.loadUrl(url)
                        }
                        popupView.post { popupView.destroy() }
                        return true
                    }
                }
                transport.webView = popup
                resultMsg.sendToTarget()
                return true
            }
        }
    }

    private fun createCustomWebViewClient(): WebViewClient {
        return object : WebViewClient() {
            private val requestInspector = RequestInspectorWebViewClient(webView)
            private val phpHandler = PHPWebViewClient(phpBridge, context as MainActivity)

            override fun shouldOverrideUrlLoading(
                view: WebView,
                request: WebResourceRequest
            ): Boolean {
                val url = request.url.toString()
                val method = request.method
                Log.d("$TAG-DEBUG", "URL: $url, Method: $method")
                Log.d(TAG, "⬆️ shouldOverrideUrlLoading: $url")

                // Handle system URL schemes (tel:, mailto:, sms:, geo:) - open with system handler
                val scheme = request.url.scheme?.lowercase()
                if (scheme in listOf("tel", "mailto", "sms", "geo")) {
                    Log.d("WebView", "📞 Intercepted system URL scheme: $url")
                    val intent = Intent(Intent.ACTION_VIEW, Uri.parse(url))
                    intent.flags = Intent.FLAG_ACTIVITY_NEW_TASK
                    try {
                        context.startActivity(intent)
                    } catch (e: ActivityNotFoundException) {
                        Log.e("WebView", "No app can handle $scheme: links")
                        Toast.makeText(context, "No app can handle this link", Toast.LENGTH_SHORT).show()
                    }
                    return true
                }

                if (url.startsWith("nativephp://")) {
                    Log.d("WebView", "🔗 Intercepted deep link inside WebView: $url")

                    val intent = Intent(Intent.ACTION_VIEW, Uri.parse(url))
                    intent.flags = Intent.FLAG_ACTIVITY_NEW_TASK

                    try {
                        context.startActivity(intent)
                    } catch (e: ActivityNotFoundException) {
                        Toast.makeText(context, "No app can handle this link", Toast.LENGTH_SHORT).show()
                    }

                    return true // prevent WebView from loading it
                }

                // Jump webview-forward session: the served app generates
                // absolute links with ITS host (http://<devhost>:<port>/…).
                // The WebView's origin is 127.0.0.1, so without this those
                // links classify as "external site" below and open the system
                // browser. Rewrite them onto 127.0.0.1 — the interception
                // layer forwards them back to the dev server.
                if (JumpWebViewSession.isActive &&
                    request.url.host == JumpWebViewSession.host &&
                    (if (request.url.port == -1) "80" else request.url.port.toString()) == JumpWebViewSession.port
                ) {
                    val rewritten = "http://127.0.0.1${request.url.encodedPath ?: "/"}" +
                        (request.url.encodedQuery?.let { "?$it" } ?: "")
                    Log.d(TAG, "🛰️ [JUMP-FORWARD] Rewriting session link → $rewritten")
                    view.loadUrl(rewritten)
                    return true
                }

                if ((url.startsWith("http://") || url.startsWith("https://")) &&
                    !url.contains("127.0.0.1") &&
                    !url.contains("localhost") &&
                    request.isForMainFrame
                ) {
                    // This is a navigation request to an external site - open in browser
                    val intent = Intent(Intent.ACTION_VIEW, Uri.parse(url))
                    view.context.startActivity(intent)
                    return true
                }

                // Handle relative URLs (convert to php://)
                if (url.startsWith("/")) {
                    val uri = request.url
                    val fullUrl = "http://127.0.0.1${uri.encodedPath}" +
                        (uri.encodedQuery?.let { "?$it" } ?: "")

                    Log.d(TAG, "🛠️ Rewriting relative URL with query: $fullUrl")
                    view.loadUrl(fullUrl)
                    return true
                }

                return false
            }

            override fun shouldInterceptRequest(
                view: WebView,
                request: WebResourceRequest
            ): WebResourceResponse? {
                val url = request.url.toString()
                val method = request.method

                Log.d(TAG, "🔄 Intercepting $method request to $url")

                request.requestHeaders.forEach { (key, value) ->
                    Log.d("$TAG-Headers", "📋 $key: $value")
                }

                val inspectorResponse = requestInspector.shouldInterceptRequest(view, request)

                if (url.startsWith("http://") && !url.contains(".") && !url.contains("127.0.0.1") && !url.contains("localhost")) {
                    val host = url.substring("http://".length).substringBefore("/")
                    val path = if (url.contains("/")) "/${url.substringAfter("/")}" else "/"
                    val correctedUrl = "http://127.0.0.1/$host$path"

                    Log.d(TAG, "🔄 Correcting malformed URL from $url to $correctedUrl")

                    // Create a modified request with the corrected URL
                    val correctedUri = Uri.parse(correctedUrl)
                    val correctedRequest = object : WebResourceRequest {
                        override fun getUrl(): Uri = correctedUri
                        override fun isForMainFrame(): Boolean = request.isForMainFrame
                        override fun isRedirect(): Boolean = request.isRedirect
                        override fun hasGesture(): Boolean = request.hasGesture()
                        override fun getMethod(): String = request.method
                        override fun getRequestHeaders(): Map<String, String> = request.requestHeaders
                    }

                    // Handle this corrected request normally
                    return shouldInterceptRequest(view, correctedRequest)
                }

                if (!url.contains("127.0.0.1") && !url.contains("localhost")) {
                    // This is an external resource - let the WebView handle it directly
                    Log.d(TAG, "📡 External resource - passing to system: $url")
                    return null // Returning null lets the WebView load it normally
                }

                // Allow Vite dev server (port 5173) to handle its own requests, including WebSocket upgrades for HMR
                if (url.contains(":5173")) {
                    Log.d(TAG, "🔥 Vite dev server request - allowing native WebView handling: $url")
                    return null
                }

                return when {
                    isStaticAssetExtension(url) ||
                            url.contains("_assets") ||
                            url.contains("/js/") ||
                            url.contains("/css/") ||
                            url.contains("/fonts/") ||
                            url.contains("/images/") -> {
                        // Jump webview-forward session: assets live on the
                        // remote dev server, not in the local bundle.
                        if (JumpWebViewSession.isActive) {
                            phpHandler.forwardToRemote(request, null as CapturedBody?)
                        } else {
                            Log.d(TAG, "🖼️ Handling asset request")
                            phpHandler.handleAssetRequest(url, request.requestHeaders)
                        }
                    }
                    // Regular PHP requests
                    url.contains("127.0.0.1") -> {
                        Log.d(TAG, "🌐 Handling PHP request")
                        val requestMethod = request.method.uppercase()
                        val postData: CapturedBody? = if (requestMethod != "GET" &&
                            requestMethod != "HEAD" && requestMethod != "OPTIONS") {
                            val reqId = request.requestHeaders?.entries
                                ?.firstOrNull { it.key.equals("X-NativePHP-Req-Id", ignoreCase = true) }
                                ?.value
                            if (reqId != null) {
                                // Header may contain a comma-joined list if setRequestHeader
                                // was called multiple times on the same XHR. Try each ID.
                                reqId.split(",")
                                    .map { it.trim() }
                                    .firstNotNullOfOrNull { id ->
                                        if (id.isNotEmpty()) phpBridge.consumePostBody(id) else null
                                    }
                            } else if (requestMethod == "POST") {
                                // Native form submission — try full URL first, then path only
                                phpBridge.consumePostBody(url)
                                    ?: phpBridge.consumePostBody(request.url.path ?: "/")
                            } else null
                        } else null
                        // Jump webview-forward session: hand the request
                        // (with any consumed POST body) to the remote dev
                        // server instead of the embedded PHP runtime.
                        if (JumpWebViewSession.isActive) {
                            phpHandler.forwardToRemote(request, postData)
                        } else {
                            phpHandler.handlePHPRequest(request, postData)
                        }
                    }
                    else -> {
                        Log.d(TAG, "↪️ Delegating to system handler: $url")
                        inspectorResponse
                    }
                }
            }

            override fun onPageStarted(view: WebView, url: String, favicon: android.graphics.Bitmap?) {
                super.onPageStarted(view, url, favicon)
                Log.d(TAG, "🚀 Page started loading: $url")

                // A WebView page load means we are (back) in WebView mode. For a
                // Route::native screen the response's native-tree publish re-sets
                // isActive = true (NativeElementBridge), so this is safe; for a
                // plain web route it stays false. Without this, exit-to-web leaves
                // the frozen native tree on screen over the loaded WebView page.
                //
                // Commit-gated EXIT_WEB swap: while pendingWebSwap is set, keep
                // the frozen native tree visible through Chromium init — the
                // flip happens in onPageCommitVisible instead, so the swap never
                // flashes a blank/stale WebView.
                val activity = context as? MainActivity
                if (!embedded && activity?.pendingWebSwap != true) {
                    com.nativephp.mobile.ui.nativerender.NativeUIBridge.isActive.value = false
                }

                if (!embedded) {
                    // Inject safe area insets IMMEDIATELY when page starts loading
                    // This ensures CSS variables are available before DOM parsing
                    activity?.injectSafeAreaInsetsToWebView()
                }
            }

            override fun onPageCommitVisible(view: WebView, url: String) {
                super.onPageCommitVisible(view, url)
                if (embedded) {
                    return
                }
                val activity = context as? MainActivity
                if (activity?.pendingWebSwap == true) {
                    activity.pendingWebSwap = false
                    com.nativephp.mobile.ui.nativerender.NativeUIBridge.isActive.value = false
                }
                // Renderer-agnostic first-content signal (web renderer):
                // the page's first visible commit is honest TTFD.
                activity?.onFirstContent("web-commit")
            }

            override fun onPageFinished(view: WebView, url: String) {
                super.onPageFinished(view, url)
                Log.d(TAG, "✅ Page finished loading: $url")

                // Inject safe area insets again to ensure they're set
                (context as? MainActivity)?.injectSafeAreaInsetsToWebView()

                // Inject JavaScript to capture form submissions and AJAX requests
                injectJavaScript(view)
            }
        }
    }


    private fun injectJavaScript(view: WebView) {
        val jsCode = """
        (function() {
            // 🌐 Native event bridge
            const listeners = {};

            const Native = {
                on: function(eventName, callback) {
                    if (!listeners[eventName]) {
                        listeners[eventName] = [];
                    }
                    listeners[eventName].push(callback);
                },
                off: function(eventName, callback) {
                    if (listeners[eventName]) {
                        listeners[eventName] = listeners[eventName].filter(cb => cb !== callback);
                    }
                },
                dispatch: function(eventName, payload) {
                    const cbs = listeners[eventName] || [];
                    cbs.forEach(cb => cb(payload, eventName));
                }
            };

            window.Native = Native;

            document.addEventListener("native-event", function (e) {
                const eventName = e.detail.event;
                const payload = e.detail.payload;

                window.Native.dispatch(eventName, payload);


            });

            // Request body capture (fetch, XHR, forms). Usually already
            // installed at document start; this is the fallback, and the shim
            // guards itself against running twice.
            var bodyShim = ${RequestBodyShim.SCRIPT.trim().removeSuffix(";")};

            if (window.__nphpCsrfWatch) {
                return bodyShim;
            }
            window.__nphpCsrfWatch = true;

            // Find CSRF token
            function findAndSendCsrfToken() {
                var tokenField = document.querySelector('input[name="_token"]');
                if (tokenField) {
                    AndroidPOST.storeCsrfToken(tokenField.value);
                    return;
                }

                if (window.livewire && window.livewire.csrfToken) {
                    AndroidPOST.storeCsrfToken(window.livewire.csrfToken);
                }
            }

            findAndSendCsrfToken();

            var observer = new MutationObserver(function() {
                findAndSendCsrfToken();
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });

            return bodyShim;
        })();
    """.trimIndent()

        view.evaluateJavascript(jsCode) { result ->
            Log.d(TAG, "JavaScript injection result: $result")
        }
    }


    private fun setupJavaScriptInterfaces() {
        webView.addJavascriptInterface(JSBridge(phpBridge, TAG), "AndroidPOST")
    }

    // Helper methods
    fun isStaticAssetExtension(url: String): Boolean {
        val staticExtensions = listOf(
            ".js", ".css", ".png", ".jpg", ".jpeg", ".gif", ".svg", ".woff",
            ".woff2", ".ttf", ".eot", ".ico", ".json", ".map"
        )
        return staticExtensions.any { url.endsWith(it) || url.contains("$it?") }
    }
}

class JSBridge(private val phpBridge: PHPBridge, private val TAG: String) {

    private class PendingBody(
        val url: String,
        val contentType: String,
        val isForm: Boolean,
        expectedLength: Int
    ) {
        val bytes = ByteArrayOutputStream(expectedLength.coerceIn(32, MAX_PRESIZE))
    }

    private val pending = ConcurrentHashMap<String, PendingBody>()

    companion object {
        private const val MAX_PRESIZE = 16 * 1024 * 1024
    }

    /**
     * Start a request body. [contentType] is what the browser computed for it
     * (a multipart boundary included), [length] the byte count to expect.
     * Form bodies are stored by URL, everything else by the request id the
     * shim puts in the X-NativePHP-Req-Id header.
     */
    @JavascriptInterface
    fun beginBody(key: String, url: String, contentType: String, length: Int, isForm: Boolean) {
        pending[key] = PendingBody(url, contentType, isForm, length)
    }

    /** One base64 chunk of the body, decoded to bytes right away. */
    @JavascriptInterface
    fun appendBody(key: String, base64Chunk: String) {
        val body = pending[key] ?: return
        try {
            body.bytes.write(android.util.Base64.decode(base64Chunk, android.util.Base64.NO_WRAP))
        } catch (e: IllegalArgumentException) {
            Log.e("$TAG-JS", "Bad base64 chunk for $key: ${e.message}")
            pending.remove(key)
        }
    }

    /** The body is complete: keep it for the request that carries it. */
    @JavascriptInterface
    fun commitBody(key: String) {
        val body = pending.remove(key) ?: return
        val captured = CapturedBody(body.bytes.toByteArray(), body.contentType)
        Log.d("$TAG-JS", "📦 Body captured for ${body.url} key=$key (${captured.size} bytes, type=${body.contentType.ifEmpty { "none" }})")

        phpBridge.storePostBody(key, captured)
        if (body.isForm) {
            // Native form submissions can't carry custom headers, so they are
            // matched by URL; also store by path in case only that matches.
            val path = android.net.Uri.parse(body.url).path
            if (path != null && path != key) {
                phpBridge.storePostBody(path, captured)
            }
        }

        val type = body.contentType.lowercase()
        if (type.contains("x-www-form-urlencoded") || type.contains("json")) {
            LaravelSecurity.extractFromPostBody(String(captured.bytes, Charsets.UTF_8))
        }
    }

    /** Text-only capture kept for older injected scripts. */
    @JavascriptInterface
    fun logPostData(data: String, url: String, headers: String, requestId: String) {
        Log.d("$TAG-JS", "📦 POST data captured (fetch/XHR) for: $url reqId=$requestId (length=${data.length})")

        // Store by unique request ID — fetch/XHR requests carry the ID as a header
        phpBridge.storePostData(requestId, data)

        // Try to extract CSRF token
        LaravelSecurity.extractFromPostBody(data)
    }

    /** Text-only form capture kept for older injected scripts. */
    @JavascriptInterface
    fun logFormPostData(data: String, url: String) {
        // Native form submissions can't carry custom headers, so store by URL
        // shouldInterceptRequest will look up by URL in the fallback path
        val path = android.net.Uri.parse(url).path ?: url
        Log.d("$TAG-JS", "📦 POST data captured (form) for: $url path=$path (length=${data.length})")

        phpBridge.storePostData(url, data)
        // Also store by path in case shouldInterceptRequest receives the full URL
        if (path != url) {
            phpBridge.storePostData(path, data)
        }

        // Try to extract CSRF token
        LaravelSecurity.extractFromPostBody(data)
    }

    @JavascriptInterface
    fun storeCsrfToken(token: String) {
        Log.d("$TAG-CSRF", "🔑 JS provided token: $token")
        LaravelSecurity.set(token)
    }

    private fun extractCsrfToken(postData: String?) {
        if (postData.isNullOrEmpty()) return

        try {
            // Check if it's JSON
            if (postData.startsWith("{")) {
                val jsonObj = JSONObject(postData)

                // Look for _token field
                if (jsonObj.has("_token")) {
                    val token = jsonObj.getString("_token")
                    Log.d("$TAG-CSRF", "🔑 Extracted token from POST data: $token")
                    LaravelSecurity.set(token)
                }
            }
            // Check for form data format
            else if (postData.contains("_token=")) {
                val parts = postData.split("&")
                for (part in parts) {
                    if (part.startsWith("_token=")) {
                        val token = part.substring("_token=".length)
                        Log.d("$TAG-CSRF", "🔑 Extracted token from form data: $token")
                        LaravelSecurity.set(token)
                        break
                    }
                }
            }
        } catch (e: Exception) {
            Log.e("$TAG-CSRF", "⚠️ Error extracting CSRF token: ${e.message}")
        }
    }
}