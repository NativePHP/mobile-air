package com.nativephp.mobile.network

/**
 * "Render a remote WebView app over Jump" — Android port of the iOS
 * `JumpWebViewSession`.
 *
 * When active, [WebViewManager]'s request interception forwards
 * `http://127.0.0.1` requests (pages AND assets) to the remote Jump dev
 * server (`host:port`) instead of the local embedded PHP. The WebView keeps
 * loading `127.0.0.1`, so there is NO change to the rendering surface, no
 * remote origin, and the existing render + JS bridge paths are untouched —
 * responses just come from a different source.
 *
 * Started by the discovery plugin's `JumpBridgeRelay` when `/jump/info`
 * reports (or implies) a WebView app; stopped by the escape hatch / session
 * teardown.
 */
object JumpWebViewSession {
    @Volatile private var _host = ""

    @Volatile private var _port = ""

    @Volatile private var _active = false

    val host: String get() = _host
    val port: String get() = _port
    val isActive: Boolean get() = _active

    @Synchronized
    fun start(host: String, port: String) {
        _host = host
        _port = port
        _active = true
    }

    @Synchronized
    fun stop() {
        _active = false
        _host = ""
        _port = ""
    }
}
