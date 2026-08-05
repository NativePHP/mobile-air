import SwiftUI
import AVFoundation

// MARK: - App Lifecycle Notification Names
// Plugins can subscribe to these notifications to receive iOS lifecycle events

extension Notification.Name {
    /// Posted when app receives APNS device token
    /// userInfo: ["deviceToken": Data]
    static let didRegisterForRemoteNotifications = Notification.Name("NativePHP.didRegisterForRemoteNotifications")

    /// Posted when app fails to register for remote notifications
    /// userInfo: ["error": Error]
    static let didFailToRegisterForRemoteNotifications = Notification.Name("NativePHP.didFailToRegisterForRemoteNotifications")

    /// Posted when app receives a remote notification
    /// userInfo: ["payload": [AnyHashable: Any]]
    static let didReceiveRemoteNotification = Notification.Name("NativePHP.didReceiveRemoteNotification")

    /// Posted when app finishes launching
    /// userInfo: ["launchOptions": [UIApplication.LaunchOptionsKey: Any]?]
    static let didFinishLaunching = Notification.Name("NativePHP.didFinishLaunching")

    /// Posted when app becomes active
    static let didBecomeActive = Notification.Name("NativePHP.didBecomeActive")

    /// Posted when app enters background
    static let didEnterBackground = Notification.Name("NativePHP.didEnterBackground")
}

class AppDelegate: NSObject, UIApplicationDelegate {
    static let shared = AppDelegate()

    // Called when the app is launched
    func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil
    ) -> Bool {
        // Record WHY this process started before anything can boot off it.
        // iOS cold-launches the app into the background for BGTaskScheduler
        // work, silent pushes and background fetch — often on a locked device.
        // ExecutionContext turns that into the gate the boot path and PHP both
        // read, so the interactive start route stays parked until the app is
        // genuinely on screen.
        ExecutionContext.shared.start(launchState: application.applicationState)

        // Check if the app was launched from a URL (custom scheme)
        if let url = launchOptions?[UIApplication.LaunchOptionsKey.url] as? URL {
            DebugLogger.shared.log("📱 AppDelegate: Cold start with custom scheme URL: \(url)")
            // Pass the URL to the DeepLinkRouter
            DeepLinkRouter.shared.handle(url: url)
        }

        // Check if the app was launched from a Universal Link
        if let userActivityDictionary = launchOptions?[UIApplication.LaunchOptionsKey.userActivityDictionary] as? [String: Any],
           let userActivity = userActivityDictionary["UIApplicationLaunchOptionsUserActivityKey"] as? NSUserActivity,
           userActivity.activityType == NSUserActivityTypeBrowsingWeb,
           let url = userActivity.webpageURL {
            DebugLogger.shared.log("📱 AppDelegate: Cold start with Universal Link: \(url)")
            // Pass the URL to the DeepLinkRouter
            DeepLinkRouter.shared.handle(url: url)
        }

        // A background launch never connects a scene, so SplashView.onAppear
        // never fires and nothing would boot the PHP runtime that the work we
        // were woken for needs. Boot it here instead — headlessly: the
        // interactive phase stays parked inside NativePHPBootstrap until the
        // app becomes active. (Idempotent, so a foreground launch that
        // reaches onAppear first is unaffected.)
        if ExecutionContext.shared.launchedInBackground {
            DebugLogger.shared.log("📱 AppDelegate: headless background cold launch — booting PHP without UI")
            NativePHPBootstrap.shared.start()
        }

        return true
    }

    // Called for Universal Links
    func application(
        _ application: UIApplication,
        continue userActivity: NSUserActivity,
        restorationHandler: @escaping ([UIUserActivityRestoring]?) -> Void
    ) -> Bool {
        // Check if this is a Universal Link
        if userActivity.activityType == NSUserActivityTypeBrowsingWeb,
           let url = userActivity.webpageURL {
            // Pass the URL to the DeepLinkRouter
            DeepLinkRouter.shared.handle(url: url)
            return true
        }

        return false
    }

    // MARK: - Push Notification Token Handling (forwards to plugins via NotificationCenter)

    func application(
        _ application: UIApplication,
        didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data
    ) {
        NotificationCenter.default.post(
            name: .didRegisterForRemoteNotifications,
            object: nil,
            userInfo: ["deviceToken": deviceToken]
        )
    }

    func application(
        _ application: UIApplication,
        didFailToRegisterForRemoteNotificationsWithError error: Error
    ) {
        NotificationCenter.default.post(
            name: .didFailToRegisterForRemoteNotifications,
            object: nil,
            userInfo: ["error": error]
        )
    }

    // Handle deeplinks
    func application(
        _ app: UIApplication,
        open url: URL,
        options: [UIApplication.OpenURLOptionsKey: Any] = [:]
    ) -> Bool {
        // Pass the URL to the DeepLinkRouter
        DeepLinkRouter.shared.handle(url: url)
        return true
    }

    // Handle remote notifications (data-only messages, silent push)
    func application(
        _ application: UIApplication,
        didReceiveRemoteNotification userInfo: [AnyHashable: Any],
        fetchCompletionHandler completionHandler: @escaping (UIBackgroundFetchResult) -> Void
    ) {
        NotificationCenter.default.post(
            name: .didReceiveRemoteNotification,
            object: nil,
            userInfo: ["payload": userInfo]
        )
        completionHandler(.newData)
    }
}
