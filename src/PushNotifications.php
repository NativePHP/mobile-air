<?php

namespace Native\Mobile;

class PushNotifications
{
    /**
     * Request push notification permissions and enroll for notifications
     * Platform-agnostic method that handles both iOS APNS and Android FCM
     *
     * Returns a PendingPushNotificationEnrollment for fluent API usage:
     *
     * @example
     * PushNotifications::enroll(); // Simple usage
     * @example
     * PushNotifications::enroll()->id('my-enrollment')->remember(); // With ID tracking
     */
    public function enroll(): PendingPushNotificationEnrollment
    {
        return new PendingPushNotificationEnrollment;
    }

    /**
     * Check current push notification permission status without prompting the user
     * Returns: "granted", "denied", "not_determined", "provisional", or "ephemeral"
     */
    public function checkPermission(): ?string
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        $result = nativephp_call('PushNotification.CheckPermission', '{}');

        if ($result) {
            $decoded = json_decode($result, true);

            return $decoded['status'] ?? null;
        }

        return null;
    }

    /**
     * Get the current push notification token
     * Returns APNS token on iOS, FCM token on Android, or null if not available
     */
    public function getToken(): ?string
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        $result = nativephp_call('PushNotification.GetToken', '{}');

        if ($result) {
            $decoded = json_decode($result, true);

            $token = $decoded['token'] ?? null;

            // Android returns empty string when no token is available, treat it as null
            return $token === '' ? null : $token;
        }

        return null;
    }

    /**
     * Set the number on the app icon, such as the unread count. 0 removes it.
     *
     * Core does this itself, so it works with any push plugin or none. iOS
     * shows the number only if the permission request included badges and
     * the user allowed them. Android has no API for a number on the icon:
     * launchers show a dot while the app has notifications, so this does
     * nothing there. The
     * reliable way to keep the badge right while the app is closed is still
     * the `aps.badge` field of each push, set by your server.
     */
    public function setBadge(int $count): void
    {
        if ($count < 0) {
            throw new \InvalidArgumentException("The badge count can't be negative, got {$count}.");
        }

        if (function_exists('nativephp_call')) {
            nativephp_call('Notification.SetBadge', json_encode(['count' => $count]));
        }
    }

    /**
     * Remove the delivered notifications of one thread, such as a conversation
     * the user has just read, and leave the others in Notification Center.
     *
     * The thread is the notification's `thread-id` on iOS (`aps.thread-id` in
     * a push, `threadIdentifier` locally), and its tag or group on Android
     * (`android.notification.tag` in an FCM message). Works with any push
     * plugin or none, and for local notifications too.
     */
    public function removeDelivered(string $thread): void
    {
        if ($thread === '') {
            throw new \InvalidArgumentException('The thread to remove notifications for is empty.');
        }

        if (function_exists('nativephp_call')) {
            nativephp_call('Notification.RemoveDelivered', json_encode(['thread' => $thread]));
        }
    }

    /**
     * Clear the app icon badge number. The installed push plugin implements
     * this, and may remove delivered notifications too.
     */
    public function clearBadge(): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        nativephp_call('PushNotification.ClearBadge', '{}');
    }
}
