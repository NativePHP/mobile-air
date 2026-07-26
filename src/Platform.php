<?php

namespace Native\Mobile;

use Native\Mobile\Enums\Os;
use Native\Mobile\Facades\System;

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
    public const IOS = Os::Ios->value;

    public const ANDROID = Os::Android->value;

    private static ?string $platform = null;

    private static bool $detected = false;

    /** Throttle re-detection after a failed attempt (see current()). */
    private static ?float $lastFailedAttempt = null;

    /**
     * Return `'ios'` / `'android'` / `null`. Cached after first SUCCESSFUL
     * detection.
     *
     * Failure is deliberately NOT cached: detection is a bridge round-trip
     * (Device.GetInfo), and in Jump dev mode the first render of a session
     * can race the device's WebSocket attach — the call fails once, and a
     * cached failure would poison this long-lived `php -S` worker (and the
     * element runloop request, which lives for the whole screen) so every
     * platform-resolved icon/variant it ever renders comes out null/blank.
     * Instead, failed attempts are retried, throttled to one bridge probe
     * every 2s so hot paths (Tailwind parser, icon resolver) never pay a
     * bridge timeout per call.
     */
    public static function current(): ?string
    {
        if (self::$detected) {
            return self::$platform;
        }

        // Defensive — no bridge available outside the mobile runtime.
        if (! function_exists('nativephp_call')
            || ! class_exists(System::class)) {
            self::$detected = true;

            return self::$platform;
        }

        if (self::$lastFailedAttempt !== null
            && (microtime(true) - self::$lastFailedAttempt) < 2.0) {
            return self::$platform;
        }

        try {
            if (System::isIos()) {
                self::$platform = self::IOS;
            } elseif (System::isAndroid()) {
                self::$platform = self::ANDROID;
            }
        } catch (\Throwable) {
            // Leave null — System wasn't ready.
        }

        if (self::$platform !== null) {
            self::$detected = true;
            self::$lastFailedAttempt = null;
        } else {
            self::$lastFailedAttempt = microtime(true);
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
     * The detected platform as an enum case, or null when it isn't known.
     * Prefer this over {@see current()} in new code — it can't be compared
     * against a misspelt string.
     */
    public static function os(): ?Os
    {
        $platform = self::current();

        return $platform === null ? null : Os::fromLabel($platform);
    }

    /**
     * Test seam — force the cached platform value. Pass `null` to reset
     * to lazy detection on the next call.
     *
     * Accepts an {@see Os} case or its string spelling. An unrecognised
     * string throws rather than being stored: silently accepting
     * `set('androdi')` leaves isAndroid() false for the rest of the
     * process with nothing to show for it, and the whole point of a test
     * seam is that you can trust what it says.
     *
     * Resetting the cache is the caller's responsibility for any
     * downstream consumer that also caches keyed by platform (e.g.
     * `\Native\Mobile\Edge\TailwindParser::clearCache()`).
     *
     * @throws \InvalidArgumentException
     */
    public static function set(Os|string|null $platform): void
    {
        if (is_string($platform)) {
            $platform = Os::fromLabel($platform) ?? throw new \InvalidArgumentException(
                "Unknown platform [{$platform}]. Use Os::Ios, Os::Android, 'ios' or 'android'."
            );
        }

        self::$platform = $platform?->value;
        self::$detected = $platform !== null;
        self::$lastFailedAttempt = null;
    }
}
