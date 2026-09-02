package com.nativephp.mobile.bridge.functions

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.content.res.Configuration
import android.net.Uri
import android.provider.Settings
import android.util.Log
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction

/**
 * Functions related to system-level operations
 * Namespace: "System.*"
 *
 * Core built-in (migrated from the `nativephp/mobile-system` plugin). Registered
 * directly in `BridgeFunctionRegistration.kt` alongside Edge/Perf/UI/Device — no
 * plugin install required. iOS twin: `Bridge/Functions/SystemFunctions.swift`.
 */
object SystemFunctions {

    /**
     * Open the app's settings screen in the device settings
     * This allows users to manage permissions they've granted or denied
     * Returns:
     *   - success: boolean - True if successfully opened
     */
    class OpenAppSettings(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            Log.d("System.OpenAppSettings", "Opening app settings")

            return try {
                val intent = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                    data = Uri.fromParts("package", context.packageName, null)
                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                }
                context.startActivity(intent)

                Log.d("System.OpenAppSettings", "Successfully opened app settings")
                mapOf("success" to true)
            } catch (e: Exception) {
                Log.e("System.OpenAppSettings", "Error opening app settings: ${e.message}", e)
                throw BridgeError.ExecutionFailed("Failed to open app settings: ${e.message}")
            }
        }
    }

    /**
     * Send the app to the background — the expected response to the system
     * back button on the navigation-stack root. Called by PHP's
     * `NativeComponent::back()` when the native stack has nothing left to
     * pop; without it the runloop would exit and reveal the blank WebView
     * underneath. Android-only (iOS apps cannot background themselves).
     * Returns:
     *   - success: boolean - True if the task was moved to the back
     */
    class MinimizeApp(private val activity: Activity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            Log.d("System.MinimizeApp", "Moving task to back")
            activity.runOnUiThread {
                activity.moveTaskToBack(true)
            }
            return mapOf("success" to true)
        }
    }

    /** Set an app-local light or dark appearance at runtime. */
    class SetAppearance(private val activity: Activity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val appearance = parameters["appearance"] as? String
            when (appearance) {
                "light", "dark", "system" -> Unit
                else -> throw BridgeError.InvalidParameters("appearance must be light, dark, or system")
            }

            val mainActivity = activity as? com.nativephp.mobile.ui.MainActivity
                ?: throw BridgeError.ExecutionFailed("System.SetAppearance requires MainActivity")
            mainActivity.setAppearance(appearance)

            return mapOf("success" to true, "appearance" to appearance)
        }
    }

    /**
     * Current system appearance (light / dark). Backs `System::appearance()` /
     * `isDark()` for the cold read before the first AppearanceChanged push.
     * Returns:
     *   - appearance: string - "light" or "dark"
     */
    class GetAppearance(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val systemNight = (context.resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK) ==
                Configuration.UI_MODE_NIGHT_YES
            val night = com.nativephp.mobile.ui.NativeAppearanceState.resolve(systemNight)
            return mapOf("appearance" to if (night) "dark" else "light")
        }
    }
}
