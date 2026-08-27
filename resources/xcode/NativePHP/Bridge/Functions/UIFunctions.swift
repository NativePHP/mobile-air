import Foundation
import SwiftUI
import UIKit

// MARK: - UI Function Namespace
//
// Functions related to native UI control. Android twin:
// `bridge/functions/UIFunctions.kt`. (Android also registers
// `UI.SetTransition` there; iOS drives transitions through the tree
// publish path, so only `UI.SetBackground` is implemented here.)

/// App-wide window background override set from PHP via `UI.SetBackground`.
///
/// Three surfaces read it (falling back to `systemBackground` when unset):
///   1. `ContentView` — the base layer each native screen renders over,
///      visible during screen transitions and before first content.
///   2. `NativeRootStackRenderer` — NavigationStack hosts its screens on
///      its own container background (systemBackground, no SwiftUI
///      override hook), so each stack screen backgrounds itself with
///      this color instead.
///   3. The UIKit windows, so overscroll and inset regions match.
///
/// `color` is only mutated on the main queue (see `SetBackground`).
final class WindowBackgroundState: ObservableObject {
    static let shared = WindowBackgroundState()

    /// nil = no override → keep the platform default (systemBackground).
    @Published var color: Color?

    private init() {}
}

/// Functions related to native UI control
/// Namespace: "UI.*"
enum UIFunctions {

    // MARK: - UI.SetBackground

    /// Set the window background color — what shows behind screen content,
    /// during transitions, and in safe-area / overscroll regions.
    /// Controls what shows behind transparent system bars and safe area
    /// insets (same contract as the Android implementation).
    ///
    /// Parameters:
    ///   - color: string|null - hex color e.g. "#0F172A" (#RGB / #RRGGBB /
    ///     #AARRGGBB). null / missing / "" CLEARS the override, restoring
    ///     the platform default — the call is app-global sticky state, so
    ///     screens that set it should clear it in unmount().
    /// Returns:
    ///   - success: boolean
    class SetBackground: BridgeFunction {
        /// The scene's windows MINUS the overlays that are transparent by
        /// design: UIKit's keyboard helpers (UITextEffectsWindow /
        /// UIRemoteKeyboardWindow), which exist from the first time a
        /// keyboard is shown until the process dies, and the toast stack's
        /// own window, which lives for as long as a toast is on screen.
        /// Painting any of them makes the whole screen an opaque sheet of
        /// the background color while the app keeps rendering (and
        /// receiving taps) underneath.
        private func appWindows(in windowScene: UIWindowScene) -> [UIWindow] {
            windowScene.windows.filter { window in
                if window is ToastWindow {
                    return false
                }

                let className = String(describing: type(of: window))
                return !className.contains("TextEffects") && !className.contains("RemoteKeyboard")
            }
        }

        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let colorStr = parameters["color"] as? String ?? ""
            guard !colorStr.isEmpty else {
                DispatchQueue.main.async { [self] in
                    WindowBackgroundState.shared.color = nil
                    for scene in UIApplication.shared.connectedScenes {
                        guard let windowScene = scene as? UIWindowScene else { continue }
                        for window in appWindows(in: windowScene) {
                            window.backgroundColor = nil
                        }
                    }
                }

                return ["success": true]
            }

            guard let uiColor = UIColor(nativePhpHex: colorStr) else {
                return ["success": false, "error": "Invalid color"]
            }

            DispatchQueue.main.async { [self] in
                WindowBackgroundState.shared.color = Color(uiColor)
                // Also paint the UIKit windows so regions SwiftUI never
                // covers (keyboard underlap, rotation gaps) match — but
                // never the keyboard helper windows (see appWindows).
                for scene in UIApplication.shared.connectedScenes {
                    guard let windowScene = scene as? UIWindowScene else { continue }
                    for window in appWindows(in: windowScene) {
                        window.backgroundColor = uiColor
                    }
                }
            }

            return ["success": true]
        }
    }
}

extension UIColor {
    /// Parse "#RGB", "#RRGGBB" or "#AARRGGBB" (Android `Color.parseColor`
    /// compatible) into a UIColor. Returns nil for anything else.
    convenience init?(nativePhpHex: String) {
        var hex = nativePhpHex.trimmingCharacters(in: .whitespacesAndNewlines)
        guard hex.hasPrefix("#") else { return nil }
        hex.removeFirst()
        if hex.count == 3 {
            hex = hex.map { "\($0)\($0)" }.joined()
        }
        guard hex.count == 6 || hex.count == 8,
              let value = UInt64(hex, radix: 16) else { return nil }

        let a, r, g, b: UInt64
        if hex.count == 8 {
            (a, r, g, b) = (value >> 24 & 0xFF, value >> 16 & 0xFF, value >> 8 & 0xFF, value & 0xFF)
        } else {
            (a, r, g, b) = (0xFF, value >> 16 & 0xFF, value >> 8 & 0xFF, value & 0xFF)
        }

        self.init(
            red: CGFloat(r) / 255,
            green: CGFloat(g) / 255,
            blue: CGFloat(b) / 255,
            alpha: CGFloat(a) / 255
        )
    }
}
