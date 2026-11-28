<?php

namespace Native\Mobile\Facades;

use Illuminate\Support\Facades\Facade;
use Native\Mobile\PendingPushNotificationEnrollment;

/**
 * @method static PendingPushNotificationEnrollment enroll()
 * @method static string|null checkPermission()
 * @method static string|null getToken()
 * @method static void clearBadge()
 * @method static void setBadge(int $count)
 * @method static void removeDelivered(string $thread)
 *
 * @see \Native\Mobile\PushNotifications
 */
class PushNotifications extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Native\Mobile\PushNotifications::class;
    }
}
