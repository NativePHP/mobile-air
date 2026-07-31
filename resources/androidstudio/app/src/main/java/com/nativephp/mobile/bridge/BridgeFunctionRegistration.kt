package com.nativephp.mobile.bridge

import android.content.Context
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.functions.EdgeFunctions
import com.nativephp.mobile.bridge.plugins.registerPluginBridgeFunctions

/**
 * Register all bridge functions with the registry
 * Call this once during app initialization
 */
fun registerBridgeFunctions(activity: FragmentActivity, context: Context) {
    val registry = BridgeFunctionRegistry.shared

    registry.register("Edge.Set", EdgeFunctions.Set())

    // Register plugin bridge functions
    registerPluginBridgeFunctions(activity, context)
}