import Foundation
import UIKit

/// Tells PHP when the app goes to the background and when it comes back, as
/// `Native\Mobile\Events\App\AppBackgrounded` and `AppForegrounded`.
///
/// `willEnterForegroundNotification` fires only after the app was in the
/// background, unlike `didBecomeActiveNotification`, which also fires when
/// Control Center or a system alert is dismissed. A cold launch fires neither
/// event.
///
/// Time away is measured on `CLOCK_MONOTONIC`, which keeps counting while
/// the device sleeps, so a timeout still holds after the phone was locked.
///
/// Start once at app init.
enum AppLifecycleMonitor {
    private static var observers: [NSObjectProtocol] = []
    private static var backgroundedAt: UInt64?

    static func start() {
        guard observers.isEmpty else { return }

        let center = NotificationCenter.default

        observers.append(center.addObserver(
            forName: UIApplication.didEnterBackgroundNotification,
            object: nil,
            queue: .main
        ) { _ in
            didEnterBackground()
        })

        observers.append(center.addObserver(
            forName: UIApplication.willEnterForegroundNotification,
            object: nil,
            queue: .main
        ) { _ in
            willEnterForeground()
        })
    }

    private static func didEnterBackground() {
        if backgroundedAt == nil {
            backgroundedAt = clock_gettime_nsec_np(CLOCK_MONOTONIC)
        }

        LaravelBridge.shared.send?("Native\\Mobile\\Events\\App\\AppBackgrounded", [:])
    }

    private static func willEnterForeground() {
        guard let since = backgroundedAt else { return }
        backgroundedAt = nil

        let now = clock_gettime_nsec_np(CLOCK_MONOTONIC)
        let secondsAway = now > since ? Int((now - since) / 1_000_000_000) : 0

        LaravelBridge.shared.send?(
            "Native\\Mobile\\Events\\App\\AppForegrounded",
            ["secondsAway": secondsAway]
        )
    }
}
