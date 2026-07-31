package com.nativephp.mobile.bridge.functions

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
