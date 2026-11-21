package com.nativephp.mobile.bridge.functions

import android.os.Handler
import android.os.Looper
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.ui.nativerender.ScreenReader

/**
 * Screen-reader requests from PHP (`Native\Mobile\Accessibility`).
 * Namespace: "Accessibility.*"
 *
 * iOS twin: Bridge/Functions/AccessibilityFunctions.swift. Both hand off
 * to [ScreenReader], which speaks through a live region and moves
 * accessibility focus without the deprecated `announceForAccessibility`.
 */
object AccessibilityFunctions {

    /**
     * Speak a message that belongs to no element on screen.
     * Parameters:
     *   - message: string - What to say
     *   - assertive: bool (optional) - Interrupt current speech (default: false)
     */
    class Announce : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val message = parameters["message"] as? String
                ?: throw BridgeError.InvalidParameters("message is required")
            val assertive = parameters["assertive"] as? Boolean ?: false

            Handler(Looper.getMainLooper()).post { ScreenReader.announce(message, assertive) }

            return mapOf("success" to true)
        }
    }

    /**
     * Move the screen reader to the element with this `ref`. Does nothing
     * while TalkBack is off.
     * Parameters:
     *   - ref: string - The element's `ref`
     */
    class Focus : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val ref = (parameters["ref"] as? String)?.takeIf { it.isNotEmpty() }
                ?: throw BridgeError.InvalidParameters("ref is required")

            Handler(Looper.getMainLooper()).post { ScreenReader.focus(ref) }

            return mapOf("success" to true)
        }
    }
}
