<?php

namespace Native\Mobile\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class BundleFileManager
{
    /**
     * All exclusion patterns. Project-level paths are anchored by a leading slash,
     * vendor patterns are scoped under vendor/** so they never match app files,
     * and bare patterns match at any depth. When a source path is given the
     * export-ignore patterns from vendor .gitattributes are also included.
     * Optional config paths are anchored and merged in (deduplicated).
     */
    public static function excludes(array $configPaths = [], ?string $sourcePath = null): array
    {
        $excludes = array_merge(
            BundleExclusions::ANY_DEPTH,
            array_map(fn ($p) => 'vendor/**/'.$p, BundleExclusions::VENDOR_PATTERNS),
            BundleExclusions::VENDOR_PATHS,
            array_map(fn ($p) => '/'.$p, BundleExclusions::PROJECT),
            array_map(fn ($p) => '/'.$p, BundleExclusions::COPY_ONLY),
        );

        /*
         * Respect gitattributes with local packages
         */
        if ($sourcePath !== null) {
            foreach (self::vendorExportIgnorePatterns($sourcePath) as $prefix => $patterns) {
                foreach ($patterns as $pattern) {
                    $excludes[] = $prefix.ltrim($pattern, '/');
                }
            }
        }

        if (! empty($configPaths)) {
            $anchored = array_map(
                fn ($path) => str_starts_with($path, '/') ? $path : '/'.$path,
                $configPaths
            );

            $excludes = array_merge($excludes, $anchored);
        }

        return array_values(array_unique($excludes));
    }

    /**
     * Copy a source directory to a destination, excluding bundled patterns.
     * Uses rsync on macOS/Linux, robocopy on Windows.
     */
    public static function copy(string $source, string $destination, array $configPaths = []): void
    {
        $source = rtrim($source, '/');
        $destination = rtrim($destination, '/');

        File::ensureDirectoryExists($destination);
        File::cleanDirectory($destination);

        if (PHP_OS_FAMILY === 'Windows') {
            self::copyWithRobocopy($source, $destination, $configPaths);
        } else {
            self::copyWithRsync($source, $destination, $configPaths);
        }

        // Put back what the exclusions removed but Laravel still needs to
        // boot, since composer install runs package:discover in here.
        foreach (BundleExclusions::REQUIRED_DIRECTORIES as $directory) {
            File::ensureDirectoryExists($destination.'/'.$directory);
        }
    }

    /**
     * Bring an existing staged copy up to date with the source, copying only
     * what changed and deleting what's gone. The destination ends up the same
     * as a fresh copy() would make it, except for excluded paths, which rsync
     * leaves alone (that's how a reused vendor/ survives: pass '/vendor' in
     * $extraExcludes).
     *
     * Returns the paths rsync created, updated or deleted, relative to the
     * destination. An empty string in the list means rsync printed something
     * we couldn't parse, which callers treat as "anything may have changed".
     *
     * Unix only. Callers fall back to copy() on Windows.
     *
     * @param  array<int, string>  $extraExcludes
     * @return array<int, string>
     */
    public static function sync(string $source, string $destination, array $configPaths = [], array $extraExcludes = []): array
    {
        $source = rtrim($source, '/');
        $destination = rtrim($destination, '/');

        File::ensureDirectoryExists($destination);

        // --delete on the wrong directory would be a disaster.
        if ($destination === '' || realpath($destination) === realpath($source)) {
            throw new \InvalidArgumentException("Refusing to sync [{$source}] onto itself.");
        }

        $excludes = array_merge(self::excludes($configPaths, $source), $extraExcludes);
        $excludeFlags = implode(' ', array_map(fn ($d) => "--exclude='".str_replace("'", "'\\''", $d)."'", $excludes));

        $result = Process::run("rsync -a --copy-links --delete --itemize-changes {$excludeFlags} \"{$source}/\" \"{$destination}/\"");

        if (! $result->successful()) {
            throw new \Exception('Failed to copy app bundle: '.$result->errorOutput());
        }

        foreach (BundleExclusions::REQUIRED_DIRECTORIES as $directory) {
            File::ensureDirectoryExists($destination.'/'.$directory);
        }

        return self::parseItemizedChanges($result->output());
    }

