<?php

namespace Native\Mobile;

use Native\Mobile\Facades\Device;

class System
{
    /**
     * Toggle the device flashlight on/off.
     *
     * @deprecated Use \Native\Mobile\Facades\Device::toggleFlashlight() instead
     */
    #[\Deprecated(message: 'Use \Native\Mobile\Facades\Device::flashlight() instead', since: '2.0.0')]
    public function flashlight(): void
    {
        // Use the new god method pattern via Device class
        if (function_exists('nativephp_call')) {
            nativephp_call('Device.ToggleFlashlight', '{}');
        }
    }

    public function isIos(): bool
    {
        $info = Device::getInfo();
        if ($info) {
            return json_decode($info)->platform === 'ios';
        }

        return false;
    }

    public function isAndroid(): bool
    {
        $info = Device::getInfo();
        if ($info) {
            return json_decode($info)->platform === 'android';
        }

        return false;
    }

    public function isMobile(): bool
    {
        $info = Device::getInfo();
        if ($info) {
            $platform = json_decode($info)->platform ?? null;

            return in_array($platform, ['ios', 'android']);
        }

        return false;
    }

    /**
     * Open the app's settings screen in the device settings.
     *
     * This allows users to manage permissions (e.g., push notifications,
     * camera, location) that they've granted or denied for the app.
     */
    public function appSettings(): void
    {
        if (function_exists('nativephp_call')) {
            nativephp_call('System.OpenAppSettings', '{}');
        }
    }
}
