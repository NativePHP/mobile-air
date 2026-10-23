<?php

namespace Native\Mobile;

use Native\Mobile\Facades\Device;

class System
{
    /**
     * Process-cached current appearance. Seeded on first read via a bridge
     * probe (`System.GetAppearance`) and kept fresh by the AppearanceChanged
     * event — a listener registered in NativeServiceProvider calls
     * rememberAppearance() when the OS flips the theme. Mirrors the platform
     * caching pattern so hot-path reads (isDark() in render logic) avoid a
     * bridge round-trip per call.
     */
    private static ?string $appearance = null;

    /**
     * Process-cached current app-window orientation. Same lifecycle as $appearance:
     * seeded on first read via `System.GetOrientation`, kept fresh by the
     * OrientationChanged event.
     */
    private static ?string $orientation = null;

    /**
     * The platform the device reported under `native:jump`, or the one a test
     * is pretending to be on (see rememberPlatform()). Unused on device, where
     * the env answers first.
     */
    private static ?string $platform = null;

    /** Throttle re-probing after a failed attempt (see platform()). */
    private static ?float $lastFailedProbe = null;

    /**
     * 'ios' or 'android', or null off the device.
     *
     * On device the iOS and Android shells export NATIVEPHP_PLATFORM before
     * PHP starts, so this is an env read with no bridge round-trip. That
     * matters because @ios, @android, @mobile, @web, the isIos() helpers,
     * Tailwind's ios:/android: variants and platform icons all land here.
     *
     * Under `native:jump` PHP runs on the developer's machine and nothing sets
     * the variable, so ask the connected device (Device.GetInfo) and keep the
     * answer: one PHP process always talks to one platform.
     *
     * A failed probe is deliberately NOT kept: the first render of a Jump
     * session can race the device's WebSocket attach, and a remembered failure
     * would poison this long-lived `php -S` worker (and the element runloop
     * request, which lives for the whole screen) so every platform-resolved
     * icon/variant it ever renders comes out blank. Failed probes are retried
     * instead, at most once every 2s, so hot paths never pay a bridge timeout
     * per call. Off device and outside Jump (tests, a web server) the probe
     * finds no device and this returns null.
     */
    public function platform(): ?string
    {
        $platform = getenv('NATIVEPHP_PLATFORM');

        if ($platform === 'ios' || $platform === 'android') {
            return $platform;
        }

        if (self::$platform !== null) {
            return self::$platform;
        }

        if (self::$lastFailedProbe !== null
            && (microtime(true) - self::$lastFailedProbe) < 2.0) {
            return null;
        }

        try {
            $info = json_decode(Device::getInfo() ?? '', true);
            $platform = is_array($info) ? ($info['platform'] ?? null) : null;
        } catch (\Throwable) {
            // The bridge or the container wasn't ready. Try again later.
            $platform = null;
        }

        if ($platform === 'ios' || $platform === 'android') {
            self::$lastFailedProbe = null;

            return self::$platform = $platform;
        }

        self::$lastFailedProbe = microtime(true);

        return null;
    }

    /**
     * Test seam behind `Native::test(..., platform:)`: pretend to be on a
     * platform without touching NATIVEPHP_PLATFORM, which would leak into the
     * next test's app boot. Pass null to forget it, and anything the Jump
     * probe remembered, so the next read asks again.
     *
     * Ignored wherever NATIVEPHP_PLATFORM is set, so it can't move a device.
     *
     * @internal
     */
    public static function rememberPlatform(?string $platform): void
    {
        self::$platform = $platform;
        self::$lastFailedProbe = null;
    }

    public function isIos(): bool
    {
        return $this->platform() === 'ios';
    }

    public function isAndroid(): bool
    {
        return $this->platform() === 'android';
    }

    public function isMobile(): bool
    {
        return $this->platform() !== null;
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
     * Current app-window orientation: 'portrait' or 'landscape'. This describes
     * the window aspect rather than the physical device, which matters in
     * multi-window modes. Off device (tests, web preview), the bridge is absent
     * and this returns 'portrait'.
     */
    public function orientation(): string
    {
        if (self::$orientation !== null) {
            return self::$orientation;
        }

        if (function_exists('nativephp_call')) {
            $result = nativephp_call('System.GetOrientation', '{}');
            $orientation = json_decode($result ?: '{}', true)['orientation'] ?? null;
            if ($orientation === 'portrait' || $orientation === 'landscape') {
                return self::$orientation = $orientation;
            }
        }

        return 'portrait';
    }

    public function isPortrait(): bool
    {
        return $this->orientation() === 'portrait';
    }

    public function isLandscape(): bool
    {
        return $this->orientation() === 'landscape';
    }

    /**
     * Update the process-cached orientation. Called by the OrientationChanged
     * listener so `orientation()` stays fresh without re-probing the bridge.
     */
    public static function rememberOrientation(string $orientation): void
    {
        if ($orientation === 'portrait' || $orientation === 'landscape') {
            self::$orientation = $orientation;
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
