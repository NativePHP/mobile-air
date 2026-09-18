<?php

namespace Native\Mobile\Support;

/**
 * Which release of the prebuilt PHP binaries this package installs.
 *
 * The binaries are built in NativePHP/php-bin-mobile and published as a
 * manifest named for the release — `4.0.0.json` — listing the artifacts that
 * belong to it. Pinning the version here rather than fetching a floating
 * `versions.json` means a given release of this package always resolves to
 * the binaries it was tested against, and publishing a newer set can't reach
 * an install that hasn't opted into it.
 *
 * Because it lives in this package, `composer.lock` already pins it. There's
 * nothing to record in `nativephp.lock`.
 *
 * ## When to bump
 *
 * Whenever you want the binaries this package installs to change. The one
 * case that is NOT optional: if the new release changes
 * `NPHP_FORMAT_VERSION` in the extension, the Swift and Kotlin readers must
 * move in the same release too — `EXPECTED_FORMAT_VERSION` in
 * `NativeElementBridge.kt` and `expectedFormatVersion` in
 * `NativeElementBridge.swift`. They compare the number on the first publish
 * and refuse to render at all on a mismatch, so an app built with mismatched
 * halves runs normally and never draws anything.
 *
 * You don't have to remember that: `FormatVersionSyncTest` fails the build
 * when the two readers disagree with each other, or with the `format_version`
 * published in the manifest for the release pinned below.
 */
class PhpBinaries
{
    /**
     * Release of the PHP binaries to install.
     *
     * Matches NATIVEPHP_VERSION in php-bin-mobile's config/versions.sh.
     */
    public const VERSION = '4.0.0';

    public const HOST = 'https://bin.nativephp.com';

    /** URL of the manifest listing every artifact in this release. */
    public static function manifestUrl(string $branch = 'main'): string
    {
        return self::HOST.'/'.$branch.'/'.self::VERSION.'.json';
    }
}
