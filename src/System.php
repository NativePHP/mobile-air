<?php

namespace Native\Mobile;

use Native\Mobile\Facades\Device;

class System
{
    /**
     * Process-cached current appearance. Seeded on first read via a bridge
     * probe (`System.GetAppearance`) and kept fresh by the AppearanceChanged
     * event — a listener registered in NativeServiceProvider calls
     * rememberAppearance() when the OS flips the theme. Mirrors the Platform
     * caching pattern so hot-path reads (isDark() in render logic) avoid a
     * bridge round-trip per call.
     */
    private static ?string $appearance = null;

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
     * Is this process running under `native:jump`?
     *
     * In Jump hybrid mode PHP runs on the DEVELOPER'S MACHINE and only bridge
     * calls cross to the phone. Anything the app hands the device that resolves
     * locally — a filesystem path, a `localhost` URL — is meaningless over
     * there, because "there" is a different computer. Code that builds
     * device-bound values (core and plugins alike) checks this to hand over
     * something the device can actually reach.
     *
     * Gated on `JUMP_BRIDGE_PORT`, which `native:jump` exports into the Laravel
     * server it spawns. NOT on `function_exists('nativephp_call')` — in Jump
     * mode the PHP fallback DEFINES that function (see
     * NativeServiceProvider::registerJumpBridgeFallback()), so it exists on the
     * dev server as well as on device and would report true everywhere.
     *
     * Static, and a bare env read, because callers include per-element render
     * paths that also run on device, where this must not cost a facade resolve
     * or a bridge round-trip.
     */
    public static function runningInJump(): bool
    {
        return getenv('JUMP_BRIDGE_PORT') !== false;
    }

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
