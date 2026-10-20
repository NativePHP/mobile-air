<?php

namespace Native\Mobile\Support;

/**
 * Which release of the PHP binaries is actually installed in a platform
 * project, as opposed to the one this package pins.
 *
 * `native:install` copies the binaries into nativephp/ios and
 * nativephp/android, and they stay there until the next install. Updating
 * nativephp/mobile moves {@see PhpBinaries::VERSION} but leaves the old
 * libraries in place, and so does switching NATIVEPHP_BIN_BRANCH, since a
 * branch build and a main build share a release number. Nothing in the build
 * notices: the app builds and runs against whatever was installed last.
 *
 * So the install writes down what it put there, and `native:run` compares it
 * with what this package would install now.
 *
 * The record lives in a small JSON file inside each platform project, like
 * {@see BuildState}, and not in nativephp.lock. Apps commit nativephp.lock
 * (the starter kit does), so a record there would describe whichever machine
 * last committed it. A teammate who pulls that commit would be told their
 * binaries are current while their own nativephp/ios still holds the old
 * ones. The platform projects are gitignored, so a file inside one only ever
 * describes the binaries next to it. It also goes away when `native:install`
 * replaces the project, so an install that fails before the binaries are in
 * place leaves no record rather than a stale one.
 *
 * One file per platform because the platforms are installed separately:
 * `native:install ios` leaves Android's binaries exactly as they were.
 */
class InstalledBinaries
{
    public const FILE = '.nativephp-binaries.json';

    /**
     * The release and branch an install would put there now: the pinned
     * release, or the prerelease NATIVEPHP_BIN_BRANCH asks for.
     *
     * @return array{release: string, branch: string}
     */
    public static function expected(): array
    {
        return [
            'release' => PhpBinaries::release(),
            'branch' => PhpBinaries::branch(),
        ];
    }

    /**
     * Record that the binaries for {@see expected()} were installed.
     */
    public static function record(string $platformPath): void
    {
        if (! is_dir($platformPath)) {
            return;
        }

        file_put_contents(
            self::path($platformPath),
            json_encode(self::expected(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );
    }

    /**
     * Forget the record, before replacing the binaries it describes.
     */
    public static function forget(string $platformPath): void
    {
        $file = self::path($platformPath);

        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * What the last successful install recorded, or null if nothing did.
     *
     * Null covers every project installed before this record existed, and
     * any install that didn't finish.
     *
     * @return array{release: string, branch: string}|null
     */
    public static function read(string $platformPath): ?array
    {
        $file = self::path($platformPath);

        if (! is_file($file)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);

        if (! is_array($data) || ! is_string($data['release'] ?? null) || $data['release'] === '') {
            return null;
        }

        $branch = $data['branch'] ?? 'main';

        return [
            'release' => $data['release'],
            'branch' => is_string($branch) && $branch !== '' ? $branch : 'main',
        ];
    }

    /**
     * A release as a developer would want to read it: `4.1.0`, or
     * `4.1.0 (feat/opcache branch)` when it isn't main's.
     *
     * @param  array{release: string, branch: string}  $binaries
     */
    public static function describe(array $binaries): string
    {
        return $binaries['branch'] === 'main'
            ? $binaries['release']
            : "{$binaries['release']} ({$binaries['branch']} branch)";
    }

    private static function path(string $platformPath): string
    {
        return rtrim($platformPath, '/\\').DIRECTORY_SEPARATOR.self::FILE;
    }
}
