package com.nativephp.mobile.bridge

import android.content.Context
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.functions.DeviceFunctions
import com.nativephp.mobile.bridge.functions.DialogFunctions
import com.nativephp.mobile.bridge.functions.EdgeFunctions
import com.nativephp.mobile.bridge.functions.FileFunctions
import com.nativephp.mobile.bridge.functions.PerfFunctions
import com.nativephp.mobile.bridge.functions.SystemFunctions
import com.nativephp.mobile.bridge.functions.UIFunctions
import com.nativephp.mobile.bridge.plugins.registerPluginBridgeFunctions

/**
 * Register all bridge functions with the registry
 * Call this once during app initialization
 */
fun registerBridgeFunctions(activity: FragmentActivity, context: Context) {
    val registry = BridgeFunctionRegistry.shared

    registry.register("Edge.Set", EdgeFunctions.Set())

    // Device.* — core built-in (migrated from the nativephp/mobile-device
    // plugin). iOS twin: Bridge/Functions/DeviceFunctions.swift.
    registry.register("Device.Vibrate", DeviceFunctions.Vibrate(context))
    registry.register("Device.ToggleFlashlight", DeviceFunctions.ToggleFlashlight(context))
    registry.register("Device.GetId", DeviceFunctions.GetId(context))
    registry.register("Device.GetInfo", DeviceFunctions.GetInfo(context))
    registry.register("Device.GetBatteryInfo", DeviceFunctions.GetBatteryInfo(context))

    // System.* — core built-in (migrated from the nativephp/mobile-system
    // plugin). iOS twin: Bridge/Functions/SystemFunctions.swift.
    registry.register("System.OpenAppSettings", SystemFunctions.OpenAppSettings(context))
    registry.register("System.GetAppearance", SystemFunctions.GetAppearance(context))

    // Dialog.* — core built-in (migrated from the nativephp/mobile-dialog
    // plugin). iOS twin: Bridge/Functions/DialogFunctions.swift.
    registry.register("Dialog.Alert", DialogFunctions.Alert(activity))
    registry.register("Dialog.Toast", DialogFunctions.Toast(activity))

    // File.* — core built-in (migrated from the nativephp/mobile-file plugin).
    // iOS twin: Bridge/Functions/FileFunctions.swift.
    registry.register("File.Move", FileFunctions.Move(activity))
    registry.register("File.Copy", FileFunctions.Copy(activity))

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
    registry.register("Perf.SetFpsOverlayEnabled", PerfFunctions.SetFpsOverlayEnabled())
    registry.register("Perf.Memory", PerfFunctions.Memory())

    // Register plugin bridge functions
    registerPluginBridgeFunctions(activity, context)
}