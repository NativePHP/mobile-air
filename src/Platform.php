<?php

namespace Native\Mobile;

/**
 * Cached, process-scoped current-platform detection.
 *
 * Used by hot-path resolvers (Tailwind variant parser, icon resolver,
 * future per-platform builders) that can't afford a bridge round-trip
 * per call.
 *
 * One PHP process always runs on one platform — so detection happens
 * lazily on first read and the result sticks for the life of the
 * request. Outside the mobile runtime (tests, web preview) the bridge
 * function is absent and detection returns `null`; callers should treat
 * that as "platform unknown" and degrade gracefully (e.g. drop
 * platform-variant classes silently).
 */
class Platform
{
    public const IOS = 'ios';
    public const ANDROID = 'android';

    private static ?string $platform = null;
    private static bool $detected = false;

    /**
     * Return `'ios'` / `'android'` / `null`. Cached after first call.
     */
    public static function current(): ?string
    {
        if (self::$detected) {
            return self::$platform;
        }
        self::$detected = true;

        // Defensive — no bridge available outside the mobile runtime.
        if (! function_exists('nativephp_call')
            || ! class_exists(\Native\Mobile\Facades\System::class)) {
            return self::$platform;
        }

        try {
            if (\Native\Mobile\Facades\System::isIos()) {
                self::$platform = self::IOS;
            } elseif (\Native\Mobile\Facades\System::isAndroid()) {
                self::$platform = self::ANDROID;
            }
        } catch (\Throwable) {
            // Leave null — System wasn't ready.
        }

        return self::$platform;
    }

    public static function isIos(): bool
    {
        return self::current() === self::IOS;
    }

    public static function isAndroid(): bool
    {
        return self::current() === self::ANDROID;
    }

    /**
     * Test seam — force the cached platform value. Pass `null` to reset
     * to lazy detection on the next call.
     *
     * Resetting the cache is the caller's responsibility for any
     * downstream consumer that also caches keyed by platform (e.g.
     * `\Native\Mobile\Edge\TailwindParser::clearCache()`).
     */
    public static function set(?string $platform): void
    {
        self::$platform = $platform;
        self::$detected = $platform !== null;
    }
}
