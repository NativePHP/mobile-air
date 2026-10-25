package com.nativephp.mobile.bridge.functions

import android.content.Context
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import java.io.File

/**
 * Functions related to file operations
 * Namespace: "File.*"
 *
 * Core built-in (migrated from the `nativephp/mobile-file` plugin). Registered
 * directly in `BridgeFunctionRegistration.kt` alongside Edge/Perf/UI/Device/
 * System/Dialog. Self-contained (java.io.File only). iOS twin:
 * `Bridge/Functions/FileFunctions.swift`.
 */
object FileFunctions {

    // Path confinement
    //
    // File.Move/File.Copy are exported in the JS bridge, so a web view page can
    // call them through `/_native/api/call` with `from`/`to` of its choosing.
    // Left open that is two primitives against the app sandbox: copy a secret
    // (`.env`, the SQLite database) into `public/` and read it back over the
    // app origin at `/_assets/…`, and truncate or clobber any file the app
    // relies on.
    //
    // So the paths are confined, not just the method gated: a source must live
    // in a scratch area (the app cache) or in the app's own storage, and a
    // destination must land inside the app's storage. Secrets like `.env` and
    // the database sit outside those roots, so they can be neither read out nor
    // overwritten. The legitimate use — moving a captured camera/gallery file
    // out of the cache into `storage/app` — stays inside the allowed roots.
    //
    // Every path is canonicalised first (File.canonicalFile resolves symlinks
    // and `..`) and only then prefix-checked, so neither `..` nor a symlink can
    // walk out of a root.

    /** A path with symlinks and `..`/`.` resolved; the literal path on failure. */
    private fun canonical(path: String): String =
        try { File(path).canonicalPath } catch (e: Exception) { File(path).absolutePath }

    /** True when [path] is [root] itself or sits beneath it; both canonical. */
    private fun isInside(path: String, root: String): Boolean =
        path == root || path.startsWith(root + File.separator)

    /**
     * The app's own storage area — where a dispatched file may legitimately be
     * written, and where `storage_path('app')` resolves.
     *
     * `storage_path()` is NOT under the Laravel app tree. `LaravelEnvironment`
     * points Laravel at `getDir("storage")/persisted_data/storage` via
     * `LARAVEL_STORAGE_PATH`, which is deliberately outside `…/laravel` so it
     * survives an OTA that swaps the `laravel` extract. This mirrors that exact
     * path, so a legitimate `storage_path('app')` destination is accepted and
     * stays on persistent storage.
     */
    private fun storageAppRoot(context: Context): String =
        canonical(File(context.getDir("storage", Context.MODE_PRIVATE), "persisted_data/storage/app").path)

    /** Scratch roots a source may come from, plus the storage root. */
    private fun sourceRoots(context: Context): List<String> {
        val roots = mutableListOf(canonical(context.cacheDir.path))
        context.externalCacheDir?.let { roots.add(canonical(it.path)) }
        roots.add(storageAppRoot(context))
        return roots
    }

    /**
     * Refuse a move/copy whose source or destination falls outside the allowed
     * roots. Returns an error payload to hand straight back, or null to proceed.
     */
    private fun confinementError(context: Context, from: String, to: String): Map<String, Any>? {
        val fromReal = canonical(from)
        val toReal = canonical(to)

        if (sourceRoots(context).none { isInside(fromReal, it) }) {
            Log.w("FileFunctions", "File op refused: source outside the allowed roots")
            return mapOf("success" to false, "error" to "Source path is not allowed")
        }

        if (!isInside(toReal, storageAppRoot(context))) {
            Log.w("FileFunctions", "File op refused: destination outside app storage")
            return mapOf("success" to false, "error" to "Destination path is not allowed")
        }

        return null
    }

    /**
     * Move a file from one location to another
     * Parameters:
     *   - from: string - Source file path
     *   - to: string - Destination file path
     * Returns:
     *   - success: boolean - Whether the operation succeeded
     *   - error: string (optional) - Error message if operation failed
     */
    class Move(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val from = parameters["from"] as? String
            val to = parameters["to"] as? String

            Log.d("FileFunctions.Move", "Move requested - from: $from, to: $to")

            if (from.isNullOrEmpty()) {
                return mapOf("success" to false, "error" to "'from' parameter is required")
            }

            if (to.isNullOrEmpty()) {
                return mapOf("success" to false, "error" to "'to' parameter is required")
            }

            confinementError(activity.applicationContext, from, to)?.let { return it }

            try {
                val sourceFile = File(from)
                val destFile = File(to)

                if (!sourceFile.exists()) {
                    return mapOf("success" to false, "error" to "Source file does not exist")
                }

                if (!sourceFile.isFile) {
                    return mapOf("success" to false, "error" to "Source is not a file")
                }

                destFile.parentFile?.let { parent ->
                    if (!parent.exists()) {
                        parent.mkdirs()
                    }
                }

                if (destFile.exists()) {
                    destFile.delete()
                }

                val success = sourceFile.renameTo(destFile)

                if (success) {
                    Log.d("FileFunctions.Move", "File moved successfully")
                    return mapOf("success" to true)
                } else {
                    sourceFile.inputStream().use { input ->
                        destFile.outputStream().use { output ->
                            input.copyTo(output)
                        }
                    }

                    if (destFile.exists() && destFile.length() == sourceFile.length()) {
                        sourceFile.delete()
                        Log.d("FileFunctions.Move", "File moved via copy + delete")
                        return mapOf("success" to true)
                    } else {
                        return mapOf("success" to false, "error" to "Failed to verify file copy")
                    }
                }
            } catch (e: Exception) {
                Log.e("FileFunctions.Move", "Error moving file: ${e.message}", e)
                return mapOf("success" to false, "error" to (e.message ?: "Unknown error"))
            }
        }
    }

    /**
     * Copy a file from one location to another
     * Parameters:
     *   - from: string - Source file path
     *   - to: string - Destination file path
     * Returns:
     *   - success: boolean - Whether the operation succeeded
     *   - error: string (optional) - Error message if operation failed
     */
    class Copy(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val from = parameters["from"] as? String
            val to = parameters["to"] as? String

            Log.d("FileFunctions.Copy", "Copy requested - from: $from, to: $to")

            if (from.isNullOrEmpty()) {
                return mapOf("success" to false, "error" to "'from' parameter is required")
            }

            if (to.isNullOrEmpty()) {
                return mapOf("success" to false, "error" to "'to' parameter is required")
            }

            confinementError(activity.applicationContext, from, to)?.let { return it }

            try {
                val sourceFile = File(from)
                val destFile = File(to)

                if (!sourceFile.exists()) {
                    return mapOf("success" to false, "error" to "Source file does not exist")
                }

                if (!sourceFile.isFile) {
                    return mapOf("success" to false, "error" to "Source is not a file")
                }

                destFile.parentFile?.let { parent ->
                    if (!parent.exists()) {
                        parent.mkdirs()
                    }
                }

                if (destFile.exists()) {
                    destFile.delete()
                }

                sourceFile.inputStream().use { input ->
                    destFile.outputStream().use { output ->
                        input.copyTo(output)
                    }
                }

                if (destFile.exists() && destFile.length() == sourceFile.length()) {
                    Log.d("FileFunctions.Copy", "File copied successfully")
                    return mapOf("success" to true)
                } else {
                    return mapOf("success" to false, "error" to "Failed to verify file copy")
                }
            } catch (e: Exception) {
                Log.e("FileFunctions.Copy", "Error copying file: ${e.message}", e)
                return mapOf("success" to false, "error" to (e.message ?: "Unknown error"))
            }
        }
    }
}
