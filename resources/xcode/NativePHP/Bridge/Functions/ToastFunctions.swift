import Foundation

// MARK: - Toast Function Namespace

/// Functions related to toasts
/// Namespace: "Toast.*"
enum ToastFunctions {

    // MARK: - Toast.Show

    /// Show a toast, either plain text or a developer-supplied view
    /// Parameters:
    ///   - id: string - Identifier used to dismiss the toast later
    ///   - message: string|null - Plain text message
    ///   - html: string|null - Rendered view, shown instead of the message
    ///   - duration: number|null - Seconds on screen; 0 stays until dismissed,
    ///                             null works it out from the message
    ///   - position: string - 'top' or 'bottom' (a hint: joining an existing
    ///                        stack keeps the stack's position)
    ///   - dismissible: bool - Whether a tap or swipe dismisses the toast
    ///
    /// Usage Example:
    ///   nativephp_call('Toast.Show', json_encode([
    ///     'message' => 'Saved',
    ///     'duration' => 3,
    ///     'position' => 'top',
    ///   ]));
    class Show: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let configuration = ToastConfiguration(parameters: parameters) else {
                throw BridgeError.invalidParameters("A toast needs a message or html")
            }

            ToastManager.shared.show(configuration)

            return ["success": true, "id": configuration.id]
        }
    }

    // MARK: - Toast.Dismiss

    /// Dismiss a single toast by ID
    /// Parameters:
    ///   - id: string - The toast's identifier
    class Dismiss: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let id = parameters["id"] as? String, !id.isEmpty else {
                throw BridgeError.invalidParameters("A toast id is required")
            }

            ToastManager.shared.dismiss(id: id)

            return ["success": true]
        }
    }

    // MARK: - Toast.DismissAll

    /// Dismiss every toast currently in the stack
    class DismissAll: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            ToastManager.shared.dismissAll()

            return ["success": true]
        }
    }
}
