package com.nativephp.mobile.bridge.functions

import android.graphics.Color
import android.os.Handler
import android.os.Looper
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.ui.nativerender.NativeUIBridge

/**
 * Functions related to native UI control
 * Namespace: "UI.*"
 */
object UIFunctions {

    /**
     * Signal a pending navigation transition.
     *
     * Parameters:
     *   - type: string - "slide_forward", "slide_back", or "crossfade"
     */
    class SetTransition : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val type = parameters["type"] as? String ?: "crossfade"
            NativeUIBridge.setNavigationPending(type)
            return mapOf("success" to true)
        }
    }

    /**
     * Set the Activity window background color.
     * Controls what shows behind transparent system bars and safe area insets.
     *
     * Parameters:
     *   - color: string - hex color e.g. "#0F172A"
     */
    class SetBackground(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val colorStr = parameters["color"] as? String ?: return mapOf("success" to false)
            return try {
                val color = Color.parseColor(colorStr)
                Handler(Looper.getMainLooper()).post {
                    activity.window.decorView.setBackgroundColor(color)
                }
                mapOf("success" to true)
            } catch (e: Exception) {
                mapOf("success" to false, "error" to (e.message ?: "Invalid color"))
            }
        }
    }
}