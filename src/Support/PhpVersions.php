<?php

namespace Native\Mobile\Support;

/**
 * Which PHP minor an app bundles.
 *
 * Composer resolves the app's dependencies against the host PHP, so the
 * bundled runtime has to be the same minor or the app can fail on the device.
 *
 * The minors on offer are whatever the pinned php-bin-mobile release
 * publishes ({@see PhpBinaries::VERSION}). There is no list of them here, so
 * supporting a new PHP minor only takes a binaries release that builds it and
 * a bump of that pin.
 */
class PhpVersions
{
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

    /**
     * The minors a binaries manifest has builds for, newest first.
     *
     * @return list<string>
     */
    public static function available(?array $manifest): array
    {
        $versions = $manifest['versions'] ?? null;

        if (! is_array($versions)) {
            return [];
        }

        $minors = array_values(array_filter(
            array_map('strval', array_keys($versions)),
            fn (string $minor) => is_array($versions[$minor] ?? null),
        ));

        usort($minors, fn (string $a, string $b) => version_compare($b, $a));

        return $minors;
    }

    /**
     * The binaries to bundle for a host on $hostMinor, out of $available.
     *
     * Its own minor when the release has it. Otherwise the newest one the
     * host is at least, or the oldest when the host is older than all of
     * them. Either way the two differ, and `native:run` stops on that before
     * building. With nothing available it's the host's minor, and the
     * install reports that the release doesn't have it.
     *
     * @param  list<string>  $available  Newest first, as {@see available()} returns them.
     */
    public static function forHost(string $hostMinor, array $available): string
    {
        if ($available === [] || in_array($hostMinor, $available, true)) {
            return $hostMinor;
        }

        foreach ($available as $version) {
            if (version_compare($hostMinor, $version, '>=')) {
                return $version;
            }
        }

        return $available[array_key_last($available)];
    }
}
