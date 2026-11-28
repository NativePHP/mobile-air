package com.nativephp.mobile.bridge

import android.content.ContentProvider
import android.content.ContentValues
import android.database.Cursor
import android.net.Uri
import com.nativephp.mobile.bridge.plugins.runPluginInitFunctions
import com.nativephp.mobile.ui.nativerender.registerNativeChromeRenderers

/**
 * Runs the plugins' init functions when the app's process starts.
 *
 * Android creates the content providers of a process before any of its
 * components, so this runs first however the process was started: by
 * MainActivity, or by a push, a broadcast receiver, a service or a WorkManager
 * worker with no activity at all. Before, init functions ran only from
 * MainActivity's boot, so a plugin's push service or receiver woke up in a
 * process where its plugin had never been set up.
 *
 * Core's chrome renderers are registered first, as MainActivity does, so they
 * keep their types before any plugin code runs. Both calls do nothing the
 * second time, so MainActivity's boot still calls them as before.
 *
 * This runs on the main thread, before the first frame, so init functions
 * should stay cheap and hand slow work to a background thread.
 *
 * It serves no data: every query and change returns nothing.
 */
class PluginInitProvider : ContentProvider() {
    override fun onCreate(): Boolean {
        val context = context?.applicationContext ?: return false
        registerNativeChromeRenderers()
        runPluginInitFunctions(context)
        return true
    }

    override fun query(
        uri: Uri,
        projection: Array<out String>?,
        selection: String?,
        selectionArgs: Array<out String>?,
        sortOrder: String?,
    ): Cursor? = null

    override fun getType(uri: Uri): String? = null

    override fun insert(uri: Uri, values: ContentValues?): Uri? = null

    override fun delete(uri: Uri, selection: String?, selectionArgs: Array<out String>?): Int = 0

    override fun update(
        uri: Uri,
        values: ContentValues?,
        selection: String?,
        selectionArgs: Array<out String>?,
    ): Int = 0
}
