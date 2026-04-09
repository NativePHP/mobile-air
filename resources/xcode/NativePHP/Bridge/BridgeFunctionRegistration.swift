import Foundation

/// Register all bridge functions with the registry
/// Call this once during app initialization
func registerBridgeFunctions() {
    let registry = BridgeFunctionRegistry.shared

    registry.register("Edge.Set", function: EdgeFunctions.Set())

    // Register plugin renderers
    registerPluginRenderers()

    // Register plugin bridge functions
    registerPluginBridgeFunctions()
}
