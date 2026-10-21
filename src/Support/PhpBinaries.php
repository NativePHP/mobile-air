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
 * Because it lives in this package, `composer.lock` already pins which
 * release an app should have. That isn't the same as the release it has: the
 * binaries are copied into nativephp/ios and nativephp/android at install
 * time and stay there when this package is updated. {@see InstalledBinaries}
 * records what each install put there, and `native:run` warns when that isn't
 * the release this package would install now.
 *
 * ## Prereleases
 *
 * php-bin-mobile can also publish a build of any commit under a name with a
 * prerelease suffix, such as `4.2.0-rc.1`. Nothing pins those, so they only
 * reach an app that asks for one through NATIVEPHP_BIN_BRANCH:
 * `main/4.2.0-rc.1` installs main's 4.2.0-rc.1, and a bare `4.2.0-rc.1`
 * means the same. {@see release()} is what gets installed. VERSION stays the
 * release this package was tested against.
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
     * Release of the PHP binaries to install, unless NATIVEPHP_BIN_BRANCH
     * asks for a prerelease. See {@see release()}.
     *
     * Matches NATIVEPHP_VERSION in php-bin-mobile's config/versions.sh.
     */
    public const VERSION = '4.1.0';

    public const HOST = 'https://bin.nativephp.com';

    /**
     * A release with a prerelease suffix: `4.2.0-rc.1`.
     *
     * The suffix is required. A plain `4.2.0` at the end of
     * NATIVEPHP_BIN_BRANCH is part of a branch name like `feat/4.2.0`, not a
     * request for that release.
     */
    private const PRERELEASE = '/^\d+\.\d+\.\d+-[0-9A-Za-z.]+$/';

    /** URL of the manifest listing every artifact in the release to install. */
    public static function manifestUrl(?string $branch = null, ?string $release = null): string
    {
        return self::HOST.'/'.($branch ?? self::branch()).'/'.($release ?? self::release()).'.json';
    }

    /** Which branch's artifacts to install. */
    public static function branch(): string
    {
        return self::parse()['branch'];
    }

    /**
     * Which release to install: VERSION, unless NATIVEPHP_BIN_BRANCH ends in
     * a prerelease.
     */
    public static function release(): string
    {
        return self::parse()['release'];
    }

    public static function isPrerelease(): bool
    {
        return self::release() !== self::VERSION;
    }

    /** NATIVEPHP_BIN_BRANCH as set, or `main`. */
    public static function selection(): string
    {
        $value = env('NATIVEPHP_BIN_BRANCH');
        $value = is_string($value) ? trim($value, " \t/") : '';

        return $value === '' ? 'main' : $value;
    }

    /**
     * Split NATIVEPHP_BIN_BRANCH into the branch and the release.
     *
     * Only the last segment can name a release, so `main/4.2.0-rc.1` is
     * main's 4.2.0-rc.1, and anything that doesn't end in a prerelease is a
     * branch of the pinned release, as it always was.
     *
     * @return array{branch: string, release: string}
     */
    private static function parse(): array
    {
        $selection = self::selection();
        $slash = strrpos($selection, '/');
        $last = $slash === false ? $selection : substr($selection, $slash + 1);

        if (preg_match(self::PRERELEASE, $last) !== 1) {
            return ['branch' => $selection, 'release' => self::VERSION];
        }

        return [
            'branch' => $slash === false ? 'main' : substr($selection, 0, $slash),
            'release' => $last,
        ];
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
     *
     * A prerelease gets its own directory too, `main-4.2.0-rc.1`, so forcing
     * a fresh download of it never clears main's archives, or the other way
     * round.
     */
    public static function branchSegment(): string
    {
        $source = self::isPrerelease() ? self::branch().'/'.self::release() : self::branch();

        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $source) ?: 'main';
    }

    /**
     * The wire format this package's native readers expect.
     *
     * Read from the Kotlin reader's source, the same way FormatVersionSyncTest
     * does. The Swift reader has to agree with it, and that test fails the
     * build when it doesn't. Null when it can't be found, so a moved file
     * costs the check that uses this rather than the install.
     */
    public static function readerFormatVersion(): ?int
    {
        $source = @file_get_contents(
            dirname(__DIR__, 2).'/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/nativerender/NativeElementBridge.kt'
        );

        if ($source === false || preg_match_all('/\bEXPECTED_FORMAT_VERSION\s*=\s*(\d+)/', $source, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1][0];
    }
}
