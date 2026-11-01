package com.nativephp.mobile.security

import android.util.Log
import org.json.JSONObject

object LaravelSecurity {
    private const val TAG = "LaravelSecurity"
    private var csrfToken: String? = null

    fun extractFromPostBody(body: String?) {
        if (body.isNullOrEmpty()) return

        Log.d(TAG, "🔎 Extracting CSRF token from body")

        try {
            if (body.startsWith("{")) {
                val json = JSONObject(body)
                if (json.has("_token")) {
                    csrfToken = json.getString("_token")
                    Log.d(TAG, "🔑 Extracted CSRF token from JSON")
                }
            } else if (body.contains("_token=")) {
                val token = body.split("&")
                    .find { it.startsWith("_token=") }
                    ?.substringAfter("=")
                if (!token.isNullOrEmpty()) {
                    csrfToken = token
                    Log.d(TAG, "🔑 Extracted CSRF token from form")
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "❌ Error parsing CSRF token: ${e.message}")
        }
    }

    fun applyToHeaders(headers: MutableMap<String, String>) {
        csrfToken?.let {
            headers["X-CSRF-TOKEN"] = it
            headers["X-XSRF-TOKEN"] = it
            Log.d(TAG, "🛡️ Applied CSRF token to headers")
        }
    }

    fun get(): String? = csrfToken

    fun set(token: String) {
        csrfToken = token
        Log.d(TAG, "📥 Stored CSRF token manually")
    }

    fun clear() {
        csrfToken = null
        Log.d(TAG, "🧹 Cleared CSRF token")
    }
}
