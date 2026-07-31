package com.nativephp.mobile.network

data class PHPRequest(
    val url: String,
    val method: String = "GET",
    val body: String = "",
    val headers: Map<String, String> = emptyMap(),
    val getParameters: Map<String, String> = emptyMap(),
    val postParameters: Map<String, String> = emptyMap(),
    val cookies: Map<String, String> = emptyMap()
) {
    val uri: String
        get() {
            val queryString = if (getParameters.isNotEmpty()) {
                "?" + getParameters.entries.joinToString("&") { (key, value) ->
                    "$key=$value"
                }
            } else ""
            return url + queryString
        }
}
