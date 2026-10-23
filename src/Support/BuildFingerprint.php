<?php

namespace Native\Mobile\Support;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Fingerprints of the inputs to the build steps that BuildState lets us skip.
 *
 * Every method returns null when it can't vouch for the inputs, which makes
 * the step run. When in doubt, run the step: a slow build is annoying, a
 * stale app is much worse.
 */
class BuildFingerprint
{
    /**
     * Composer scripts that may run during `composer install` without making
     * the install unsafe to skip. Laravel's defaults only rebuild
     * bootstrap/cache/packages.php, which depends on vendor/ and
     * composer.json, and both are part of the fingerprint.
     */
    private const SAFE_INSTALL_SCRIPTS = [
        'Illuminate\\Foundation\\ComposerScripts::postAutoloadDump',
        'Illuminate\\Foundation\\ComposerScripts::postInstall',
        '@php artisan package:discover',
        '@php artisan package:discover --ansi',
    ];

    private const INSTALL_SCRIPT_EVENTS = [
        'pre-install-cmd',
        'post-install-cmd',
        'pre-autoload-dump',
        'post-autoload-dump',
    ];

    /**
     * Combine any JSON-encodable parts into a single fingerprint.
     */
    public static function of(mixed $parts): string
    {
        return sha1((string) json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * Content hash of a file, or a marker when it doesn't exist.
     */
    public static function file(string $path): string
    {
        return is_file($path) ? (string) sha1_file($path) : 'missing';
    }

    /**
     * Inputs to `composer install` in the staged app: composer.json,
     * composer.lock, the flags, the PHP and Composer binaries, and every
     * file in the project's vendor/ directory that the bundle copy would
     * pick up (size and times, following path-repository symlinks).
     *
     * The vendor walk is what catches path repositories and hand-edited
     * vendor files, which change without composer.lock changing.
     *
     * @param  array<int, string>  $flags
     */
    public static function composer(string $basePath, array $flags): ?string
    {
        $basePath = rtrim($basePath, '/');
        $composerJson = $basePath.'/composer.json';
        $composerLock = $basePath.'/composer.lock';

        if (! is_file($composerJson) || ! is_file($composerLock) || ! is_dir($basePath.'/vendor')) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($composerJson), true);

        if (! is_array($decoded) || self::hasCustomInstallScripts($decoded)) {
            return null;
        }

        $vendor = self::tree($basePath, 'vendor', function (string $relative, string $name): bool {
            if (in_array($name, BundleExclusions::ANY_DEPTH, true)) {
                return true;
            }

            foreach (BundleExclusions::VENDOR_PATHS as $pattern) {
                if (fnmatch($pattern, $relative, FNM_PATHNAME)) {
                    return true;
                }
            }

            return false;
        });

        if ($vendor === null) {
            return null;
        }

        return self::of([
            'composer.json' => self::file($composerJson),
            'composer.lock' => self::file($composerLock),
            'flags' => array_values($flags),
            'php' => [PHP_VERSION, PHP_BINARY, self::executable('php')],
            'composer' => self::executable('composer'),
            'env' => [getenv('COMPOSER') ?: null, getenv('COMPOSER_VENDOR_DIR') ?: null],
            'vendor' => $vendor,
        ]);
    }

    /**
     * Whether composer.json runs anything during install beyond Laravel's
     * package discovery. A custom script can write anywhere and depend on
     * anything, so an install that runs one is never skipped.
     *
     * @param  array<string, mixed>  $composerJson
     */
    public static function hasCustomInstallScripts(array $composerJson): bool
    {
        $scripts = $composerJson['scripts'] ?? [];

        if (! is_array($scripts)) {
            return true;
        }

        foreach (self::INSTALL_SCRIPT_EVENTS as $event) {
            foreach ((array) ($scripts[$event] ?? []) as $script) {
                $normalised = is_string($script) ? preg_replace('/\s+/', ' ', trim($script)) : null;

                if (! in_array($normalised, self::SAFE_INSTALL_SCRIPTS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The paths Composer's autoloader is generated from, relative to the
     * project root. A change under any of them means the classmap may be
     * stale. An empty string means "the whole project".
     *
     * @param  array<string, mixed>  $composerJson
     * @return array<int, string>
     */
    public static function autoloadPaths(array $composerJson, bool $dev): array
    {
        $sections = ['autoload'];

        if ($dev) {
            $sections[] = 'autoload-dev';
        }

        $paths = [];

        foreach ($sections as $section) {
            $autoload = $composerJson[$section] ?? [];

            if (! is_array($autoload)) {
                return [''];
            }

            foreach (['psr-4', 'psr-0'] as $type) {
                foreach ((array) ($autoload[$type] ?? []) as $dirs) {
                    foreach ((array) $dirs as $dir) {
                        $paths[] = $dir;
                    }
                }
            }

            foreach (['classmap', 'files', 'exclude-from-classmap'] as $type) {
                foreach ((array) ($autoload[$type] ?? []) as $path) {
                    $paths[] = $path;
                }
            }
        }

        $normalised = [];

        foreach ($paths as $path) {
            if (! is_string($path)) {
                return [''];
            }

            $path = trim(str_replace('\\', '/', $path));
            $path = preg_replace('#^(\./)+#', '', $path);
            $path = rtrim((string) $path, '/');

            // Globs and parent-relative paths are hard to match against a
            // change list, so treat them as covering everything.
            if ($path === '' || $path === '.' || str_contains($path, '*') || str_contains($path, '..')) {
                return [''];
            }

            $normalised[] = $path;
        }

        return array_values(array_unique($normalised));
    }

    /**
     * Whether any changed path falls under (or contains) one of the given
     * paths. An empty string in either list matches everything.
     *
     * @param  array<int, string>|null  $changes  null when the changes are unknown
     * @param  array<int, string>  $paths
     */
    public static function touches(?array $changes, array $paths): bool
    {
        if ($changes === null) {
            return true;
        }

        foreach ($changes as $change) {
            $change = rtrim($change, '/');

            if ($change === '') {
                return true;
            }

            foreach ($paths as $path) {
                if ($path === ''
                    || $change === $path
                    || str_starts_with($change, $path.'/')
                    || str_starts_with($path, $change.'/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Inputs to `pod install`: the Podfile the plugin compiler just wrote,
     * the lockfile, the sandbox manifest and the projects pod install
     * integrates with. Null when there is no complete previous install to
     * trust, or when the Podfile points at local pods whose sources can
     * change without any of these files changing.
     */
    public static function cocoaPods(string $iosPath): ?string
    {
        $iosPath = rtrim($iosPath, '/');
        $podfile = $iosPath.'/Podfile';
        $lock = $iosPath.'/Podfile.lock';
        $manifest = $iosPath.'/Pods/Manifest.lock';
        $podsProject = $iosPath.'/Pods/Pods.xcodeproj/project.pbxproj';
        $workspace = $iosPath.'/NativePHP.xcworkspace/contents.xcworkspacedata';

        foreach ([$podfile, $lock, $manifest, $podsProject, $workspace] as $required) {
            if (! is_file($required)) {
                return null;
            }
        }

        $podfileContents = (string) file_get_contents($podfile);

        if (preg_match('/:path\s*=>|:podspec\s*=>|path:\s|podspec:\s/', $podfileContents)) {
            return null;
        }

        // CocoaPods' own "sandbox is out of sync" check.
        if (self::file($lock) !== self::file($manifest)) {
            return null;
        }

        return self::of([
            'Podfile' => sha1($podfileContents),
            'Podfile.lock' => self::file($lock),
            'project' => self::file($iosPath.'/NativePHP.xcodeproj/project.pbxproj'),
            'pods' => self::file($podsProject),
            'workspace' => self::file($workspace),
        ]);
    }

    /**
     * Inputs to Swift package resolution: the package references in the
     * Xcode project and the resolved versions. Null when nothing has been
     * resolved yet.
     */
    public static function swiftPackages(string $iosPath): ?string
    {
        $iosPath = rtrim($iosPath, '/');
        $resolved = $iosPath.'/NativePHP.xcodeproj/project.xcworkspace/xcshareddata/swiftpm/Package.resolved';

        if (! is_file($resolved)) {
            return null;
        }

        return self::of([
            'project' => self::file($iosPath.'/NativePHP.xcodeproj/project.pbxproj'),
            'resolved' => self::file($resolved),
            'workspace' => self::file($iosPath.'/NativePHP.xcworkspace/xcshareddata/swiftpm/Package.resolved'),
        ]);
    }

    /**
     * Fingerprint every file under $base/$directory by path, size and
     * modification/change time. Symlinked directories are followed (that's
     * what the rsync copy does), with a guard against cycles.
     *
     * @param  callable(string $relative, string $name): bool  $skip
     */
    public static function tree(string $base, string $directory, callable $skip): ?string
    {
        $entries = [];
        $visited = [];

        $walk = function (string $relative) use (&$walk, &$entries, &$visited, $base, $skip): bool {
            $absolute = $base.'/'.$relative;
            $real = realpath($absolute);

            if ($real === false) {
                return false;
            }

            if (isset($visited[$real])) {
                // A directory we're already inside: a symlink cycle.
                return true;
            }

            $visited[$real] = true;

            $names = @scandir($absolute);

            if ($names === false) {
                return false;
            }

            sort($names, SORT_STRING);

            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $childRelative = $relative.'/'.$name;

                if ($skip($childRelative, $name)) {
                    continue;
                }

                $childAbsolute = $absolute.'/'.$name;

                if (is_dir($childAbsolute)) {
                    if (! $walk($childRelative)) {
                        return false;
                    }

                    continue;
                }

                $stat = @stat($childAbsolute);

                if ($stat === false) {
                    // A dangling symlink or a file that vanished mid-walk.
                    $entries[] = $childRelative.'|missing';

                    continue;
                }

                $entries[] = $childRelative.'|'.$stat['size'].'|'.$stat['mtime'].'|'.$stat['ctime'];
            }

            unset($visited[$real]);

            return true;
        };

        if (! is_dir($base.'/'.$directory) || ! $walk($directory)) {
            return null;
        }

        return sha1(implode("\n", $entries));
    }

    /**
     * Where an executable on PATH lives, and when it last changed.
     */
    private static function executable(string $name): ?string
    {
        $path = (new ExecutableFinder)->find($name);

        if ($path === null) {
            return null;
        }

        $real = realpath($path) ?: $path;

        return $real.'@'.(@filemtime($real) ?: 0);
    }
}
