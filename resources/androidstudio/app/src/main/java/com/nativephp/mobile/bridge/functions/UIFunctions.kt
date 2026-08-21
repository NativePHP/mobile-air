package com.nativephp.mobile.bridge.functions

import android.graphics.Color
import android.graphics.drawable.Drawable
import android.os.Handler
import android.os.Looper
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.ui.nativerender.NativeUIBridge
import com.nativephp.mobile.ui.nativerender.NativeUIState

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
     *   - color: string|null - hex color e.g. "#0F172A". null / missing / ""
     *     CLEARS the override, restoring the theme's original window
     *     background — the call is app-global sticky state, so screens
     *     that set it should clear it in unmount(). (iOS twin:
     *     Bridge/Functions/UIFunctions.swift, same contract.)
     */
    class SetBackground(private val activity: FragmentActivity) : BridgeFunction {
        companion object {
            /** The decor background before the first override, for restore. */
            private var originalBackground: Drawable? = null
            private var hasOverride = false
        }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val colorStr = parameters["color"] as? String

            if (colorStr.isNullOrEmpty()) {
                Handler(Looper.getMainLooper()).post {
                    if (hasOverride) {
                        activity.window.decorView.background = originalBackground
                        originalBackground = null
                        hasOverride = false
                    }
                }
                return mapOf("success" to true)
            }

            return try {
                val color = Color.parseColor(colorStr)
                Handler(Looper.getMainLooper()).post {
                    if (!hasOverride) {
                        originalBackground = activity.window.decorView.background
                        hasOverride = true
                    }
                    activity.window.decorView.setBackgroundColor(color)
                }
                mapOf("success" to true)
            } catch (e: Exception) {
                mapOf("success" to false, "error" to (e.message ?: "Invalid color"))
            }
        }
    }

    /**
     * Receive the developer's `nativephp.rtl_support` flag from the Laravel
     * layer at boot. The payload carries framework metadata:
     *
     *     { "_meta": { "rtl_support": true } }
     *
     * `_meta` is NativePHP framework metadata (not application state). This
     * updates the shared [NativeUIState], which then recomputes the
     * effective layout direction from the device locale. iOS twin:
     * `Bridge/Functions/UIFunctions.swift`.
     */
    class SetRtlSupport : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val meta = parameters["_meta"] as? Map<*, *>
            val enabled = (meta?.get("rtl_support") as? Boolean)
                ?: (parameters["rtl_support"] as? Boolean)
                ?: false

            // Compute the effective direction deterministically for the
            // return value (before the async state update lands on main).
            val effective = enabled && NativeUIState.deviceLanguageIsRTL()

            Handler(Looper.getMainLooper()).post {
                NativeUIState.setRtlSupport(enabled)
            }

            return mapOf(
                "success" to true,
                "rtl_support" to enabled,
                "is_rtl" to effective,
            )
        }
    }
}