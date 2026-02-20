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
                    getParameters = emptyMap()
                )

                val response = phpBridge.handleLaravelRequest(phpRequest)
                val (responseHeaders, body, statusCode) = parseResponse(response)
                Log.d(TAG, "RESPONSE HEADERS: ${responseHeaders}")

                if (statusCode == 200) {
                    Log.d(TAG, "✅ Asset served via PHP: ${responseHeaders["Content-Type"]}")
                    WebResourceResponse(
                        responseHeaders["Content-Type"] ?: guessMimeType(cleanPath),
                        responseHeaders["Charset"] ?: "UTF-8",
                        statusCode,
                        "OK",
                        responseHeaders,
                        body.byteInputStream()
                    )
                } else {
                    Log.d(TAG, "❌ Asset not found via PHP: $path (Status: $statusCode)")
                    errorResponse(404, "Asset not found: $path")
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "⚠️ Error loading asset: $path", e)
            errorResponse(500, "Error loading asset: ${e.message}")
        }
    }

    fun handlePHPRequest(
        request: WebResourceRequest,
        postData: String?,
        redirectCount: Int = 0
    ): WebResourceResponse {
        val requestStart = System.currentTimeMillis()
        val path = request.url.encodedPath ?: "/"

        if (redirectCount > 10) {
            Log.e(TAG, "❌ Too many redirects")
            return errorResponse(500, "Too many redirects")
        }

        val headers = HashMap<String, String>(request.requestHeaders)

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

        val phpRequest = PHPRequest(
            url = normalizedPath,
            method = request.method,
            body = if (method in listOf("POST", "PUT", "PATCH")) postData ?: "" else "",
            headers = headers,
            getParameters = request.url.queryParameterNames?.associateWith {
                request.url.getQueryParameter(it) ?: ""
            } ?: emptyMap()
        )

        val prepTime = System.currentTimeMillis() - requestStart
        val phpStart = System.currentTimeMillis()

        val response = phpBridge.handleLaravelRequest(phpRequest)

        val phpTime = System.currentTimeMillis() - phpStart
        val parseStart = System.currentTimeMillis()

        val (responseHeaders, body, statusCode) = parseResponse(response)

        val parseTime = System.currentTimeMillis() - parseStart
        Log.d("PerfTiming", "⏱️ WEBCLIENT [$path] prep=${prepTime}ms php=${phpTime}ms parse=${parseTime}ms")

        // ✅ Handle Set-Cookie headers
        responseHeaders.entries
            .filter { it.key.equals("Set-Cookie", ignoreCase = true) }
            .forEach { (_, value) ->
                Log.d(TAG, "🍪 Setting cookie from response: $value")
                CookieManager.getInstance().setCookie("http://127.0.0.1", value)
            }

        CookieManager.getInstance().flush()

        // ✅ Handle redirects
        if (statusCode in 300..399) {
            val location = responseHeaders["Location"] ?: responseHeaders["location"]
            if (!location.isNullOrEmpty()) {
                val redirectUrl = when {
                    location.startsWith("/") -> location
                    location.startsWith("http") -> Uri.parse(location).encodedPath ?: "/"
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
                return handlePHPRequest(redirectRequest, null, redirectCount + 1)
            }
        }

        // ✅ Normal response
        return WebResourceResponse(
            responseHeaders["Content-Type"] ?: "text/html",
            responseHeaders["Charset"] ?: "UTF-8",
            statusCode,
            if (statusCode == 200) "OK" else "Error",
            responseHeaders,
            body.byteInputStream()
        )
    }

   fun parseResponse(rawResponse: String): Triple<Map<String, String>, String, Int> {
       val headers = mutableMapOf<String, String>()
       var statusCode = 200
       var body = ""

       val parts = rawResponse.split("\r\n\r\n", limit = 2)
       if (parts.size < 2) {
           Log.w(TAG, "⚠️ Could not split response into headers/body. Raw: ${rawResponse.take(200)}")
           return Triple(headers, rawResponse.trim(), statusCode)
       }

       val headerLines = parts[0].split("\r\n")
       body = parts[1]

       val statusLine = headerLines.firstOrNull()
       if (statusLine != null && statusLine.startsWith("HTTP/")) {
           val statusParts = statusLine.split(" ")
           if (statusParts.size >= 2) {
               try {
                   statusCode = statusParts[1].toInt()
                   Log.d(TAG, "📋 Parsed status code: $statusCode")
               } catch (e: Exception) {
                   Log.w(TAG, "⚠️ Failed to parse status code from: $statusLine")
               }
           }
       }

       for (i in 1 until headerLines.size) {
           val line = headerLines[i]
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

       // Log PHP timing header if present
       headers["X-PHP-Timing"]?.let { timing ->
           Log.d("PerfTiming", "⏱️ PHP_TIMING $timing")
       }

       // Set cookies
       headers.entries
           .filter { it.key.equals("Set-Cookie", ignoreCase = true) }
           .flatMap { it.value.split("\n") }
           .forEach { cookie ->
               LaravelCookieStore.storeFromSetCookieHeader(cookie)
               CookieManager.getInstance().setCookie("http://127.0.0.1", cookie)
               Log.d(TAG, "🍪 Stored cookie from Set-Cookie header: $cookie")
           }

       CookieManager.getInstance().flush()
       LaravelCookieStore.logAll()

       return Triple(headers, body.trim(), statusCode)
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
            else -> {
                Log.w(TAG, "⚠️ Unknown file extension for: $fileName. Defaulting to application/octet-stream")
                "application/octet-stream"
            }
        }
    }
}
