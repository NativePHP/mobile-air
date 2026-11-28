import Foundation
import UserNotifications

/// Notification center work that needs no push plugin.
/// Namespace: "Notification.*"
///
/// Push plugins implement `PushNotification.*`; these names are core's own,
/// so a plugin that registers its own `PushNotification.ClearBadge` keeps it.
enum NotificationFunctions {

    // MARK: - Notification.SetBadge

    /// The number on the app icon. 0 removes it. iOS shows it only when the
    /// user allowed badges.
    class SetBadge: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let count = parameters["count"] as? Int, count >= 0 else {
                throw BridgeError.invalidParameters("count must be a whole number of 0 or more")
            }

            UNUserNotificationCenter.current().setBadgeCount(count) { error in
                if let error {
                    NativeLog.debug("Notification.SetBadge failed: \(error.localizedDescription)")
                }
            }

            return ["success": true]
        }
    }

    // MARK: - Notification.RemoveDelivered

    /// Remove the delivered notifications whose thread identifier is
    /// `thread` (`aps.thread-id` in a push) and leave the rest.
    class RemoveDelivered: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let thread = parameters["thread"] as? String, !thread.isEmpty else {
                throw BridgeError.invalidParameters("thread must be a non-empty string")
            }

            let center = UNUserNotificationCenter.current()
            center.getDeliveredNotifications { delivered in
                let ids = delivered
                    .filter { $0.request.content.threadIdentifier == thread }
                    .map(\.request.identifier)
                if !ids.isEmpty {
                    center.removeDeliveredNotifications(withIdentifiers: ids)
                }
            }

            return ["success": true]
        }
    }
}

/// Notification categories, the sets of actions such as Reply or Mark read
/// that a notification can offer, shared by core, plugins and the app.
///
/// `UNUserNotificationCenter.setNotificationCategories` replaces every
/// category the app had, so two plugins that call it each remove the other's
/// actions. Register through this instead: it keeps everything registered
/// here, plus any category that was set directly, and replaces a category
/// that is registered again under the same identifier.
///
/// Call it from a plugin's init function, so the actions exist before a
/// notification that launches the app is shown.
enum NotificationCategories {
    private static let queue = DispatchQueue(label: "com.nativephp.notification-categories")
    private static var registered: [String: UNNotificationCategory] = [:]

    static func register(_ categories: [UNNotificationCategory]) {
        queue.async {
            for category in categories {
                registered[category.identifier] = category
            }
            let mine = Array(registered.values)

            let center = UNUserNotificationCenter.current()
            center.getNotificationCategories { existing in
                center.setNotificationCategories(merged(existing: existing, registered: mine))
            }
        }
    }

    /// The registered categories, plus those already set that none of them
    /// replaces.
    static func merged(existing: Set<UNNotificationCategory>, registered: [UNNotificationCategory]) -> Set<UNNotificationCategory> {
        let ids = Set(registered.map(\.identifier))
        return existing.filter { !ids.contains($0.identifier) }.union(registered)
    }
}
