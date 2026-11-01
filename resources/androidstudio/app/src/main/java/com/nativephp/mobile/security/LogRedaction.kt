package com.nativephp.mobile.security

/**
 * Keeps secrets out of logcat. Logcat is readable over adb, ends up in bug
 * reports and gets shipped off the device by crash and support tools, so
 * cookie values, CSRF tokens and auth headers must never be logged, in any
 * build. Log cookie names and redacted headers instead.
 */
object LogRedaction {
    private const val REDACTED = "[redacted]"

    private val sensitiveHeaders = setOf(
        "cookie",
        "set-cookie",
        "authorization",
        "proxy-authorization",
        "x-csrf-token",
        "x-xsrf-token",
    )

    /** The name of a cookie from a "name=value; attributes" string, without its value. */
    fun cookieName(cookie: String): String =
        cookie.substringBefore(";").substringBefore("=").trim()

    /** [value], or a placeholder when [name] is a header that carries a secret. */
    fun header(name: String, value: String): String =
        if (name.lowercase() in sensitiveHeaders) REDACTED else value

    /** [headers] with the values of cookie, CSRF and auth headers replaced. */
    fun headers(headers: Map<String, String>): Map<String, String> =
        headers.mapValues { (name, value) -> header(name, value) }
}
