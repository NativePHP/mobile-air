<?php

namespace Native\Mobile\Support;

/**
 * The PHP minors an app can bundle, and which one a given host gets.
 *
 * `native:install` picks the binaries to download from this list, and
 * `native:run` checks the host PHP against it before offering to reinstall.
 * Composer resolves the app's dependencies against the host PHP, so the
 * bundled runtime has to be the same minor or the app can fail on the device.
 *
 * Listing a minor here doesn't make its binaries exist. They come from the
 * php-bin-mobile release pinned in {@see PhpBinaries::VERSION}, and when that
 * release doesn't include the minor, the install says so and stops.
 */
class PhpVersions
{
    /**
     * Newest first. {@see forHost()} relies on the order.
     *
     * @var list<string>
     */
    public const SUPPORTED = ['8.6', '8.5', '8.4', '8.3'];

    /** The minor of the PHP running this process, like `8.6`. */
    public static function host(): string
    {
        return PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    }

    /** `8.6` from `8.6.0`, `8.6.0RC7` or `8.6`. */
    public static function minor(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }

    public static function isSupported(string $minor): bool
    {
        return in_array($minor, self::SUPPORTED, true);
    }

    /**
     * The binaries to bundle for a host on $hostMinor.
     *
     * Its own minor when that's supported. A host newer than anything listed
     * gets the newest, and one older than everything gets the oldest. Either
     * way the two differ, and `native:run` stops on that before building.
     */
    public static function forHost(string $hostMinor): string
    {
        if (self::isSupported($hostMinor)) {
            return $hostMinor;
        }

        foreach (self::SUPPORTED as $version) {
            if (version_compare($hostMinor, $version, '>=')) {
                return $version;
            }
        }

        return self::SUPPORTED[array_key_last(self::SUPPORTED)];
    }
}
