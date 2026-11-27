import Foundation

// MARK: - Accessibility Function Namespace
//
// Screen-reader requests from PHP (`Native\Mobile\Accessibility`). Both hand
// off to `ScreenReader` (NativeRender/ScreenReader.swift). Android twin:
// `bridge/functions/AccessibilityFunctions.kt`.

/// Namespace: "Accessibility.*"
enum AccessibilityFunctions {

    // MARK: - Accessibility.Announce

    /// Speak a message that belongs to no element on screen.
    /// Parameters:
    ///   - message: string - What to say
    ///   - assertive: bool (optional) - Interrupt current speech (default: false)
    class Announce: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let message = parameters["message"] as? String else {
                throw BridgeError.invalidParameters("message is required")
            }
            let assertive = parameters["assertive"] as? Bool ?? false

            DispatchQueue.main.async {
                ScreenReader.announce(message, assertive: assertive)
            }

            return ["success": true]
        }
    }

    // MARK: - Accessibility.Focus

    /// Move the screen reader to the element with this `ref`. Does nothing
    /// while VoiceOver is off.
    /// Parameters:
    ///   - ref: string - The element's `ref`
    class Focus: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let ref = parameters["ref"] as? String, !ref.isEmpty else {
                throw BridgeError.invalidParameters("ref is required")
            }

            DispatchQueue.main.async {
                ScreenReader.focus(ref: ref)
            }

            return ["success": true]
        }
    }
}
