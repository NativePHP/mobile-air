import Foundation
import UserNotifications

/// Tells PHP when the user opens a notification, as
/// `Native\Mobile\Events\Notification\NotificationTapped`: a tap, an action,
/// or a reply typed into a text input action.
///
/// iOS has one notification center delegate per app, and it must be set
/// before launch finishes or a tap that launches the app is lost. Core sets
/// itself in `NativePHPApp.init`, right after the plugins' init functions
/// ran, and keeps the delegate a plugin set there as `next`. Every delegate
/// call is passed on to `next`, so that plugin keeps working as before, and
/// it is the one that decides how a notification is shown in the
/// foreground. Without one, a notification is not shown while the app is
/// open, as before.
///
/// A tap that launches the app arrives before PHP has booted. It is held
/// until a native event can reach PHP (`DeepLinkRouter.canDeliverEvents`),
/// as a cold-start deep link is, and then sent.
final class NotificationTapRouter: NSObject, UNUserNotificationCenterDelegate {
    static let shared = NotificationTapRouter()

    private static let event = "Native\\Mobile\\Events\\Notification\\NotificationTapped"

    /// Keys that say how a push was sent, not what it is about.
    private static let reservedKeys: Set<String> = ["aps", "fcm_options"]
    private static let reservedPrefixes = ["google.", "gcm."]

    /// The delegate that was set before core's. The center holds its
    /// delegate weakly too, so whoever set it already keeps it alive.
    private weak var next: UNUserNotificationCenterDelegate?

    /// The newest tap that arrived before PHP could hear it.
    private var pending: [String: Any?]?

    /// Become the delegate, keeping the one that was set before as `next`.
    /// Doing it again later picks up a delegate a plugin set since.
    static func install() {
        let center = UNUserNotificationCenter.current()
        guard center.delegate !== shared else { return }
        shared.next = center.delegate
        center.delegate = shared
    }

    /// Send a held tap, once a native event can reach PHP.
    func flushIfReady() {
        guard let payload = pending, DeepLinkRouter.shared.canDeliverEvents else { return }
        pending = nil
        LaravelBridge.shared.send?(Self.event, payload)
    }

    static func payload(for response: UNNotificationResponse) -> [String: Any?] {
        let action: String
        switch response.actionIdentifier {
        case UNNotificationDefaultActionIdentifier:
            action = "default"
        case UNNotificationDismissActionIdentifier:
            action = "dismiss"
        default:
            action = response.actionIdentifier
        }

        let userInfo = response.notification.request.content.userInfo
        var data: [String: Any] = [:]
        for (key, value) in userInfo {
            guard let key = key as? String,
                  !reservedKeys.contains(key),
                  !reservedPrefixes.contains(where: { key.hasPrefix($0) }),
                  JSONSerialization.isValidJSONObject([key: value]) else {
                continue
            }
            data[key] = value
        }

        // An FCM push carries its message ID, which is what Android reports
        // too. Anything else has only the request's identifier.
        let id = userInfo["gcm.message_id"] as? String ?? response.notification.request.identifier

        return [
            "action": action,
            "id": id,
            "data": data,
            "text": (response as? UNTextInputNotificationResponse)?.userText,
        ]
    }

    // MARK: - UNUserNotificationCenterDelegate

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse,
        withCompletionHandler completionHandler: @escaping () -> Void
    ) {
        let payload = Self.payload(for: response)
        if DeepLinkRouter.shared.canDeliverEvents {
            LaravelBridge.shared.send?(Self.event, payload)
        } else {
            pending = payload
        }

        if next?.userNotificationCenter?(center, didReceive: response, withCompletionHandler: completionHandler) == nil {
            completionHandler()
        }
    }

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification,
        withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void
    ) {
        if next?.userNotificationCenter?(center, willPresent: notification, withCompletionHandler: completionHandler) == nil {
            completionHandler([])
        }
    }

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        openSettingsFor notification: UNNotification?
    ) {
        next?.userNotificationCenter?(center, openSettingsFor: notification)
    }
}
