package com.nativephp.mobile.network

import android.view.ViewGroup
import android.webkit.CookieManager
import android.webkit.WebView
import com.nativephp.mobile.bridge.PHPBridge
import com.nativephp.mobile.security.LaravelCookieStore
import com.nativephp.mobile.security.WebCookieMirror
import com.nativephp.mobile.ui.MainActivity

/**
 * Lazily-created owner of the app's WebView + WebViewManager.
 *
 * Native-first boot: an all-native (EDGE) app never constructs this — no
 * Chromium init on the boot path, no sandboxed renderer process for the
 * app's lifetime. It is created on demand the first time a web response
 * actually needs painting: a web start route, an EXIT_WEB from the native
 * runloop, or a WebViewProvider consumer that insists on a WebView.
 *
 * Must be constructed on the main thread.
 */
class WebRenderer(
    activity: MainActivity,
    phpBridge: PHPBridge,
) {
    val webView: WebView = WebView(activity).apply {
        layoutParams = ViewGroup.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.MATCH_PARENT
        )
        settings.mediaPlaybackRequiresUserGesture = false
    }

    val manager: WebViewManager = WebViewManager(activity, webView, phpBridge)

    init {
        manager.setup()

        // A session may already exist from native-only dispatches, which
        // read/write LaravelCookieStore only. Seed the freshly-created
        // Chromium cookie jar from the store so web pages join the same
        // session, then open the mirror for future Set-Cookie responses.
        WebCookieMirror.markWebViewAvailable()
        val jar = CookieManager.getInstance()
        LaravelCookieStore.all().forEach { (name, value) ->
            jar.setCookie("http://127.0.0.1", "$name=$value")
        }
        jar.flush()

        // Activity-owned wiring: AndroidBridge JS interface, dev cache mode,
        // pending safe-area insets, window.Native bootstrap.
        activity.onWebRendererCreated(this)
    }
}
