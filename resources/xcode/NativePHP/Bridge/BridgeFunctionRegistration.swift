import Foundation
import SwiftUI

/// Register all bridge functions with the registry
/// Call this once during app initialization
func registerBridgeFunctions() {
    let registry = BridgeFunctionRegistry.shared

    registry.register("Edge.Set", function: EdgeFunctions.Set())

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
