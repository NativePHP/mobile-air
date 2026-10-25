<?php

namespace Native\Mobile;

use Native\Mobile\Facades\System as SystemFacade;

/**
 * @deprecated Use the System facade instead: System::platform(),
 *             System::isIos() and System::isAndroid(). It reads the platform
 *             from the env the iOS and Android shells set, and caches the
 *             device probe under `native:jump` the way this class did.
 *             Platform will be removed in the next major version.
 */
class Platform
{
    public const IOS = 'ios';

    public const ANDROID = 'android';

    /**
     * @deprecated Use System::platform().
     */
    public static function current(): ?string
    {
        try {
            return SystemFacade::platform();
        } catch (\Throwable) {
            // No app booted. This never threw before, so don't start now.
            return null;
        }
    }

    /**
     * @deprecated Use System::isIos().
     */
    public static function isIos(): bool
    {
        return self::current() === self::IOS;
    }

    /**
     * @deprecated Use System::isAndroid().
     */
    public static function isAndroid(): bool
    {
        return self::current() === self::ANDROID;
    }

    /**
     * @deprecated Render a component with `Native::test(..., platform:)`, or
     *             set NATIVEPHP_PLATFORM in the test.
     */
    public static function set(?string $platform): void
    {
        System::rememberPlatform($platform);
    }
}
