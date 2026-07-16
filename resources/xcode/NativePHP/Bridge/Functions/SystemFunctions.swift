import Foundation
import UIKit

// MARK: - System Function Namespace
//
// Core built-in (migrated from the `nativephp/mobile-system` plugin). Registered
// directly in `BridgeFunctionRegistration.swift` alongside Edge/Perf/UI/Device —
// no plugin install required. Android twin: `bridge/functions/SystemFunctions.kt`.

/// Functions related to system-level operations
/// Namespace: "System.*"
enum SystemFunctions {

    // MARK: - System.OpenAppSettings

    /// Open the app's settings screen in the device settings
    /// This allows users to manage permissions they've granted or denied
    /// Returns:
    ///   - success: boolean - True if successfully opened
    class OpenAppSettings: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            DispatchQueue.main.async {
                if let url = URL(string: UIApplication.openSettingsURLString) {
                    UIApplication.shared.open(url)
                }
            }

            return ["success": true]
        }
    }

    // MARK: - System.GetAppearance

    /// Current system appearance (light / dark). Backs `System::appearance()` /
    /// `isDark()` for the cold read before the first AppearanceChanged push.
    /// Returns:
    ///   - appearance: string - "light" or "dark"
    class GetAppearance: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            func read() -> String {
                let style = UIApplication.shared.connectedScenes
                    .compactMap { $0 as? UIWindowScene }
                    .first?.windows.first?.traitCollection.userInterfaceStyle
                    ?? UITraitCollection.current.userInterfaceStyle
                return style == .dark ? "dark" : "light"
            }
            // Bridge functions may run off the main thread; UIKit trait reads
            // must be on main.
            let mode = Thread.isMainThread ? read() : DispatchQueue.main.sync { read() }
            return ["appearance": mode]
        }
    }
}
