package com.nativephp.mobile.security

import android.content.Context
import android.content.SharedPreferences
import android.util.Log

object LaravelCookieStore {
    private lateinit var prefs: SharedPreferences
    private val cookies = mutableMapOf<String, String>()
    private const val TAG = "LaravelCookies"
    private const val PREF_NAME = "laravel_cookies"

    fun init(context: Context) {
        prefs = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
        prefs.all.forEach { (key, value) ->
            if (value is String) {
                cookies[key] = value
            }
        }
        logAll()
    }

    fun storeFromSetCookieHeader(header: String) {
        val parts = header.split(";")[0].split("=")
        if (parts.size == 2) {
            val name = parts[0].trim()
            val value = parts[1].trim()
            cookies[name] = value
            prefs.edit().putString(name, value).apply()
            Log.d(TAG, "🍪 Stored cookie: $name=$value")
        }
    }

    fun asCookieHeader(): String {
        val cookieString = cookies.entries.joinToString("; ") { "${it.key}=${it.value}" }
        Log.d(TAG, "📤 Cookie header: $cookieString")
        return cookieString
    }

    fun has(name: String): Boolean = cookies.containsKey(name)

    fun get(name: String): String? = cookies[name]

    fun clear() {
        Log.d(TAG, "🧹 Clearing cookies")
        cookies.clear()
        prefs.edit().clear().apply()
    }

    fun logAll() {
        Log.d(TAG, "📦 Stored cookies:")
        cookies.forEach { (key, value) ->
            Log.d(TAG, "   → $key = $value")
        }
    }
}
