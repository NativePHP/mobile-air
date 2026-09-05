package com.nativephp.mobile.bridge.functions

import android.app.Activity
import android.app.ActivityManager
import android.content.Context
import android.content.Intent
import android.content.res.Configuration
import android.net.Uri
import android.os.UserManager
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

    /**
     * Current system appearance (light / dark). Backs `System::appearance()` /
     * `isDark()` for the cold read before the first AppearanceChanged push.
     * Returns:
     *   - appearance: string - "light" or "dark"
     */
    class GetAppearance(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val night = (context.resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK) ==
                Configuration.UI_MODE_NIGHT_YES
            return mapOf("appearance" to if (night) "dark" else "light")
        }
    }

    /**
     * Where this process is running and why it started — backs
     * `Native\Mobile\Facades\ExecutionContext`. iOS twin:
     * `Bridge/Functions/SystemFunctions.swift` (GetExecutionContext).
     *
     * Android's runtime is started by MainActivity, so the launch is always
     * a foreground one and `headless` is always false — the shape iOS reports
     * for a BGTaskScheduler cold launch has no Android equivalent. What does
     * carry over is whether we are currently on screen (process importance)
     * and whether protected storage is readable, which on Android is the
     * direct-boot user-unlocked state.
     *
     * Returns the same keys as the iOS twin so PHP has one code path.
     */
    class GetExecutionContext(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val info = ActivityManager.RunningAppProcessInfo()
            ActivityManager.getMyMemoryState(info)

            // FOREGROUND = a visible activity. FOREGROUND_SERVICE and lower
            // importances mean the user is not looking at us.
            val active = info.importance <= ActivityManager.RunningAppProcessInfo.IMPORTANCE_FOREGROUND
            val foreground = info.importance <= ActivityManager.RunningAppProcessInfo.IMPORTANCE_VISIBLE

            val unlocked = context.getSystemService(Context.USER_SERVICE)
                ?.let { (it as UserManager).isUserUnlocked }
                ?: true

            return mapOf(
                "launch" to "foreground",
                "state" to when {
                    active -> "active"
                    foreground -> "inactive"
                    else -> "background"
                },
                "foreground" to foreground,
                "active" to active,
                "has_become_active" to true,
                "headless" to false,
                "protected_data_available" to unlocked,
                "interactive_boot_started" to true,
            )
        }
    }
}
