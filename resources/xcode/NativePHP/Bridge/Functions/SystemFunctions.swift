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

    /// The current app window orientation. This intentionally describes the
    /// window, not the physical device: iPad multitasking can make a window
    /// landscape while the device itself is portrait (and vice versa).
    static func currentWindowOrientation() -> String {
        let size = UIApplication.shared.connectedScenes
            .compactMap { $0 as? UIWindowScene }
            .first?.windows.first?.bounds.size
            ?? UIScreen.main.bounds.size
        return size.width > size.height ? "landscape" : "portrait"
    }

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

    // MARK: - System.SetAppearance

    /// UserDefaults key holding the mode last passed to `System.SetAppearance`.
    static let appearanceDefaultsKey = "NativePHP.appearance"

    /// The window style for a `System::setAppearance()` mode, nil for an unknown
    /// one. `system` is `.unspecified`: windows follow the device again, or the
    /// build-time `UIUserInterfaceStyle` when `nativephp.appearance` pins one.
    static func interfaceStyle(for mode: String) -> UIUserInterfaceStyle? {
        switch mode {
        case "system": return .unspecified
        case "light": return .light
        case "dark": return .dark
        default: return nil
        }
    }

    private static var appearanceObserver: NSObjectProtocol?

    /// Re-apply the saved mode on every window as it becomes visible: the app
    /// window on launch, and the keyboard and alert windows later. Started from
    /// `NativePHPApp.init()`, before any window exists, so the first SwiftUI body
    /// (the splash included) already sees the saved color scheme and never flips.
    static func startAppearanceOverride() {
        guard appearanceObserver == nil else { return }

        appearanceObserver = NotificationCenter.default.addObserver(
            forName: UIWindow.didBecomeVisibleNotification,
            object: nil,
            queue: .main
        ) { notification in
            let mode = UserDefaults.standard.string(forKey: appearanceDefaultsKey) ?? "system"
            (notification.object as? UIWindow)?.overrideUserInterfaceStyle =
                interfaceStyle(for: mode) ?? .unspecified
        }
    }

    /// Pin the app to light or dark, or follow the device again. Sets every
    /// window's `overrideUserInterfaceStyle`, so UIKit and SwiftUI redraw in it
    /// (bars, sheets and keyboards included) and ContentView's colorScheme
    /// observer pushes AppearanceChanged as it does for a device switch. Saved
    /// in UserDefaults and re-applied at launch by `startAppearanceOverride()`.
    /// Parameters:
    ///   - mode: string - "system", "light" or "dark"
    /// Returns:
    ///   - success: boolean - True once the mode is saved
    class SetAppearance: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let mode = parameters["mode"] as? String,
                  let style = SystemFunctions.interfaceStyle(for: mode) else {
                throw BridgeError.invalidParameters("mode must be system, light or dark")
            }

            UserDefaults.standard.set(mode, forKey: SystemFunctions.appearanceDefaultsKey)

            DispatchQueue.main.async {
                UIApplication.shared.connectedScenes
                    .compactMap { $0 as? UIWindowScene }
                    .flatMap(\.windows)
                    .forEach { $0.overrideUserInterfaceStyle = style }
            }

            return ["success": true]
        }
    }

    // MARK: - System.GetOrientation

    /// Current app window orientation (portrait / landscape), derived from the
    /// window's aspect — the same signal the OrientationChanged push uses.
    /// Backs `System::orientation()` / `isLandscape()` for the cold read before
    /// the first OrientationChanged push.
    /// Returns:
    ///   - orientation: string - "portrait" or "landscape"
    class GetOrientation: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            func read() -> String {
                SystemFunctions.currentWindowOrientation()
            }
            // Bridge functions may run off the main thread; UIKit window reads
            // must be on main.
            let orientation = Thread.isMainThread ? read() : DispatchQueue.main.sync { read() }
            return ["orientation": orientation]
        }
    }
}
