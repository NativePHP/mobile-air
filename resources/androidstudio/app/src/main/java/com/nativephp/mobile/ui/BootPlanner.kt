package com.nativephp.mobile.ui

import android.content.Context
import android.util.Log
import org.json.JSONObject
import java.io.File

/**
 * Decides how the first screen boots: direct JNI dispatch into the native
 * runloop (no WebView, no Chromium) or the legacy WebView path.
 *
 * The decision is data-driven from the native-route manifest:
 *  - Build time: the CLI bakes `entry_mode` and the `Route::native` URI
 *    patterns into `bundle_meta.json` (APK assets).
 *  - Runtime refresh: `NativeServiceProvider` re-dumps the patterns to
 *    `storage/framework/native_routes.json` on every boot (dev/hot-reload
 *    safety). The fresher source wins.
 *
 * Missing or unparseable manifest ⇒ WEB_LEGACY — always safe, byte-identical
 * to the pre-native-first behavior.
 */
object BootPlanner {
    private const val TAG = "BootPlanner"

    enum class Entry { NATIVE_DIRECT, WEB_LEGACY }

    fun plan(context: Context, startPath: String): Entry {
        val meta = readBundleMeta(context)

        // Explicit escape hatch: NATIVEPHP_BOOT_MODE=web at build time.
        if (meta?.optString("entry_mode") == "web") {
            Log.d(TAG, "entry_mode=web — forcing legacy WebView boot")
            return Entry.WEB_LEGACY
        }

        val patterns = freshestPatterns(context, meta)
        if (patterns == null) {
            Log.d(TAG, "No native-route manifest — legacy WebView boot")
            return Entry.WEB_LEGACY
        }

        val path = startPath.substringBefore('?').ifEmpty { "/" }
        val matched = patterns.any { matches(it, path) }
        Log.d(TAG, "Boot plan for $path: ${if (matched) "NATIVE_DIRECT" else "WEB_LEGACY"} (${patterns.size} native patterns)")
        return if (matched) Entry.NATIVE_DIRECT else Entry.WEB_LEGACY
    }

    private fun readBundleMeta(context: Context): JSONObject? = try {
        context.assets.open("bundle_meta.json").bufferedReader().use { JSONObject(it.readText()) }
    } catch (e: Exception) {
        null
    }

    /**
     * Prefer the runtime dump when its bundle version matches or beats the
     * baked manifest — it reflects the routes PHP actually registered last
     * boot (hot reload can add/remove Route::native calls between builds).
     */
    private fun freshestPatterns(context: Context, meta: JSONObject?): List<String>? {
        val baked = meta?.optJSONArray("native_routes")?.let { arr ->
            (0 until arr.length()).map { arr.getString(it) }
        }
        val bakedVersion = meta?.optString("version") ?: ""

        val runtimeFile = File(
            File(context.filesDir.parent, "app_storage"),
            "persisted_data/storage/framework/native_routes.json"
        )
        val runtime = try {
            if (runtimeFile.isFile) JSONObject(runtimeFile.readText()) else null
        } catch (e: Exception) {
            null
        }
        if (runtime != null && runtime.optString("version") == bakedVersion) {
            val arr = runtime.optJSONArray("routes")
            if (arr != null) {
                return (0 until arr.length()).map { arr.getString(it) }
            }
        }
        return baked
    }

    /**
     * Segment matcher for Laravel-style URI patterns: `/users/{id}` matches
     * `/users/42`; `{param?}` segments are optional trailing matches.
     */
    internal fun matches(pattern: String, path: String): Boolean {
        val p = pattern.trim('/').split('/').filter { it.isNotEmpty() }
        val s = path.trim('/').split('/').filter { it.isNotEmpty() }

        var i = 0
        while (i < p.size) {
            val seg = p[i]
            val isParam = seg.startsWith("{") && seg.endsWith("}")
            val isOptional = isParam && seg.endsWith("?}")
            when {
                i < s.size -> if (!isParam && seg != s[i]) return false
                else -> return isOptional || (i until p.size).all {
                    p[it].startsWith("{") && p[it].endsWith("?}")
                }
            }
            i++
        }
        return s.size == p.size
    }
}
