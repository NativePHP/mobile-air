package com.nativephp.mobile.bridge

import android.content.Context
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.functions.EdgeFunctions
import com.nativephp.mobile.bridge.functions.PerfFunctions
import com.nativephp.mobile.bridge.functions.UIFunctions
import com.nativephp.mobile.bridge.plugins.registerPluginBridgeFunctions

/**
 * Register all bridge functions with the registry
 * Call this once during app initialization
 */
fun registerBridgeFunctions(activity: FragmentActivity, context: Context) {
    val registry = BridgeFunctionRegistry.shared

    registry.register("Edge.Set", EdgeFunctions.Set())
    registry.register("UI.SetTransition", UIFunctions.SetTransition())
    registry.register("UI.SetBackground", UIFunctions.SetBackground(activity))

    // Performance tracking
    registry.register("Perf.Enable", PerfFunctions.Enable())
    registry.register("Perf.Disable", PerfFunctions.Disable())
    registry.register("Perf.Reset", PerfFunctions.Reset())
    registry.register("Perf.Export", PerfFunctions.Export())
    registry.register("Perf.Summary", PerfFunctions.Summary())
    registry.register("Perf.StartCaptureWindow", PerfFunctions.StartCaptureWindow())
    registry.register("Perf.StopCaptureWindow", PerfFunctions.StopCaptureWindow())
    registry.register("Perf.SimulatePress", PerfFunctions.SimulatePress())
    registry.register("Perf.SimulateTextChange", PerfFunctions.SimulateTextChange())
    registry.register("Perf.SimulateToggle", PerfFunctions.SimulateToggle())
    registry.register("Perf.SetDiffEnabled", PerfFunctions.SetDiffEnabled())
    registry.register("Perf.Memory", PerfFunctions.Memory())

    // Register plugin bridge functions
    registerPluginBridgeFunctions(activity, context)
}