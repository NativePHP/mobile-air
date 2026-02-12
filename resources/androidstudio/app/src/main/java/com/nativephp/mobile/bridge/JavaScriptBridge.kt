package com.nativephp.mobile.bridge

import android.util.Log
import android.webkit.JavascriptInterface

/**
 * JavaScript bridge for Android
 */
class JavaScriptBridge {

    companion object {
        private const val TAG = "JavaScriptBridge"
    }

    @JavascriptInterface
    fun can(method: String): Int {
        Log.d(TAG, "🔍 JavaScript checking if function exists: $method")
        return nativePHPCan(method)
    }

    @JavascriptInterface
    fun call(method: String, parametersJSON: String?): String? {
        Log.d(TAG, "🚀 JavaScript calling native function: $method")

        val result = nativePHPCall(method, parametersJSON)

        if (result == null) {
            Log.e(TAG, "❌ Function '$method' not found in native bridge")
        } else {
            Log.d(TAG, "✅ Function '$method' executed successfully")
        }

        return result
    }
}
