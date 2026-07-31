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
     * Process-cached current appearance. Seeded on first read via a bridge
     * probe (`System.GetAppearance`) and kept fresh by the AppearanceChanged
     * event — a listener registered in NativeServiceProvider calls
     * rememberAppearance() when the OS flips the theme. Mirrors the Platform
     * caching pattern so hot-path reads (isDark() in render logic) avoid a
     * bridge round-trip per call.
     */
    private static ?string $appearance = null;

    /**
     * Current system appearance: 'light' or 'dark'. Off the device (tests, web
     * preview) the bridge is absent and this returns 'light'.
     */
    public function appearance(): string
    {
        if (self::$appearance !== null) {
            return self::$appearance;
        }

        if (function_exists('nativephp_call')) {
            $result = nativephp_call('System.GetAppearance', '{}');
            $mode = json_decode($result ?: '{}', true)['appearance'] ?? null;
            if ($mode === 'light' || $mode === 'dark') {
                return self::$appearance = $mode;
            }
        }

        return 'light';
    }

    public function isDarkMode(): bool
    {
        return $this->appearance() === 'dark';
    }

    public function isLightMode(): bool
    {
        return $this->appearance() === 'light';
    }

    /**
     * Update the process-cached appearance. Called by the AppearanceChanged
     * listener so `appearance()` stays fresh without re-probing the bridge.
     */
    public static function rememberAppearance(string $mode): void
    {
        if ($mode === 'light' || $mode === 'dark') {
            self::$appearance = $mode;
        }
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