    /**
     * Turn `rsync --itemize-changes` output into a list of changed paths.
     * Handles both GNU rsync (11-character codes) and macOS openrsync
     * (9-character codes). Directory attribute-only lines are ignored: a
     * directory's mtime only moves when an entry inside it was added or
     * removed, and that entry has its own line.
     *
     * @return array<int, string>
     */
    public static function parseItemizedChanges(string $output): array
    {
        $changes = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (str_starts_with($line, '*deleting')) {
                $changes[] = ltrim(substr($line, strlen('*deleting')));

                continue;
            }

            if (preg_match('/^([<>ch.*][fdLDS][^ ]{7,9}) (.+)$/', $line, $matches)) {
                if (str_starts_with($matches[1], '.d')) {
                    continue;
                }

                $changes[] = $matches[2];

                continue;
            }

            // Something we don't recognise: assume everything changed.
            $changes[] = '';
        }

        return $changes;
    }

    private static function copyWithRsync(string $source, string $destination, array $configPaths): void
    {
        $excludes = self::excludes($configPaths, $source);
        $excludeFlags = implode(' ', array_map(fn ($d) => "--exclude='".str_replace("'", "'\\''", $d)."'", $excludes));

        $result = Process::run("rsync -a --copy-links {$excludeFlags} \"{$source}/\" \"{$destination}/\"");

        if (! $result->successful()) {
            throw new \Exception('Failed to copy app bundle: '.$result->errorOutput());
        }
    }

    private static function copyWithRobocopy(string $source, string $destination, array $configPaths): void
    {
        $excludes = self::excludes($configPaths, $source);
        $source = rtrim($source, '/');

        // Robocopy path matching requires backslashes, but callers build
        // their paths with forward slashes (base_path('x')). /XD only
        // excludes directories, so files are registered via /XF.
        $excludeArgs = '';
        $append = function (string $path) use (&$excludeArgs): void {
            $flag = is_file($path) ? '/XF' : '/XD';
            $excludeArgs .= ' '.$flag.' "'.str_replace('/', '\\', $path).'"';
        };

        foreach ($excludes as $pattern) {
            // Bare names match at any depth, mirroring rsync's unanchored
            // semantics. A name can be a file or a directory and unused
            // robocopy flags are harmless, so register it as both.
            if (! str_contains($pattern, '/')) {
                $excludeArgs .= " /XD \"{$pattern}\" /XF \"{$pattern}\"";

                continue;
            }

            // Multi-level wildcards (vendor/**/*.md) cannot be expressed
            // with /XD or /XF, so they are pruned from the destination
            // after robocopy finishes instead.
            if (str_contains($pattern, '**')) {
                continue;
            }

            // Single-level wildcards (vendor/*/*/vendor) are expanded here
            // because robocopy cannot. This also keeps robocopy from
            // cycling through composer path-repo junctions.
            if (str_contains($pattern, '*')) {
                foreach (glob($source.'/'.ltrim($pattern, '/')) ?: [] as $match) {
                    $append($match);
                }

                continue;
            }

            $append($source.'/'.ltrim($pattern, '/'));
        }

        $sourceWin = str_replace('/', '\\', $source);
        $destinationWin = str_replace('/', '\\', $destination);

        $result = Process::run("robocopy \"{$sourceWin}\" \"{$destinationWin}\" /MIR /NFL /NDL /NJH /NJS /NP /R:0 /W:0{$excludeArgs}");

        // Robocopy exit codes < 8 are success
        if ($result->exitCode() >= 8) {
            throw new \Exception('Failed to copy app bundle (robocopy exit code '.$result->exitCode().')');
        }

        self::pruneVendorPatterns($destination);
    }

    /**
     * Delete files matching VENDOR_PATTERNS from a copied vendor tree.
     * Robocopy cannot express the multi-level vendor wildcards rsync
     * handles natively, so the Windows backend prunes them here to
     * keep the copy contract identical on every platform.
     */
    private static function pruneVendorPatterns(string $destination): void
    {
        $vendorPath = $destination.'/vendor';

        if (! is_dir($vendorPath)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($vendorPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            // rsync's vendor/**/<pattern> form needs at least one directory
            // level below vendor/, so files sitting directly in vendor/
            // (only autoload.php in practice) are never matched.
            if ($items->getDepth() < 1) {
                continue;
            }

            foreach (BundleExclusions::VENDOR_PATTERNS as $pattern) {
                if (fnmatch($pattern, $item->getFilename())) {
                    $item->isDir() ? File::deleteDirectory($item->getPathname()) : unlink($item->getPathname());

                    break;
                }
            }
        }
    }

    public static function cleanupDirectories(): array
    {
        return array_merge(
            BundleExclusions::ANY_DEPTH,
            BundleExclusions::PROJECT,
            ['vendor/bin'],
            BundleExclusions::VENDOR_PATHS,
        );
    }

    public static function cleanupFiles(): array
    {
        return array_merge(
            BundleExclusions::ANY_DEPTH,
            BundleExclusions::PROJECT,
            BundleExclusions::CLEANUP_ONLY,
            BundleExclusions::VENDOR_PATHS,
        );
    }

    /**
     * Load export-ignore patterns from .gitattributes in vendor packages.
     *
     * @return array<string, string[]> Keyed by vendor prefix (e.g. 'vendor/acme/widget/')
     */
    public static function vendorExportIgnorePatterns(string $sourcePath): array
    {
        $patterns = [];
        $vendorPath = rtrim($sourcePath, '/').'/vendor/';

        if (! is_dir($vendorPath)) {
            return $patterns;
        }

        foreach (new \DirectoryIterator($vendorPath) as $namespace) {
            if ($namespace->isDot() || ! $namespace->isDir()) {
                continue;
            }

            foreach (new \DirectoryIterator($namespace->getPathname()) as $package) {
                if ($package->isDot() || ! $package->isDir()) {
                    continue;
                }

                $gitattributes = $package->getPathname().'/.gitattributes';
                if (! file_exists($gitattributes)) {
                    continue;
                }

                $ignores = [];
                foreach (file($gitattributes, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || ! str_contains($line, 'export-ignore')) {
                        continue;
                    }

                    $path = trim(preg_split('/\s+/', $line, 2)[0] ?? '');
                    if ($path !== '') {
                        $ignores[] = ltrim($path, '/');
                    }
                }

                if (! empty($ignores)) {
                    $prefix = 'vendor/'.$namespace->getFilename().'/'.$package->getFilename().'/';
                    $patterns[$prefix] = $ignores;
                }
            }
        }

        return $patterns;
    }

    /**
     * Delete directories and files from an app path using the cleanup lists + config paths.
     */
    public static function removeUnnecessaryFiles(string $appPath, array $configPaths = []): void
    {
        $appPath = rtrim($appPath, '/').'/';

        foreach (self::cleanupDirectories() as $dir) {
            if (str_contains($dir, '*')) {
                foreach (glob($appPath.$dir, GLOB_ONLYDIR) as $match) {
                    File::deleteDirectory($match);
                }
            } elseif (is_dir($appPath.$dir)) {
                File::deleteDirectory($appPath.$dir);
            }
        }

        foreach (self::cleanupFiles() as $pattern) {
            foreach (glob($appPath.$pattern) as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        foreach ($configPaths as $path) {
            $fullPath = $appPath.ltrim($path, '/');
            if (is_dir($fullPath)) {
                File::deleteDirectory($fullPath);
            } elseif (is_file($fullPath)) {
                unlink($fullPath);
            }
        }
    }
}
