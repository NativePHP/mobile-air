package com.nativephp.mobile.bridge.functions

import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.ui.nativerender.NativeUIBridge

/**
 * Functions related to native UI control
 * Namespace: "UI.*"
 */
object UIFunctions {

    /**
     * Signal a pending navigation transition.
     * Called by PHP's NativeRouter before resetBuffers() so the Kotlin
     * renderer knows to animate the next screen swap.
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
}