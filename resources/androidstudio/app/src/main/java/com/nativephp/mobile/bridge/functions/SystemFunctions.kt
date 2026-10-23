package com.nativephp.mobile.bridge.functions

import android.app.Activity
import android.content.ActivityNotFoundException
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
     * Open a URL in the user's web browser, never in this app.
     *
     * PHP calls this to give a deep link back to the web when the app has no
     * route for it. A plain ACTION_VIEW can't do that here: for a link the app
     * holds a verified App Link for, Android resolves the intent straight back
     * to MainActivity, which hands the same link to PHP, which hands it off
     * again, forever.
     *
     * The selector is the same request for a URL with no host. Android picks
     * the activity from the selector, and only a browser can handle a web URL
     * with no host, since an app's link filter always names one. So the user's
     * default browser opens the real URL (or, with no default set, the system
     * asks which browser), and this app is never a candidate.
     * Parameters:
     *   - url: string - The http(s) URL to open
     * Returns:
     *   - success: boolean - False when no browser is installed
     */
    class OpenInBrowser(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val url = parameters["url"] as? String
                ?: throw BridgeError.InvalidParameters("url is required")

            val uri = Uri.parse(url)
            val scheme = uri.scheme?.lowercase()
            if (scheme != "http" && scheme != "https") {
                throw BridgeError.InvalidParameters("url must be an http(s) URL")
            }

            val intent = Intent(Intent.ACTION_VIEW, uri).apply {
                addCategory(Intent.CATEGORY_BROWSABLE)
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                selector = Intent(Intent.ACTION_VIEW, Uri.parse("$scheme://"))
                    .addCategory(Intent.CATEGORY_BROWSABLE)
            }

            return try {
                context.startActivity(intent)
                Log.d("System.OpenInBrowser", "Opened $url in the browser")
                mapOf("success" to true)
            } catch (e: ActivityNotFoundException) {
                Log.w("System.OpenInBrowser", "No browser installed to open $url")
                mapOf("success" to false)
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
     * Current app-window orientation (portrait / landscape), which may differ
     * from the physical device orientation in multi-window mode. Backs
     * `System::orientation()` / `isLandscape()` for the cold read before the
     * first OrientationChanged push.
     * Returns:
     *   - orientation: string - "portrait" or "landscape"
     */
    class GetOrientation(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val landscape = context.resources.configuration.orientation ==
                Configuration.ORIENTATION_LANDSCAPE
            return mapOf("orientation" to if (landscape) "landscape" else "portrait")
        }
    }
}
