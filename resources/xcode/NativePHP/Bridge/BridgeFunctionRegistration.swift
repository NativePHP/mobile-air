import Foundation

/// Register all bridge functions with the registry
/// Call this once during app initialization
func registerBridgeFunctions() {
    let registry = BridgeFunctionRegistry.shared

    // Edge UI functions
    registry.register("Edge.Set", function: EdgeFunctions.Set())
    
    // Queue functions
    registry.register("Queue.JobsAvailable", function: QueueFunctions.JobsAvailable())

    // Register plugin bridge functions
    registerPluginBridgeFunctions()
    
    print("✅ Registered \(registry.getAllFunctionNames().count) bridge functions")
    
    // NOTE: Queue coordinator is started later, after PHP is initialized
    // See NativePHPApp.performDeferredInitialization()
}
