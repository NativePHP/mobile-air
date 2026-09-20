<?php

namespace Native\Mobile\Plugins;

/**
 * Decides which of a plugin's `.swift` files belong in the host app's iOS
 * target.
 *
 * The app's `NativePHP` folder is a PBXFileSystemSynchronizedRootGroup, so
 * every file copied under it becomes a member of the compile sources phase
 * automatically. There is no per-file opt-out. Anything copied that cannot
 * compile in an app target therefore breaks the build of every application
 * that installs the plugin.
 *
 * Three kinds of file can never compile there:
 *
 * - `Package.swift` — a SwiftPM manifest. It imports `PackageDescription`,
 *   which exists only inside SwiftPM's own build.
 * - Anything under `Tests/` — imports `XCTest`/`Testing` and uses
 *   `@testable import`, none of which an app target has.
 * - Anything under a dot directory — `.build`, `.swiftpm`. Build residue,
 *   frequently containing generated Swift.
 *
 * A nested directory holding a `Package.swift` is an embedded Swift package.
 * Xcode consumes those as package dependencies, not as loose source files, so
 * the whole subtree is skipped rather than copied file by file.
 */
class SwiftSourceFilter
{
    /**
     * Relative paths of every `.swift` file under $root that may be copied
     * into an app target, in depth-first order.
     *
     * @return list<string>
     */
    public static function collect(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        static::walk($root, '', $files);
        sort($files);

        return $files;
    }

    /**
     * Whether $root holds at least one copyable `.swift` file.
     *
     * Cheaper than collect() only in intent; kept separate so callers read
     * clearly. Plugins ship tens of files, not thousands.
     */
    public static function hasAny(string $root): bool
    {
        return static::collect($root) !== [];
    }

    /**
     * Is this a directory the walk must not descend into?
     */
    public static function isExcludedDirectory(string $path): bool
    {
        $name = basename($path);

        // .build, .swiftpm, .git — build residue and tooling state.
        if (str_starts_with($name, '.')) {
            return true;
        }

        // SwiftPM's test target convention.
        if ($name === 'Tests') {
            return true;
        }

        // An embedded Swift package. Xcode takes these as package
        // dependencies; copying their sources loose duplicates every symbol.
        return is_file($path.'/Package.swift');
    }

    /**
     * @param  list<string>  $files
     */
    protected static function walk(string $directory, string $prefix, array &$files): void
    {
        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            $relative = $prefix === '' ? $entry : $prefix.'/'.$entry;

            if (is_dir($path)) {
                if (static::isExcludedDirectory($path)) {
                    continue;
                }

                static::walk($path, $relative, $files);

                continue;
            }

            if (! is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'swift') {
                continue;
            }

            // A manifest at the root of the source path, where the directory
            // check above never sees it.
            if ($entry === 'Package.swift') {
                continue;
            }

            $files[] = $relative;
        }
    }
}
