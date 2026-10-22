package com.nativephp.mobile.network

import android.util.Log
import android.webkit.*
import java.io.ByteArrayInputStream
import java.io.BufferedInputStream
import android.content.Context
import java.io.File
import android.net.Uri
import com.nativephp.mobile.bridge.PHPBridge
import com.nativephp.mobile.security.LaravelCookieStore
import com.nativephp.mobile.security.LaravelSecurity


/**
 * PHPWebViewClient that extends RequestInspectorWebViewClient to handle PHP requests
 * while also getting the benefit of request inspection.
 */
class PHPWebViewClient(
    private val phpBridge: PHPBridge,
    private val context: Context
) {
    companion object {
        private const val TAG = "PHPRequestHandler"
        private const val CR: Byte = 13
        private const val LF: Byte = 10
    }

    fun handleAssetRequest(url: String, requestHeaders: Map<String, String> = emptyMap()): WebResourceResponse {
        val path = when {
            url.contains("/_assets/") -> {
                url.substring(url.indexOf("_assets/") + 8)
            }
            url.startsWith("http://127.0.0.1/") || url.startsWith("https://127.0.0.1/") -> {
                // Root-based URL pattern
                val startIndex = url.indexOf("127.0.0.1/") + 10
                url.substring(startIndex)
            }
            else -> {
                // Fallback
                url.substring(url.lastIndexOf("/") + 1)
            }
        }

        // Remove query parameters for file lookup but keep them for logging
        val cleanPath = path.split("?")[0]
        Log.d(TAG, "🗂️ Handling asset request: $path")

        return try {
            // Get Laravel public path
            val laravelPublicPath = phpBridge.getLaravelPublicPath()

            // Try multiple possible locations for the asset
            val possiblePaths = listOf(
                "$laravelPublicPath/$path",                // Direct path with query
                "$laravelPublicPath/$cleanPath",           // Direct path without query
                "$laravelPublicPath/vendor/$cleanPath",    // Vendor path
                "$laravelPublicPath/build/$cleanPath",      // Build path
            )

            // Log all paths we're trying
            Log.d(TAG, "🔍 Checking paths: ${possiblePaths.joinToString()}")

            // Try each path
            val assetFile = possiblePaths.firstOrNull { File(it).exists() }?.let { File(it) }

            if (assetFile != null && assetFile.exists()) {
                Log.d(TAG, "✅ Found asset at: ${assetFile.absolutePath}")

                // Determine MIME type
                val mimeType = guessMimeType(cleanPath)
                val fileSize = assetFile.length()

                // Create appropriate response headers
                val responseHeaders = mutableMapOf<String, String>()
                responseHeaders["Content-Type"] = mimeType
                responseHeaders["Cache-Control"] = "max-age=86400, public" // 1 day cache

                // Special handling for different file types
                when {
                    // CSS files
                    cleanPath.endsWith(".css") -> {
                        Log.d(TAG, "📋 Serving CSS file")
                        responseHeaders["Content-Type"] = "text/css"
                    }
                    // JavaScript files
                    cleanPath.endsWith(".js") -> {
                        Log.d(TAG, "📋 Serving JavaScript file")
                        responseHeaders["Content-Type"] = "application/javascript"
                    }
                    // Font files
                    cleanPath.endsWith(".woff") || cleanPath.endsWith(".woff2") ||
                            cleanPath.endsWith(".ttf") || cleanPath.endsWith(".eot") -> {
                        Log.d(TAG, "📋 Serving font file")
                        // Keep font MIME type from guessMimeType
                        responseHeaders["Access-Control-Allow-Origin"] = "*" // Allow cross-origin font loading
                    }
                }

                Log.d(TAG, "📋 Serving with MIME type: ${responseHeaders["Content-Type"]}")
                responseHeaders["Content-Length"] = fileSize.toString()

                // Use BufferedInputStream with 1MB buffer for efficient streaming (matching iOS)
                // Note: We don't advertise Accept-Ranges because the stream doesn't support true seeking
                // Android WebView handles progressive loading internally
                val bufferedStream = BufferedInputStream(assetFile.inputStream(), 1024 * 1024)

                WebResourceResponse(
                    responseHeaders["Content-Type"] ?: "application/octet-stream",
                    "UTF-8",
                    200,
                    "OK",
                    responseHeaders,
                    bufferedStream
                )
            } else {
                // If static file not found, try handling via PHP
                Log.d(TAG, "🔄 Asset not found in filesystem, trying PHP handler")

                // Use PHP to handle the asset
                val phpRequest = PHPRequest(
                    url = "/$path",
                    method = "GET",
                    body = "",
                    headers = mapOf("Accept" to "*/*"),
                    queryString = Uri.parse(url).encodedQuery ?: ""
                )

                val response = parseRawResponse(phpBridge.handleLaravelRequestBytes(phpRequest))
                Log.d(TAG, "RESPONSE HEADERS: ${response.headers}")

                if (response.status == 200) {
                    val (mime, charset) = mimeAndCharset(response.headers["Content-Type"], guessMimeType(cleanPath))
                    Log.d(TAG, "✅ Asset served via PHP: $mime (${response.bodyLength} bytes)")
                    WebResourceResponse(
                        mime,
                        charset,
                        response.status,
                        response.reason,
                        response.headersForWebView(),
                        response.bodyStream()
                    )
                } else {
                    val statusCode = response.status
                    Log.d(TAG, "❌ Asset not found via PHP: $path (Status: $statusCode)")
                    errorResponse(404, "Asset not found: $path")
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "⚠️ Error loading asset: $path", e)
            errorResponse(500, "Error loading asset: ${e.message}")
        }
    }

    /** Text body wrapper kept for older callers. */
    fun handlePHPRequest(
        request: WebResourceRequest,
        postData: String?,
        redirectCount: Int = 0
    ): WebResourceResponse = handlePHPRequest(
        request,
        postData?.let { CapturedBody(it.toByteArray(Charsets.UTF_8)) },
        redirectCount
    )

    /**
     * Serve a 127.0.0.1 request from PHP. [body] is the request body exactly
     * as the page sent it, with its content type; the response body goes back
     * to the WebView as the bytes PHP wrote, not re-encoded or trimmed.
     */
    fun handlePHPRequest(
        request: WebResourceRequest,
        body: CapturedBody?,
        redirectCount: Int = 0
    ): WebResourceResponse {
        val requestStart = System.currentTimeMillis()
        val path = request.url.encodedPath ?: "/"

        if (redirectCount > 10) {
            Log.e(TAG, "❌ Too many redirects")
            return errorResponse(500, "Too many redirects")
        }

        val headers = HashMap<String, String>(request.requestHeaders)
        headers.keys.removeAll { it.equals("X-NativePHP-Req-Id", ignoreCase = true) }

        // ✅ Apply CSRF token and cookies
        LaravelSecurity.applyToHeaders(headers)
        headers["Cookie"] = LaravelCookieStore.asCookieHeader()
        LaravelCookieStore.logAll()

        Log.d(TAG, "📤 Final request headers: $headers")

        val normalizedPath = when {
            path.startsWith("//") -> path.substring(1)
            else -> path
        }
        val method = request.method.uppercase()

        val hasBody = body != null && method != "GET" && method != "HEAD"
        val phpRequest = PHPRequest(
            url = normalizedPath,
            method = request.method,
            body = "",
            headers = headers,
            queryString = request.url.encodedQuery ?: "",
            bodyBytes = if (hasBody) body!!.bytes else null,
            bodyContentType = if (hasBody) body!!.contentType else null
        )

        val prepTime = System.currentTimeMillis() - requestStart
        val phpStart = System.currentTimeMillis()

        val response = parseRawResponse(phpBridge.handleLaravelRequestBytes(phpRequest))

        val phpTime = System.currentTimeMillis() - phpStart
        val parseStart = System.currentTimeMillis()

        val responseHeaders = response.headers
        val statusCode = response.status

        val parseTime = System.currentTimeMillis() - parseStart
        Log.d("PerfTiming", "⏱️ WEBCLIENT [$path] prep=${prepTime}ms php=${phpTime}ms parse=${parseTime}ms")

        // ✅ Handle Set-Cookie headers (jar mirror is gated; parseResponse
        // already stored them in LaravelCookieStore)
        responseHeaders.entries
            .filter { it.key.equals("Set-Cookie", ignoreCase = true) }
            .forEach { (_, value) ->
                Log.d(TAG, "🍪 Setting cookie from response: $value")
                com.nativephp.mobile.security.WebCookieMirror.set(value)
            }

        com.nativephp.mobile.security.WebCookieMirror.flush()

        // ✅ Handle redirects
        if (statusCode in 300..399) {
            val location = responseHeaders["Location"]
            if (!location.isNullOrEmpty()) {
                // An absolute Location carries its query too, and Laravel writes
                // absolute URLs by default. Taking the path alone drops every
                // parameter the redirect was meant to hand on.
                val redirectUrl = when {
                    location.startsWith("/") -> location
                    location.startsWith("http") -> {
                        val parsedUri = Uri.parse(location)
                        val path = parsedUri.encodedPath ?: "/"
                        val query = parsedUri.encodedQuery
                        if (!query.isNullOrEmpty()) "$path?$query" else path
                    }
                    else -> "/$location"
                }

                val redirectUri = Uri.parse("http://127.0.0.1$redirectUrl")

                val redirectRequest = object : WebResourceRequest {
                    override fun getUrl(): Uri = redirectUri
                    override fun isForMainFrame(): Boolean = request.isForMainFrame
                    override fun isRedirect(): Boolean = true
                    override fun hasGesture(): Boolean = false
                    override fun getMethod(): String = "GET"
                    override fun getRequestHeaders(): Map<String, String> = request.requestHeaders
                }

                val currentPath = request.url.path ?: "/"
                val targetPath = redirectUri.path ?: "/"

                Log.d(TAG, "🔄 Following redirect ${redirectCount + 1}/10 to $redirectUrl")
                return handlePHPRequest(redirectRequest, null as CapturedBody?, redirectCount + 1)
            }
        }

        // ✅ Normal response: the body bytes exactly as PHP wrote them, with
        // the mime type and charset PHP's content-type header gives.
        val (mime, charset) = mimeAndCharset(responseHeaders["Content-Type"], "text/html")
        return WebResourceResponse(
            mime,
            charset,
            statusCode,
            response.reason,
            response.headersForWebView(),
            response.bodyStream()
        )
    }

    /**
     * A raw HTTP response split at the first CRLF CRLF. [headers] looks names
     * up case-insensitively (PHP sends them lower-case); repeated Set-Cookie
     * values are joined with newlines. The body is never decoded.
     */
    class RawResponse(
        private val raw: ByteArray,
        val status: Int,
        val reason: String,
        val headers: Map<String, String>,
        private val bodyOffset: Int
    ) {
        val bodyLength: Int
            get() = raw.size - bodyOffset

        fun bodyStream(): java.io.InputStream = ByteArrayInputStream(raw, bodyOffset, bodyLength)

        fun bodyText(): String = String(raw, bodyOffset, bodyLength, Charsets.UTF_8)

        /**
         * The headers to hand WebResourceResponse. Content-Type is left out:
         * the WebView builds it from the mime type argument, and a second
         * copy here would reach the page as "application/json, application/json".
         */
        fun headersForWebView(): Map<String, String> =
            headers.filterKeys { !it.equals("Content-Type", ignoreCase = true) }
    }

    fun parseRawResponse(raw: ByteArray): RawResponse {
        val headers = java.util.TreeMap<String, String>(String.CASE_INSENSITIVE_ORDER)

        var split = -1
        var i = 0
        while (i + 3 < raw.size) {
            if (raw[i] == CR && raw[i + 1] == LF && raw[i + 2] == CR && raw[i + 3] == LF) {
                split = i
                break
            }
            i++
        }

        if (split < 0) {
            // PHPBridge already turns an incomplete response into a 500, so
            // this only happens for a caller that skipped it.
            Log.w(TAG, "⚠️ Could not split response into headers/body (${raw.size} bytes)")
            return RawResponse(raw, 500, "Internal Server Error", headers, 0)
        }

        val headerLines = String(raw, 0, split, Charsets.UTF_8).split("\r\n")
        var statusCode = 200
        var reason = "OK"

        val statusLine = headerLines.firstOrNull()
        if (statusLine != null && statusLine.startsWith("HTTP/")) {
            val statusParts = statusLine.split(" ", limit = 3)
            statusParts.getOrNull(1)?.toIntOrNull()?.let { statusCode = it }
            reason = statusParts.getOrNull(2)
                ?.filter { it.code in 0x20..0x7E }
                ?.trim()
                ?.takeIf { it.isNotEmpty() }
                ?: if (statusCode in 200..299) "OK" else "Error"
            Log.d(TAG, "📋 Parsed status code: $statusCode")
        }

        for (line in headerLines.drop(1)) {
            val colonIndex = line.indexOf(":")
            if (colonIndex > 0) {
                val key = line.substring(0, colonIndex).trim()
                val value = line.substring(colonIndex + 1).trim()
                if (key.equals("Set-Cookie", ignoreCase = true)) {
                    headers.merge(key, value) { old, new -> "$old\n$new" }
                } else {
                    headers[key] = value
                }
            }
        }

        headers["X-PHP-Timing"]?.let { timing ->
            Log.d("PerfTiming", "⏱️ PHP_TIMING $timing")
        }

        return RawResponse(raw, statusCode, reason, headers, split + 4)
    }

    /**
     * Mime type and charset from a Content-Type header value, for
     * WebResourceResponse. Text types without a charset get UTF-8, as before;
     * other types get none.
     */
    private fun mimeAndCharset(contentType: String?, fallbackMime: String): Pair<String, String?> {
        if (contentType.isNullOrBlank()) {
            return fallbackMime to (if (fallbackMime.startsWith("text/")) "UTF-8" else null)
        }
        val mime = contentType.substringBefore(';').trim().ifEmpty { fallbackMime }
        val charset = Regex("charset\\s*=\\s*\"?([^\";]+)", RegexOption.IGNORE_CASE)
            .find(contentType)?.groupValues?.get(1)?.trim()
        return mime to (charset ?: if (mime.startsWith("text/")) "UTF-8" else null)
    }

    /**
     * Text version kept for older callers. Same parse as [parseRawResponse],
     * so the body is no longer trimmed; it also stores Set-Cookie values as
     * before.
     */
    fun parseResponse(rawResponse: String): Triple<Map<String, String>, String, Int> {
        val parsed = parseRawResponse(rawResponse.toByteArray(Charsets.UTF_8))

        parsed.headers.entries
            .filter { it.key.equals("Set-Cookie", ignoreCase = true) }
            .flatMap { it.value.split("\n") }
            .forEach { cookie ->
                LaravelCookieStore.storeFromSetCookieHeader(cookie)
                com.nativephp.mobile.security.WebCookieMirror.set(cookie)
            }
        com.nativephp.mobile.security.WebCookieMirror.flush()

        return Triple(parsed.headers, parsed.bodyText(), parsed.status)
    }



    /**
     * Jump webview-forward: proxy a 127.0.0.1 request (page or asset) to the
     * remote Jump dev server over the LAN and wrap its response for the
     * WebView. Mirrors iOS `PHPSchemeHandler.forwardToRemote`, plus binary
     * bodies (bytes end-to-end, so images/fonts work).
     *
     * Runs on the WebView's intercept thread (network allowed). Remote
     * Set-Cookies are persisted through the same stores as the local path
     * (LaravelCookieStore + WebCookieMirror) so Livewire sessions/CSRF
     * survive across forwards. Redirects are followed manually (max 5) as
     * GETs because WebResourceResponse rejects 3xx status codes outright.
     */
    fun forwardToRemote(request: WebResourceRequest, postData: String?): WebResourceResponse =
        forwardToRemote(request, postData?.let { CapturedBody(it.toByteArray(Charsets.UTF_8)) })

    fun forwardToRemote(request: WebResourceRequest, captured: CapturedBody?): WebResourceResponse {
        val remoteHost = JumpWebViewSession.host
        val remotePort = JumpWebViewSession.port
        val path = request.url.encodedPath ?: "/"
        val query = request.url.encodedQuery
        var urlString = "http://$remoteHost:$remotePort$path" +
            if (query.isNullOrEmpty()) "" else "?$query"
        var method = request.method.uppercase()
        var body: CapturedBody? = captured

        try {
            var redirects = 0
            while (true) {
                val conn = java.net.URL(urlString).openConnection() as java.net.HttpURLConnection
                conn.requestMethod = method
                conn.connectTimeout = 10_000
                conn.readTimeout = 15_000
                // Manual redirect handling: HttpURLConnection won't reliably
                // convert POST→GET across a 302, and WebResourceResponse
                // rejects 3xx codes, so we must resolve them here either way.
                conn.instanceFollowRedirects = false
                for ((k, v) in request.requestHeaders) {
                    val lk = k.lowercase()
                    // Skip hop-by-hop headers the stack owns. Dropping
                    // accept-encoding makes the platform handle gzip
                    // transparently, so the body arrives decoded.
                    if (lk == "host" || lk == "content-length" || lk == "accept-encoding") continue
                    conn.setRequestProperty(k, v)
                }
                conn.setRequestProperty("Cookie", LaravelCookieStore.asCookieHeader())

                val sending = body
                if (method in listOf("POST", "PUT", "PATCH", "DELETE") && sending != null) {
                    // The captured bytes and the content type that describes
                    // them (a multipart boundary included), exactly as sent.
                    if (sending.contentType.isNotEmpty()) {
                        conn.setRequestProperty("Content-Type", sending.contentType)
                    }
                    conn.doOutput = true
                    conn.outputStream.use { it.write(sending.bytes) }
                }

                val status = conn.responseCode

                // Persist remote session cookies exactly like the local path.
                conn.headerFields.entries
                    .filter { it.key?.equals("Set-Cookie", ignoreCase = true) == true }
                    .flatMap { it.value }
                    .forEach { v ->
                        LaravelCookieStore.storeFromSetCookieHeader(v)
                        com.nativephp.mobile.security.WebCookieMirror.set(v)
                    }
                com.nativephp.mobile.security.WebCookieMirror.flush()

                if (status in 300..399 && redirects < 5) {
                    val location = conn.getHeaderField("Location") ?: break
                    urlString = when {
                        location.startsWith("http") -> {
                            // Rebind absolute redirects onto the dev server —
                            // the remote app believes it lives at 127.0.0.1.
                            val u = Uri.parse(location)
                            "http://$remoteHost:$remotePort${u.encodedPath ?: "/"}" +
                                if (u.encodedQuery.isNullOrEmpty()) "" else "?${u.encodedQuery}"
                        }
                        location.startsWith("/") -> "http://$remoteHost:$remotePort$location"
                        else -> "http://$remoteHost:$remotePort/$location"
                    }
                    method = "GET"
                    body = null
                    redirects++
                    conn.disconnect()
                    continue
                }

                var bytes = (if (status >= 400) conn.errorStream else conn.inputStream)
                    ?.use { it.readBytes() } ?: ByteArray(0)

                Log.d(TAG, "🛰️ [JUMP-FORWARD] $method $urlString → $status (${bytes.size} bytes)")

                val contentType = conn.contentType ?: guessMimeType(path)

                // Rewrite the dev server's origin to 127.0.0.1 in text bodies.
                // The served app bakes ABSOLUTE URLs into its HTML/JS/JSON
                // (Livewire's update endpoint, asset links, Boost's logger…).
                // The WebView's origin is 127.0.0.1, so those would be
                // cross-origin: XHR/fetch then sends a CORS preflight straight
                // over the LAN, the jump router answers without CORS headers,
                // and the browser blocks the real request — every wire:click
                // silently dies. Same-origin URLs flow through the
                // interception forward (with captured POST bodies) instead.
                val isText = contentType.contains("html", true) ||
                    contentType.contains("json", true) ||
                    contentType.contains("javascript", true) ||
                    contentType.contains("css", true)
                if (isText && bytes.isNotEmpty()) {
                    val rewritten = String(bytes, Charsets.UTF_8)
                        .replace("http://$remoteHost:$remotePort", "http://127.0.0.1")
                    bytes = rewritten.toByteArray(Charsets.UTF_8)
                }
                val mime = contentType.substringBefore(';').trim()
                val encoding = if (contentType.contains("charset=", ignoreCase = true)) {
                    contentType.substringAfter("charset=").trim()
                } else "utf-8"
                val responseHeaders = conn.headerFields.entries
                    .filter { (k, _) ->
                        k != null && k.lowercase() !in listOf(
                            "set-cookie", "transfer-encoding", "content-encoding", "content-length",
                        )
                    }
                    .associate { (k, v) -> k!! to v.joinToString(", ") }
                val reason = conn.responseMessage?.takeIf { it.isNotBlank() } ?: "OK"

                return WebResourceResponse(
                    mime, encoding, status, reason, responseHeaders, ByteArrayInputStream(bytes)
                )
            }
            return errorResponse(502, "Unresolvable redirect from Jump dev server")
        } catch (e: Exception) {
            Log.e(TAG, "❌ [JUMP-FORWARD] $urlString failed: ${e.message}")
            return errorResponse(502, "Jump dev server unreachable")
        }
    }

    private fun errorResponse(code: Int, message: String): WebResourceResponse {
        return WebResourceResponse(
            "text/html",
            "UTF-8",
            code,
            message,
            mapOf("Content-Type" to "text/html"),
            ByteArrayInputStream("<html><body><h1>$code - $message</h1></body></html>".toByteArray())
        )
    }

    private fun guessMimeType(fileName: String): String {
        return when(fileName.substringAfterLast('.').lowercase()) {
            "html", "htm" -> "text/html"
            "css" -> "text/css"
            "js" -> "application/javascript"
            "png" -> "image/png"
            "jpg", "jpeg" -> "image/jpeg"
            "gif" -> "image/gif"
            "webp" -> "image/webp"
            "heic" -> "image/heic"
            "heif" -> "image/heif"
            "svg" -> "image/svg+xml"
            "json" -> "application/json"
            "pdf" -> "application/pdf"
            "txt" -> "text/plain"
            "xml" -> "application/xml"
            "woff" -> "font/woff"
            "woff2" -> "font/woff2"
            "ttf" -> "font/ttf"
            "eot" -> "application/vnd.ms-fontobject"
            "otf" -> "font/otf"
            "ico" -> "image/x-icon"
            // Video — Chromium WebView refuses to play <video src> without an
            // explicit video/* Content-Type. Without these entries the asset
            // handler returned application/octet-stream and the player
            // stayed black on Android.
            "mp4" -> "video/mp4"
            "m4v" -> "video/x-m4v"
            "mov" -> "video/quicktime"
            "webm" -> "video/webm"
            "mkv" -> "video/x-matroska"
            "avi" -> "video/x-msvideo"
            "3gp" -> "video/3gpp"
            // HLS playlist + segments for locally served streams.
            "m3u8" -> "application/vnd.apple.mpegurl"
            "ts" -> "video/mp2t"
            // Audio
            "mp3" -> "audio/mpeg"
            "wav" -> "audio/wav"
            "m4a" -> "audio/mp4"
            "aac" -> "audio/aac"
            "ogg" -> "audio/ogg"
            else -> {
                Log.w(TAG, "⚠️ Unknown file extension for: $fileName. Defaulting to application/octet-stream")
                "application/octet-stream"
            }
        }
    }
}
