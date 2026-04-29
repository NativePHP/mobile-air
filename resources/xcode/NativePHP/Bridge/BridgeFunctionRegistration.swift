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

    // Register plugin renderers
    registerPluginRenderers()

    // Register plugin bridge functions
    registerPluginBridgeFunctions()
}
