import Foundation
import SwiftUI

/// Register all bridge functions with the registry
/// Call this once during app initialization
func registerBridgeFunctions() {
    let registry = BridgeFunctionRegistry.shared

    // AsyncTask.* — background PHP work with UI completion callbacks
    // (AsyncTask::dispatch()). Android twin: bridge/functions/AsyncFunctions.kt.
    registry.register("AsyncTask.Dispatch", function: AsyncFunctions.Dispatch())
    registry.register("AsyncTask.Complete", function: AsyncFunctions.Complete())

    // Device.* — core built-in (migrated from the nativephp/mobile-device
    // plugin). Android twin: bridge/functions/DeviceFunctions.kt.
    registry.register("Device.Vibrate",         function: DeviceFunctions.Vibrate())
    registry.register("Device.ToggleFlashlight", function: DeviceFunctions.ToggleFlashlight())
    registry.register("Device.GetId",           function: DeviceFunctions.GetId())
    registry.register("Device.GetInfo",         function: DeviceFunctions.GetInfo())
    registry.register("Device.GetBatteryInfo",  function: DeviceFunctions.GetBatteryInfo())

    // System.* — core built-in (migrated from the nativephp/mobile-system
    // plugin). Android twin: bridge/functions/SystemFunctions.kt.
    registry.register("System.OpenAppSettings", function: SystemFunctions.OpenAppSettings())
    registry.register("System.GetAppearance", function: SystemFunctions.GetAppearance())

    // UI.* — core built-in. Android twin: bridge/functions/UIFunctions.kt
    // (which also registers UI.SetTransition; iOS transitions ride the
    // tree publish path, so only SetBackground is implemented here).
    registry.register("UI.SetBackground", function: UIFunctions.SetBackground())

    // Dialog.* — core built-in (migrated from the nativephp/mobile-dialog
    // plugin). Android twin: bridge/functions/DialogFunctions.kt.
    registry.register("Dialog.Alert", function: DialogFunctions.Alert())
    registry.register("Dialog.Toast", function: DialogFunctions.Toast())

    // File.* — core built-in (migrated from the nativephp/mobile-file plugin).
    // Android twin: bridge/functions/FileFunctions.kt.
    registry.register("File.Move", function: FileFunctions.Move())
    registry.register("File.Copy", function: FileFunctions.Copy())

    // Perf.* — mirror of Android's `bridge/functions/PerfFunctions.kt`.
    // Used by `BenchmarkComponent` to drive each scenario. The
    // `Perf.Export` JSON shape matches Android's so PHP analysis is
    // one code path.
    registry.register("Perf.Enable",             function: PerfFunctions.Enable())
    registry.register("Perf.Disable",            function: PerfFunctions.Disable())
    registry.register("Perf.Reset",              function: PerfFunctions.Reset())
    registry.register("Perf.Export",             function: PerfFunctions.Export())
    registry.register("Perf.Summary",            function: PerfFunctions.Summary())
    registry.register("Perf.StartCaptureWindow", function: PerfFunctions.StartCaptureWindow())
    registry.register("Perf.StopCaptureWindow",  function: PerfFunctions.StopCaptureWindow())
    registry.register("Perf.SimulatePress",      function: PerfFunctions.SimulatePress())
    registry.register("Perf.SimulateTextChange", function: PerfFunctions.SimulateTextChange())
    registry.register("Perf.SimulateToggle",     function: PerfFunctions.SimulateToggle())
    registry.register("Perf.SetDiffEnabled",     function: PerfFunctions.SetDiffEnabled())
    registry.register("Perf.SetFpsOverlayEnabled", function: PerfFunctions.SetFpsOverlayEnabled())
    registry.register("Perf.Memory",             function: PerfFunctions.Memory())

    // Built-in framework-level renderers for chrome sentinels emitted
    // by `wrapWithChrome` when a layout opts into native chrome via
    // `NativeLayout::usesNativeChrome() = true`. These aren't plugin
    // components — they ship with mobile-air.
    SwiftUIRendererRegistry.shared.register("native_root_stack") {
        AnyView(NativeRootStackRenderer(node: $0))
    }
    SwiftUIRendererRegistry.shared.register("native_root_tabs") {
        AnyView(NativeRootTabsRenderer(node: $0))
    }
    // `bottom_bar` is a marker element — its content is extracted by
    // the parent chrome renderer (NavigationStack / TabView) and pinned
    // via `.safeAreaInset(edge: .bottom)`. The marker itself renders
    // nothing if it ever falls through to the default container path.
    SwiftUIRendererRegistry.shared.register("bottom_bar") { _ in
        AnyView(EmptyView())
    }

    // Register plugin renderers
    registerPluginRenderers()

    // Register plugin bridge functions
    registerPluginBridgeFunctions()
}
