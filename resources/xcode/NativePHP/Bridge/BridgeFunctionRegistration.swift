import Foundation
import SwiftUI

/// Register all bridge functions with the registry
/// Call this once during app initialization
func registerBridgeFunctions() {
    let registry = BridgeFunctionRegistry.shared

    registry.register("Edge.Set", function: EdgeFunctions.Set())

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
