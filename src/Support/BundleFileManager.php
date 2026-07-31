<?php

namespace Native\Mobile\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class BundleFileManager
{
    /**
     * All exclusion patterns, with project-level paths anchored by leading /.
     * Patterns without / match at any depth; patterns with / are project-root only.
     *
     * When a source path is given, export-ignore patterns from vendor .gitattributes
     * are included automatically.
     *
     * Optional config paths are anchored and merged (deduplicated).
     */
    public static function excludes(array $configPaths = [], ?string $sourcePath = null): array
    {
        $excludes = array_merge(
            BundleExclusions::ANY_DEPTH,
            BundleExclusions::VENDOR_PATTERNS,
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

        // Robocopy uses /XD for directories with absolute paths
        $excludeArgs = '';
        foreach ($excludes as $pattern) {
            $dir = ltrim($pattern, '/\\');
            $dir = str_replace('/', '\\', $dir);
            $excludeArgs .= " /XD \"{$source}\\{$dir}\"";
        }

        $result = Process::run("robocopy \"{$source}\" \"{$destination}\" /MIR /NFL /NDL /NJH /NJS /NP /R:0 /W:0{$excludeArgs}");

        // Robocopy exit codes < 8 are success
        if ($result->exitCode() >= 8) {
            throw new \Exception('Failed to copy app bundle (robocopy exit code '.$result->exitCode().')');
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
