import Foundation
import UIKit

// MARK: - Dialog Function Namespace
//
// Core built-in (migrated from the `nativephp/mobile-dialog` plugin). Registered
// directly in `BridgeFunctionRegistration.swift` alongside Edge/Perf/UI/Device/
// System. Depends on core's `ToastManager` (Components/Toast.swift) and
// `LaravelBridge` (Bridge/NativePHP.swift). Android twin:
// `bridge/functions/DialogFunctions.kt`.

/// Functions related to native alert dialogs
/// Namespace: "Dialog.*"
enum DialogFunctions {

    // MARK: - Dialog.Alert

    /// Show a native alert dialog with custom buttons
    /// Parameters:
    ///   - title: (optional) string - Alert title
    ///   - message: (optional) string - Alert message body
    ///   - buttons: (optional) array of strings - Button titles (defaults to ["OK"])
    ///   - id: (optional) string - Custom ID included in event payload
    ///   - eventClass: (optional) string - Custom event class name (defaults to "Native\Mobile\Events\Alert\ButtonPressed")
    /// Events:
    ///   - Fires the specified eventClass (or default) when a button is tapped
    class Alert: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let title = parameters["title"] as? String
            let message = parameters["message"] as? String
            let id = parameters["id"] as? String
            let event = parameters["event"] as? String ?? "Native\\Mobile\\Events\\Alert\\ButtonPressed"

            var buttons: [String] = []
            if let buttonsArray = parameters["buttons"] as? [String] {
                buttons = buttonsArray
            } else if let buttonsArray = parameters["buttons"] as? [Any] {
                buttons = buttonsArray.compactMap { $0 as? String }
            }

            if buttons.isEmpty {
                buttons = ["OK"]
            }

            DispatchQueue.main.async {
                guard let scene = UIApplication.shared.connectedScenes
                          .compactMap({ $0 as? UIWindowScene })
                          .first(where: { $0.activationState == .foregroundActive }),
                      let root = scene.windows.first(where: { $0.isKeyWindow })?.rootViewController else {
                    return
                }

                let alert = UIAlertController(title: title,
                                              message: message,
                                              preferredStyle: .alert)

                for (index, label) in buttons.enumerated() {
                    alert.addAction(UIAlertAction(title: label,
                                                  style: .default) { _ in
                        var payload: [String: Any] = ["index": index, "label": label]
                        if let id = id {
                            payload["id"] = id
                        }

                        LaravelBridge.shared.send?(event, payload)
                    })
                }

                root.present(alert, animated: true)
            }

            return [:]
        }
    }

    // MARK: - Dialog.Toast

    /// Show a toast notification
    /// Parameters:
    ///   - message: string - The message to display
    ///   - duration: string (optional) - "short" or "long" (default: "long")
    /// Returns:
    ///   - success: boolean - True if toast shown successfully
    class Toast: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let message = parameters["message"] as? String else {
                throw BridgeError.invalidParameters("message is required")
            }

            let durationParam = parameters["duration"] as? String ?? "long"
            let duration: TimeInterval = durationParam.lowercased() == "short" ? 2.0 : 4.0

            print("Dialog.Toast called with message: \(message)")

            // Show toast on main thread
            DispatchQueue.main.async {
                ToastManager.shared.show(message: message, duration: duration)
                print("Toast displayed")
            }

            return ["success": true]
        }
    }
}
