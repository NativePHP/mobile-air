package com.nativephp.mobile.security

import android.webkit.CookieManager

/**
 * Gated mirror of Laravel session cookies into the WebView CookieManager.
 *
 * `CookieManager.getInstance()` loads the Chromium WebView provider into the
 * process — on a native-first boot that alone claws back part of the
 * "no Chromium" win. PHP requests never read the CookieManager (request
 * cookies come from LaravelCookieStore), so mirroring is pointless until a
 * WebView actually exists. All writes are dropped until WebRenderer creation
 * calls [markWebViewAvailable] and back-fills the jar from the store.
 */
object WebCookieMirror {
    // CookieManager silently drops Secure cookies set for an HTTP URL. Chromium
    // treats http://127.0.0.1 as trustworthy, so cookies written against HTTPS
    // remain available on the HTTP origin the app is served from.
    internal const val COOKIE_ORIGIN = "https://127.0.0.1"

    @Volatile
    private var available = false

    fun markWebViewAvailable() {
        available = true
    }

    fun set(cookieHeaderValue: String) {
        if (!available) return
        CookieManager.getInstance().setCookie(COOKIE_ORIGIN, cookieHeaderValue)
    }

    fun flush() {
        if (!available) return
        CookieManager.getInstance().flush()
    }

    fun clearAll() {
        if (!available) return
        CookieManager.getInstance().apply {
            removeAllCookies(null)
            flush()
        }
    }
}
