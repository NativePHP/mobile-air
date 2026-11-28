package com.nativephp.mobile.bridge

import android.content.Context
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.functions.AsyncFunctions
import com.nativephp.mobile.bridge.functions.DeviceFunctions
import com.nativephp.mobile.bridge.functions.DialogFunctions
import com.nativephp.mobile.bridge.functions.EventFunctions
import com.nativephp.mobile.bridge.functions.FileFunctions
import com.nativephp.mobile.bridge.functions.NotificationFunctions
import com.nativephp.mobile.bridge.functions.PerfFunctions
import com.nativephp.mobile.bridge.functions.QueueWorkerFunctions
import com.nativephp.mobile.bridge.functions.SystemFunctions
import com.nativephp.mobile.bridge.functions.UIFunctions
import com.nativephp.mobile.bridge.plugins.registerContextOnlyBridgeFunctions
import com.nativephp.mobile.bridge.plugins.registerPluginBridgeFunctions

/**
 * Register all bridge functions with the registry
 * Call this once during app initialization
 */
fun registerBridgeFunctions(activity: FragmentActivity, context: Context) {
    val registry = BridgeFunctionRegistry.shared

    registerCoreContextBridgeFunctions(registry, context)

    // The core functions below need the Activity.
    registry.register("System.MinimizeApp", SystemFunctions.MinimizeApp(activity))

    // Dialog.* — core built-in (migrated from the nativephp/mobile-dialog
    // plugin). iOS twin: Bridge/Functions/DialogFunctions.swift.
    registry.register("Dialog.Alert", DialogFunctions.Alert(activity))
    registry.register("Dialog.Toast", DialogFunctions.Toast(activity))

    // File.* — core built-in (migrated from the nativephp/mobile-file plugin).
    // iOS twin: Bridge/Functions/FileFunctions.swift.
    registry.register("File.Move", FileFunctions.Move(activity))
    registry.register("File.Copy", FileFunctions.Copy(activity))

    registry.register("UI.SetBackground", UIFunctions.SetBackground(activity))

    // Register plugin bridge functions
    registerPluginBridgeFunctions(activity, context)

    bridgeFunctionsRegistered = true
}

@Volatile
private var bridgeFunctionsRegistered = false

/**
 * Register the bridge functions that work without an Activity: core ones and
 * plugins' Context-only ones. For PHP booted with no screen, such as from
 * PHPBridge.runArtisan(). Does nothing once registerBridgeFunctions() has
 * run, and that still registers everything when the app opens later.
 */
@Synchronized
fun registerContextBridgeFunctions(context: Context) {
    if (bridgeFunctionsRegistered) return

    registerCoreContextBridgeFunctions(BridgeFunctionRegistry.shared, context)
    registerContextOnlyBridgeFunctions(context)

    bridgeFunctionsRegistered = true
}

private fun registerCoreContextBridgeFunctions(registry: BridgeFunctionRegistry, context: Context) {
    // AsyncTask.* — background PHP work with UI completion callbacks
    // (AsyncTask::dispatch()). iOS twin: Bridge/Functions/AsyncFunctions.swift.
    registry.register("AsyncTask.Dispatch", AsyncFunctions.Dispatch())
    registry.register("AsyncTask.Complete", AsyncFunctions.Complete())

    // QueueWorker.Wake: a job was pushed, so boot the queue worker (or have it
    // poll now). iOS twin: Bridge/PHPQueueWorker.swift (QueueWorkerFunctions).
    registry.register("QueueWorker.Wake", QueueWorkerFunctions.Wake())

    // Event.*: carries a BroadcastsGlobally event fired on another PHP thread
    // to the native screens' runloop. Queue only, never the web sink.
    // iOS twin: Bridge/Functions/EventFunctions.swift.
    registry.register("Event.Broadcast", EventFunctions.Broadcast())

    // Device.* — core built-in (migrated from the nativephp/mobile-device
    // plugin). iOS twin: Bridge/Functions/DeviceFunctions.swift.
    registry.register("Device.Vibrate", DeviceFunctions.Vibrate(context))
    registry.register("Device.ToggleFlashlight", DeviceFunctions.ToggleFlashlight(context))
    registry.register("Device.GetId", DeviceFunctions.GetId(context))
    registry.register("Device.GetInfo", DeviceFunctions.GetInfo(context))
    registry.register("Device.GetBatteryInfo", DeviceFunctions.GetBatteryInfo(context))
    registry.register("Device.GetThermalState", DeviceFunctions.GetThermalState(context))

    // System.* — core built-in (migrated from the nativephp/mobile-system
    // plugin). iOS twin: Bridge/Functions/SystemFunctions.swift.
    // Notification.* — badge and delivered notifications, without a push plugin.
    registry.register("Notification.SetBadge", NotificationFunctions.SetBadge())
    registry.register("Notification.RemoveDelivered", NotificationFunctions.RemoveDelivered(context))

    registry.register("System.OpenAppSettings", SystemFunctions.OpenAppSettings(context))
    registry.register("System.GetAppearance", SystemFunctions.GetAppearance(context))
    registry.register("System.SetAppearance", SystemFunctions.SetAppearance(context))
    registry.register("System.GetOrientation", SystemFunctions.GetOrientation(context))
    registry.register("System.OpenInBrowser", SystemFunctions.OpenInBrowser(context))

    registry.register("UI.SetTransition", UIFunctions.SetTransition())

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
}