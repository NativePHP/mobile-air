import Foundation

// MARK: - Edge Function Namespace

/// Functions related to Edge UI management
/// Namespace: "Edge.*"
enum EdgeFunctions {

    // MARK: - Edge.Set

    /// Update the native UI state with Edge components
    /// Parameters:
    ///   - components: array - Array of Edge components
    ///
    /// Usage Example:
    ///   nativephp_call('Edge.Set', json_encode([
    ///     'components' => [
    ///       ['type' => 'bottom_nav', 'data' => [...]]
    ///     ]
    ///   ]));
    class Set: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            // Extract components array from parameters
            guard let components = parameters["components"] as? [[String: Any]] else {
                print("❌ Edge.Set: No components array provided")
                return ["error": "No components array provided"]
            }

            print("🎨 Edge.Set called with \(components.count) component(s)")

            // Convert components back to JSON string for NativeUIState
            do {
                let jsonData = try JSONSerialization.data(withJSONObject: components, options: [])
                guard let jsonString = String(data: jsonData, encoding: .utf8) else {
                    print("❌ Edge.Set: Failed to convert components to JSON string")
                    return ["error": "Failed to convert components to JSON string"]
                }

                // Update NativeUIState on main thread synchronously
                // Use sync to ensure the UI state is updated before PHP response completes
                // This prevents a race condition where the WebView receives the response
                // before the EDGE components are registered in NativeUIState
                if Thread.isMainThread {
                    NativeUIState.shared.updateFromJson(jsonString)
                } else {
                    DispatchQueue.main.sync {
                        NativeUIState.shared.updateFromJson(jsonString)
                    }
                }

                return ["success": true]
            } catch {
                print("❌ Edge.Set: JSON serialization error: \(error)")
                return ["error": "JSON serialization failed: \(error.localizedDescription)"]
            }
        }
    }
}