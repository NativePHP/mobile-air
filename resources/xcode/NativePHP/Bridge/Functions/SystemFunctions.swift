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

    // MARK: - System.GetExecutionContext

    /// Where this process is running and why it started — backs
    /// `Native\Mobile\Facades\ExecutionContext`.
    ///
    /// The signal that matters for scheduled work is `headless`: true when
    /// iOS cold-launched the app in the background (BGTaskScheduler, silent
    /// push, background fetch) and it has never been on screen. Code woken
    /// that way should do its job and return rather than touch the UI, and
    /// `protected_data_available` tells it whether the device is unlocked
    /// enough to read keychain items and protected files.
    ///
    /// Reads a lock-guarded mirror of the UIKit lifecycle state, so it is
    /// safe from the PHP worker thread — no hop to main, no deadlock when
    /// main is itself waiting on PHP.
    ///
    /// Returns:
    ///   - launch: string - "foreground" or "background"
    ///   - state: string - "active", "inactive" or "background"
    ///   - foreground: boolean - not backgrounded (active or inactive)
    ///   - active: boolean - frontmost and receiving events
    ///   - has_become_active: boolean - has been on screen at least once
    ///   - headless: boolean - background launch that never became active
    ///   - protected_data_available: boolean - Data Protection unlocked
    ///   - interactive_boot_started: boolean - start URL has been dispatched
    class GetExecutionContext: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            ExecutionContext.shared.snapshot()
        }
    }
}
