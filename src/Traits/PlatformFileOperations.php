<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Facades\File;

trait PlatformFileOperations
{
    /**
     * Platform-optimized full-tree copy. Copies with exclusions moved
     * to BundleFileManager::copy(), which owns the exclusion sets
     * for both rsync and robocopy, so this stays a plain copy.
     */
    protected function platformOptimizedCopy(string $source, string $destination): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Normalize separators: xcopy path matching requires backslashes,
            // but callers build paths with forward slashes (base_path('x'))
            $source = str_replace('/', '\\', $source);
            $destination = str_replace('/', '\\', $destination);

            $cmd = "xcopy \"{$source}\\*\" \"{$destination}\\\" /E /I /Y /Q";
        } else {
            $cmd = "cp -a \"{$source}/.\" \"{$destination}/\"";
        }

        exec($cmd, $output, $result);

        if ($result !== 0) {
            throw new \RuntimeException("File copy failed (exit code {$result}): {$cmd}");
        }
    }

    /**
     * Platform-optimized directory removal
     */
    protected function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            // Try rmdir first, fallback to File::deleteDirectory
            $result = @exec("rmdir /s /q \"{$directory}\" 2>&1", $output, $exitCode);
            if ($exitCode !== 0) {
                File::deleteDirectory($directory);
            }
            // Windows sometimes needs a moment
            usleep(100000); // 100ms
        } else {
            File::deleteDirectory($directory);
        }
    }

    /**
     * Normalize line endings to LF
     */
    protected function normalizeLineEndings(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    /**
     * Replace file contents with proper line ending handling
     */
    protected function replaceFileContents(string $path, string $search, string $replace): bool
    {
        if (! File::exists($path)) {
            return false;
        }

        $contents = File::get($path);
        $newContents = str_replace($search, $replace, $contents);

        if ($contents !== $newContents) {
            File::put($path, $this->normalizeLineEndings($newContents));

            return true;
        }

        return false;
    }

    /**
     * Replace file contents using regex with proper line ending handling
     */
    protected function replaceFileContentsRegex(string $path, string $pattern, string $replacement): bool
    {
        if (! File::exists($path)) {
            return false;
        }

        $contents = File::get($path);
        $newContents = preg_replace($pattern, $replacement, $contents);

        if ($contents !== $newContents) {
            File::put($path, $this->normalizeLineEndings($newContents));

            return true;
        }

        return false;
    }

    /**
     * Check if running inside Windows Subsystem for Linux (WSL)
     */
    protected function isRunningInWSL(): bool
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        $version = @file_get_contents('/proc/version') ?: '';

        return str_contains($version, 'Microsoft') || str_contains($version, 'WSL');
    }
}
