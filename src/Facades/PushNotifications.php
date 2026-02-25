<?php

namespace Native\Mobile\Facades;

use Illuminate\Support\Facades\Facade;
use Native\Mobile\PendingPushNotificationEnrollment;

/**
 * @method static PendingPushNotificationEnrollment enroll()
 * @method static string|null checkPermission()
 * @method static string|null getToken()
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
