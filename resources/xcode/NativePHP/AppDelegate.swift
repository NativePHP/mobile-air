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

    /// Posted when a Home Screen quick action is chosen (cold launch or while running)
    /// userInfo: ["shortcutItem": UIApplicationShortcutItem]
    static let didReceiveShortcutItem = Notification.Name("NativePHP.didReceiveShortcutItem")

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

        return true
    }

    // The SwiftUI WindowGroup's scene gets NativePHPSceneDelegate, so quick actions reach the app.
    func application(
        _ application: UIApplication,
        configurationForConnecting connectingSceneSession: UISceneSession,
        options: UIScene.ConnectionOptions
    ) -> UISceneConfiguration {
        let configuration = UISceneConfiguration(name: nil, sessionRole: connectingSceneSession.role)
        configuration.delegateClass = NativePHPSceneDelegate.self
        return configuration
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

/// Home Screen quick actions. The app is a SwiftUI App, so it runs with
/// scenes: iOS hands a quick action to the scene (connectionOptions on a cold
/// launch, windowScene(_:performActionFor:) while running), never to
/// launchOptions or application(_:performActionFor:). Posted as
/// NativePHP.didReceiveShortcutItem for plugins to handle.
class NativePHPSceneDelegate: NSObject, UIWindowSceneDelegate {
    func scene(_ scene: UIScene, willConnectTo session: UISceneSession, options connectionOptions: UIScene.ConnectionOptions) {
        if let shortcutItem = connectionOptions.shortcutItem {
            DebugLogger.shared.log("📱 SceneDelegate: cold launch with quick action \(shortcutItem.type)")
            NotificationCenter.default.post(name: .didReceiveShortcutItem, object: nil, userInfo: ["shortcutItem": shortcutItem])
        }
    }

    func windowScene(_ windowScene: UIWindowScene, performActionFor shortcutItem: UIApplicationShortcutItem, completionHandler: @escaping (Bool) -> Void) {
        DebugLogger.shared.log("📱 SceneDelegate: quick action \(shortcutItem.type)")
        NotificationCenter.default.post(name: .didReceiveShortcutItem, object: nil, userInfo: ["shortcutItem": shortcutItem])
        completionHandler(true)
    }
}
