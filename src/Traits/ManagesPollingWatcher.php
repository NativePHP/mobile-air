<?php

namespace Native\Mobile\Traits;

trait ManagesPollingWatcher
{
    protected bool $pollingWatcherRunning = false;

    /**
     * Start watching paths using filesystem polling (Windows fallback)
     *
     * @param  array  $paths  Paths to watch
     * @param  array  $excludePatterns  Patterns to exclude
     * @param  callable  $onChange  Callback that receives the changed file path
     * @param  callable|null  $onTick  Optional callback for periodic tasks
     */
    protected function startPollingWatcher(array $paths, array $excludePatterns, callable $onChange, ?callable $onTick = null): void
    {
        $this->pollingWatcherRunning = true;

        // Register shutdown handler
        register_shutdown_function([$this, 'onPollingWatcherShutdown']);

        // Handle SIGINT (Ctrl+C) and SIGTERM gracefully
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'onPollingWatcherShutdown']);
            pcntl_signal(SIGTERM, [$this, 'onPollingWatcherShutdown']);
        }

        // Build initial state of file modification times
        $fileStates = $this->scanFilesWithTimes($paths, $excludePatterns);

        while ($this->pollingWatcherRunning) {
            $currentStates = $this->scanFilesWithTimes($paths, $excludePatterns);

            // Find new or modified files
            foreach ($currentStates as $file => $mtime) {
                if (! isset($fileStates[$file]) || $fileStates[$file] < $mtime) {
                    $onChange($file);
                }
            }

            $fileStates = $currentStates;

            // Run periodic tasks if callback provided
            if ($onTick !== null) {
                $onTick();
            }

            // Poll interval - 500ms is a good balance between responsiveness and CPU usage
            usleep(500_000);
        }
    }

    /**
     * Scan directories and return file paths with their modification times
     */
    protected function scanFilesWithTimes(array $paths, array $excludePatterns): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $path,
                        \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
                    ),
                    \RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $file) {
                    if ($file->isDir()) {
                        continue;
                    }

                    $filePath = $file->getPathname();

                    // Normalize path separators for consistent comparison
                    $normalizedPath = str_replace('\\', '/', $filePath);

                    if ($this->shouldExcludeFromPolling($normalizedPath, $excludePatterns)) {
                        continue;
                    }

                    try {
                        $files[$filePath] = $file->getMTime();
                    } catch (\Exception $e) {
                        // File may have been deleted between iteration and getMTime
                        continue;
                    }
                }
            } catch (\Exception $e) {
                // Directory may have permission issues or been deleted
                continue;
            }
        }

        return $files;
    }

    /**
     * Check if a file should be excluded based on patterns
     */
    protected function shouldExcludeFromPolling(string $filePath, array $excludePatterns): bool
    {
        foreach ($excludePatterns as $pattern) {
            if (str_contains($filePath, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stop the polling watcher
     */
    protected function stopPollingWatcher(): void
    {
        $this->pollingWatcherRunning = false;
    }

    /**
     * Shutdown handler for polling watcher
     */
    public function onPollingWatcherShutdown(): void
    {
        $this->stopPollingWatcher();

        // Clean up Vite dev server if the trait is available
        if (method_exists($this, 'stopViteDevServer')) {
            $this->stopViteDevServer();
        }

        exit(0);
    }
}
