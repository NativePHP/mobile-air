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

        return $result['status'] ?? null;
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

        $token = $result['token'] ?? null;

        // Android returns empty string when no token is available, treat it as null
        return $token === '' ? null : $token;
    }

    /**
     * Request critical alert permission
     * iOS: Requests .criticalAlert authorization (requires entitlement)
     * Android: Checks DND bypass access (user must grant in system settings)
     * Returns: "granted", "denied", or "not_supported" (iOS only)
     */
    public function requestCriticalPermission(): ?string
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        $result = nativephp_call('PushNotification.RequestCriticalPermission', '{}');

        if ($result) {
            $decoded = json_decode($result, true);

            return $decoded['status'] ?? null;
        }

        return null;
    }

    /**
     * Check critical alert permission status without prompting
     * iOS: Reads criticalAlertSetting from notification settings
     * Android: Checks isNotificationPolicyAccessGranted
     * Returns: "granted", "denied", or "not_supported" (iOS only)
     */
    public function checkCriticalPermission(): ?string
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        $result = nativephp_call('PushNotification.CheckCriticalPermission', '{}');

        if ($result) {
            $decoded = json_decode($result, true);

            return $decoded['status'] ?? null;
        }

        return null;
    }

    /**
     * Open system settings for critical alert / DND bypass configuration
     * iOS: Opens app settings
     * Android: Opens ACTION_NOTIFICATION_POLICY_ACCESS_SETTINGS
     */
    public function openCriticalSettings(): void
    {
        if (! function_exists('nativephp_call')) {
            return;
        }

        nativephp_call('PushNotification.OpenCriticalSettings', '{}');
    }
}
