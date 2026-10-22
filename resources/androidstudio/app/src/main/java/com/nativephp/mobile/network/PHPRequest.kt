package com.nativephp.mobile.network

/**
 * A request body exactly as the page sent it: the bytes, and the
 * Content-Type the browser computed for them (a multipart boundary
 * included). Empty [contentType] means none was known.
 */
class CapturedBody(
    val bytes: ByteArray,
    val contentType: String = ""
) {
    val size: Int
        get() = bytes.size
}

data class PHPRequest(
    val url: String,
    val method: String = "GET",
    val body: String = "",
    val headers: Map<String, String> = emptyMap(),
    val getParameters: Map<String, String> = emptyMap(),
    val queryString: String = "",
    val postParameters: Map<String, String> = emptyMap(),
    val cookies: Map<String, String> = emptyMap(),
    // The exact body bytes. When set they win over [body], which is text only.
    val bodyBytes: ByteArray? = null,
    // The body's own Content-Type, when known.
    val bodyContentType: String? = null
) {
    val uri: String
        get() {
            return if (queryString.isNotBlank()) {
                "$url?$queryString"
            } else if (getParameters.isNotEmpty()) {
                "$url?" + getParameters.entries.joinToString("&") { (key, value) ->
                    "$key=$value"
                }
            } else {
                url
            }
        }

    /** The body as bytes: [bodyBytes] if set, else [body] as UTF-8. */
    fun bodyAsBytes(): ByteArray? = bodyBytes ?: body.takeIf { it.isNotEmpty() }?.toByteArray(Charsets.UTF_8)

    /**
     * The Content-Type that belongs to the body: the captured one, else the
     * request's Content-Type header, else "".
     */
    fun effectiveContentType(): String =
        bodyContentType?.takeIf { it.isNotEmpty() }
            ?: headers.entries.firstOrNull { it.key.equals("Content-Type", ignoreCase = true) }?.value
            ?: ""

    /**
     * Request headers as UTF-8 "Name: value" lines joined by CRLF, the block
     * BridgeDispatcher::handle() reads. Values holding CR or LF are left out.
     */
    fun headerBlock(): ByteArray =
        headers.entries
            .filter { (k, v) -> k.isNotBlank() && k.none { it == '\r' || it == '\n' || it == ':' } && v.none { it == '\r' || it == '\n' } }
            .joinToString("\r\n") { (k, v) -> "$k: $v" }
            .toByteArray(Charsets.UTF_8)
}
