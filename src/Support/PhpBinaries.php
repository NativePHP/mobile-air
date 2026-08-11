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

    /** Which branch's artifacts to install. */
    public static function branch(): string
    {
        return env('NATIVEPHP_BIN_BRANCH', 'main');
    }

    /**
     * Where downloaded archives are cached, scoped to the branch they came from.
     *
     * The archive filename carries the release and the PHP version but not the
     * branch: `ios-4.0.0-php8.4.24.zip` is what main and a feature branch both
     * publish. A flat cache treats those as the same file, so setting
     * NATIVEPHP_BIN_BRANCH after installing from main finds the old archive,
     * reports a cache hit, and installs main's binaries under the impression it
     * installed the branch's. Nothing in the output says otherwise, which makes
     * it the kind of bug that invalidates a test run rather than failing it.
     */
    public static function cacheDirectory(): string
    {
        return base_path('nativephp/binaries').DIRECTORY_SEPARATOR.self::branchSegment();
    }

    /**
     * The branch as one safe path segment.
     *
     * Branch names contain slashes — `feat/surfaces-phase1` — which would nest
     * directories and let a branch named `feat` collide with the parent of
     * `feat/anything`. Encoding the separator keeps it to one directory per
     * branch.
     */
    public static function branchSegment(): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', self::branch()) ?: 'main';
    }
}
