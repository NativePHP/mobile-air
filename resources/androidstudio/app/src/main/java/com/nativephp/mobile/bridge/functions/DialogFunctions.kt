package com.nativephp.mobile.bridge.functions

import android.os.Handler
import android.os.Looper
import android.util.Log
import android.view.View
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.utils.NativeActionCoordinator
import com.google.android.material.snackbar.Snackbar
import org.json.JSONArray
import org.json.JSONObject

/**
 * Functions related to native alert dialogs
 * Namespace: "Dialog.*"
 *
 * Core built-in (migrated from the `nativephp/mobile-dialog` plugin). Registered
 * directly in `BridgeFunctionRegistration.kt` alongside Edge/Perf/UI/Device/
 * System. Depends on core's `NativeActionCoordinator` + Material Snackbar. iOS
 * twin: `Bridge/Functions/DialogFunctions.swift`.
 */
object DialogFunctions {

    /**
     * Show a native alert dialog with custom buttons
     * Parameters:
     *   - title: (optional) string - Alert title
     *   - message: (optional) string - Alert message body
     *   - buttons: (optional) array - Button titles as strings, or objects
     *     {label, style} where style is "default", "cancel" or "destructive"
     *     (defaults to ["OK"])
     *   - id: (optional) string - Custom ID included in event payload
     *   - event: (optional) string - Custom event class name (defaults to "Native\Mobile\Events\Alert\ButtonPressed")
     * Events:
     *   - Fires the specified event (or default) when a button is tapped
     */
    class Alert(private val context: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val title = parameters["title"] as? String
            val message = parameters["message"] as? String
            val id = parameters["id"] as? String
            val event = parameters["event"] as? String ?: "Native\\Mobile\\Events\\Alert\\ButtonPressed"

            // Parse buttons array (strings or {label, style} objects)
            val labels = mutableListOf<String>()
            val styles = mutableListOf<String>()
            fun addButton(label: String?, style: String?) {
                if (label.isNullOrEmpty()) return
                labels.add(label)
                styles.add(style ?: "default")
            }
            when (val buttonsParam = parameters["buttons"]) {
                is JSONArray -> {
                    for (i in 0 until buttonsParam.length()) {
                        when (val entry = buttonsParam.opt(i)) {
                            is String -> addButton(entry, null)
                            is JSONObject -> addButton(entry.optString("label"), entry.optString("style", "default"))
                        }
                    }
                }
                is List<*> -> {
                    buttonsParam.forEach { entry ->
                        when (entry) {
                            is String -> addButton(entry, null)
                            is Map<*, *> -> addButton(entry["label"] as? String, entry["style"] as? String)
                        }
                    }
                }
                is Array<*> -> {
                    buttonsParam.forEach { entry ->
                        when (entry) {
                            is String -> addButton(entry, null)
                            is Map<*, *> -> addButton(entry["label"] as? String, entry["style"] as? String)
                        }
                    }
                }
            }

            if (labels.isEmpty()) {
                labels.add("OK")
                styles.add("default")
            }

            // Launch alert on UI thread
            Handler(Looper.getMainLooper()).post {
                try {
                    val coord = NativeActionCoordinator.install(context)
                    coord.launchAlert(
                        title ?: "",
                        message ?: "",
                        labels.toTypedArray(),
                        styles.toTypedArray(),
                        id,
                        event
                    )
                } catch (e: Exception) {
                    Log.e("DialogFunctions.Alert", "Error launching alert: ${e.message}", e)
                }
            }

            return emptyMap()
        }
    }

    /**
     * Show a toast notification (uses Snackbar on Android for better UX)
     * Parameters:
     *   - message: string - The message to display
     *   - duration: string (optional) - "short" or "long" (default: "long")
     * Returns:
     *   - success: boolean - True if toast shown successfully
     */
    class Toast(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val message = parameters["message"] as? String
                ?: throw BridgeError.InvalidParameters("message is required")

            val durationParam = parameters["duration"] as? String ?: "long"
            val duration = when (durationParam.lowercase()) {
                "short" -> Snackbar.LENGTH_SHORT
                else -> Snackbar.LENGTH_LONG
            }

            Log.d("Dialog.Toast", "Showing toast (Snackbar): $message")

            return try {
                // Show snackbar on main thread
                Handler(Looper.getMainLooper()).post {
                    // Find a view to anchor the Snackbar to
                    val rootView = activity.findViewById<View>(android.R.id.content)
                    if (rootView != null) {
                        val snackbar = Snackbar.make(rootView, message, duration)

                        // Get current IME (keyboard) insets to avoid overlap
                        val windowInsets = ViewCompat.getRootWindowInsets(rootView)
                        val imeInsets = windowInsets?.getInsets(WindowInsetsCompat.Type.ime())?.bottom ?: 0

                        // Standard Material Design bottom nav height is 56dp
                        val bottomNavHeight = (56 * activity.resources.displayMetrics.density).toInt()

                        // Use whichever is greater: bottom nav or keyboard
                        val bottomOffset = maxOf(bottomNavHeight, imeInsets)

                        val view = snackbar.view
                        val params = view.layoutParams as android.view.ViewGroup.MarginLayoutParams
                        params.setMargins(
                            params.leftMargin,
                            params.topMargin,
                            params.rightMargin,
                            params.bottomMargin + bottomOffset
                        )
                        view.layoutParams = params

                        snackbar.show()
                        Log.d("Dialog.Toast", "Snackbar displayed with bottom offset: ${bottomOffset}px")
                    } else {
                        Log.e("Dialog.Toast", "Could not find root view for Snackbar")
                    }
                }

                mapOf("success" to true)
            } catch (e: Exception) {
                Log.e("Dialog.Toast", "Error showing toast: ${e.message}", e)
                throw BridgeError.ExecutionFailed("Failed to show toast: ${e.message}")
            }
        }
    }
}
