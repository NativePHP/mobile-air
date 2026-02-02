package com.nativephp.mobile.bridge

import android.content.Context
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.functions.EdgeFunctions
import com.nativephp.mobile.bridge.functions.QueueFunctions
import com.nativephp.mobile.bridge.plugins.registerPluginBridgeFunctions
import com.nativephp.mobile.queue.NativeQueueCoordinator

private const val TAG = "BridgeFunctionRegistration"

/**
 * Register all bridge functions with the registry
 * Call this once during app initialization
 */
fun registerBridgeFunctions(activity: FragmentActivity, context: Context, phpBridge: PHPBridge? = null) {
    val registry = BridgeFunctionRegistry.shared

    // Edge UI functions
    registry.register("Edge.Set", EdgeFunctions.Set())
    
    // Queue functions
    registry.register("Queue.JobsAvailable", QueueFunctions.JobsAvailable())

    // Register plugin bridge functions
    registerPluginBridgeFunctions(activity, context)
    
    Log.d(TAG, "✅ Registered ${registry.getAllFunctionNames().size} bridge functions")
    
    // Initialize the queue coordinator (but don't start yet - wait for PHP to be ready)
    if (phpBridge != null) {
        NativeQueueCoordinator.getInstance().initialize(phpBridge) { activity }
    }
}

/**
 * Start the queue coordinator after PHP is initialized
 * Call this from MainActivity after the first page load
 */
fun startQueueCoordinator() {
    Log.d(TAG, "🚀 Starting queue coordinator (PHP is ready)")
    NativeQueueCoordinator.getInstance().start()
}