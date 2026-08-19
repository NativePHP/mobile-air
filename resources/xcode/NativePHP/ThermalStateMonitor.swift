import Foundation
import UIKit

/// App-wide thermal-state watcher.
///
/// Observes `ProcessInfo.thermalStateDidChangeNotification` and forwards
/// real changes to PHP as `Native\Mobile\Events\Device\ThermalStateChanged`.
/// The query side (`Device.GetThermalState` / `Device::thermalState()`) is
/// the cold read; this is the push path, matching AppearanceChanged.
///
/// `UIApplication.didBecomeActiveNotification` is the appearance-style
/// resume nudge: re-read when the app comes back and emit only if the
/// value drifted while we were suspended. First launch is a no-op emit
/// (`lastState` was just seeded to the current value).
///
/// Start once at app init. The simulator stays `.nominal` (`normal`) — live
/// events need a physical device.
enum ThermalStateMonitor {
    private static var observer: NSObjectProtocol?
    private static var foregroundObserver: NSObjectProtocol?
    private static var lastState: String?

    static func start() {
        guard observer == nil else { return }

        lastState = DeviceFunctions.normalizedThermalState()

        observer = NotificationCenter.default.addObserver(
            forName: ProcessInfo.thermalStateDidChangeNotification,
            object: nil,
            queue: .main
        ) { _ in
            emitIfChanged(DeviceFunctions.normalizedThermalState())
        }

        foregroundObserver = NotificationCenter.default.addObserver(
            forName: UIApplication.didBecomeActiveNotification,
            object: nil,
            queue: .main
        ) { _ in
            emitIfChanged(DeviceFunctions.normalizedThermalState())
        }
    }

    private static func emitIfChanged(_ state: String) {
        guard let previous = lastState else {
            lastState = state
            return
        }
        guard state != previous else { return }
        lastState = state

        LaravelBridge.shared.send?(
            "Native\\Mobile\\Events\\Device\\ThermalStateChanged",
            ["state": state, "previous": previous]
        )
    }
}
