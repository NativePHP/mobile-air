package com.nativephp.mobile.bridge.functions

import android.util.Log
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.ui.NativeUIState

/**
 * Functions related to Edge UI management
 * Namespace: "Edge.*"
 */
object EdgeFunctions {

    /**
     * Update the native UI state with Edge components
     * Parameters:
     *   - components: array - Array of Edge components
     *
     * Usage Example:
     *   nativephp_call('Edge.Set', json_encode([
     *     'components' => [
     *       ['type' => 'bottom_nav', 'data' => [...]]
     *     ]
     *   ]));
     */
    class Set : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            // Extract components from parameters
            val components = parameters["components"]

            if (components == null) {
                Log.e("EdgeFunctions.Set", "❌ No components provided in parameters")
                return mapOf("error" to "No components provided")
            }

            // Extract _meta.rtl_support flag
            @Suppress("UNCHECKED_CAST")
            val meta = parameters["_meta"] as? Map<String, Any>
            val rtlSupport = meta?.get("rtl_support") as? Boolean
            if (rtlSupport != null) {
                NativeUIState.setRtlSupport(rtlSupport)
            }

            Log.d("EdgeFunctions.Set", "🎨 Edge.Set called")

            try {
                // Pass the components object directly to NativeUIState
                // It will handle the type conversion internally
                NativeUIState.updateFromJson(components)

                return mapOf("success" to true)
            } catch (e: Exception) {
                Log.e("EdgeFunctions.Set", "❌ Error updating UI state: ${e.message}", e)
                return mapOf("error" to "Failed to update UI state: ${e.message}")
            }
        }
    }
}