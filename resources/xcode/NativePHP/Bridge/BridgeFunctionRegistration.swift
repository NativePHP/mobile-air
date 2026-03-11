import Foundation

/// Register all bridge functions with the registry
/// Call this once during app initialization
func registerBridgeFunctions() {
    let registry = BridgeFunctionRegistry.shared

    registry.register("Edge.Set", function: EdgeFunctions.Set())

    // Register built-in native element renderers
    NativeRendererRegistry.shared.registerBuiltins()

    // Register plugin renderers (overrides built-in with more featured versions)
    registerPluginRenderers()

    // Register plugin bridge functions
    registerPluginBridgeFunctions()
}
