<?php

namespace Native\Mobile\Concerns;

use Native\Mobile\Support\PathHelper;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

trait ManagesWatchman
{
    protected ?Process $watchmanProcess = null;

    protected ?string $watchmanRoot = null;

    /**
     * Check if Watchman is installed and available
     *
     * Probes both `watchman` and `watchman-wait`: startWatchman() executes
     * watchman-wait, which is a separate pywatchman helper that some package
     * managers (e.g. apt's watchman package) do not ship alongside watchman.
     */
    protected function isWatchmanAvailable(): bool
    {
        return $this->isBinaryAvailable('watchman') && $this->isBinaryAvailable('watchman-wait');
    }

    /**
     * Check if a binary is resolvable on the PATH
     */
    private function isBinaryAvailable(string $binary): bool
    {
        $command = PHP_OS_FAMILY === 'Windows' ? "where {$binary}" : "which {$binary}";
        $process = Process::fromShellCommandline($command);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Get installation instructions for Watchman
     */
    protected function getWatchmanInstallInstructions(): array
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => [
                'brew install watchman',
            ],
            'Linux' => [
                'sudo apt-get install watchman python3-pywatchman',
                '# watchman-wait is provided by python3-pywatchman (or: pip install pywatchman)',
                '# Or build from source: https://facebook.github.io/watchman/docs/install.html',
            ],
            'Windows' => [
                'choco install watchman',
                '# Or download from: https://facebook.github.io/watchman/docs/install.html#windows',
            ],
            default => [
                'Visit: https://facebook.github.io/watchman/docs/install.html',
            ],
        };
    }

    /**
     * Start watching paths with Watchman
     *
     * @param  array  $paths  Paths to watch
     * @param  array  $excludePatterns  Patterns to exclude (filtered in PHP, not by watchman-wait)
     * @param  callable  $onChange  Callback that receives the changed file path
     * @param  callable|null  $onTick  Optional callback for periodic tasks (called every 100ms)
     */
    protected function startWatchman(array $paths, array $excludePatterns, callable $onChange, ?callable $onTick = null, ?callable $onBatch = null): void
    {
        $this->watchmanRoot = base_path();

        // Build the watchman-wait command
        // watchman-wait outputs changed files to stdout, one per line
        $command = [
            'watchman-wait',
            '-m', '0',  // Unlimited events (run forever)
            '-t', '0',  // No timeout (run indefinitely)
            '--relative', $this->watchmanRoot, // Output paths relative to this root
            '--',
            $this->watchmanRoot,
        ];

        $this->watchmanProcess = new Process($command);
        $this->watchmanProcess->setTimeout(null);
        $this->watchmanProcess->start();

        // Register shutdown handler to clean up
        register_shutdown_function([$this, 'onWatchmanShutdown']);

        // Handle SIGINT (Ctrl+C) and SIGTERM gracefully. Async delivery is
        // required: nothing in the loop below calls pcntl_signal_dispatch(),
        // so without it the handlers are installed but never run and Ctrl+C
        // is simply swallowed.
        if (function_exists('pcntl_signal')) {
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);
            }

            pcntl_signal(SIGINT, [$this, 'onWatchmanShutdown']);
            pcntl_signal(SIGTERM, [$this, 'onWatchmanShutdown']);
        }

        // Give watchman a moment to start and check for immediate errors
        usleep(500_000); // 500ms

        if (! $this->watchmanProcess->isRunning()) {
            $errorOutput = $this->watchmanProcess->getErrorOutput();
            $this->watchmanLine('<fg=red>Watchman failed to start:</> '.$errorOutput);

            return;
        }

        // Process output in a non-blocking loop
        while ($this->watchmanProcess->isRunning()) {
            $output = $this->watchmanProcess->getIncrementalOutput();
            $errorOutput = $this->watchmanProcess->getIncrementalErrorOutput();

            if ($errorOutput) {
                $this->watchmanLine("<fg=yellow>Watchman:</fg=yellow> {$errorOutput}");
            }

            if ($output) {
                $lines = array_filter(explode("\n", trim($output)));

                $batch = [];

                foreach ($lines as $changedFile) {
                    if (empty($changedFile)) {
                        continue;
                    }

                    // Skip files matching exclude patterns
                    if ($this->shouldExcludeFile($changedFile, $excludePatterns)) {
                        continue;
                    }

                    // Convert relative path to absolute
                    $absolutePath = $this->watchmanRoot.'/'.$changedFile;

                    // Check if file matches any of our watch paths
                    if ($this->fileMatchesWatchPaths($changedFile, $paths)) {
                        $onChange($absolutePath);
                        $batch[] = $absolutePath;
                    }
                }

                // One save typically emits several lines — the file plus its
                // containing directory. Handing the whole cycle over at once
                // lets the caller sync every file and then trigger a single
                // reload, instead of firing one per file and relying on the
                // app to discard the extras.
                if ($batch !== [] && $onBatch !== null) {
                    $onBatch($batch);
                }
            }

            // Run periodic tasks if callback provided
            if ($onTick !== null) {
                $onTick();
            }

            // Short poll: this interval is pure latency between watchman
            // reporting a change and the file reaching the device.
            usleep(25_000); // 25ms
        }

        // If we get here, the process stopped unexpectedly
        $exitCode = $this->watchmanProcess->getExitCode();
        $errorOutput = $this->watchmanProcess->getErrorOutput();

        if ($exitCode !== 0) {
            $this->watchmanLine("<fg=red>Watchman exited with code {$exitCode}:</> {$errorOutput}");
        }
    }

    /**
     * Write a line without trampling the interactive watch footer, when one
     * is being drawn.
     */
    protected function watchmanLine(string $message): void
    {
        if (method_exists($this, 'watchNote')) {
            $this->watchNote($message);

            return;
        }

        $this->line($message);
    }

    /**
     * Check if a file should be excluded based on patterns
     */
    protected function shouldExcludeFile(string $relativePath, array $excludePatterns): bool
    {
        foreach ($excludePatterns as $pattern) {
            // Simple pattern matching - check if path contains the pattern
            if (str_contains($relativePath, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a file path matches any of the configured watch paths
     */
    protected function fileMatchesWatchPaths(string $relativePath, array $watchPaths): bool
    {
        foreach ($watchPaths as $watchPath) {
            // Remove base_path prefix if present
            $watchPath = PathHelper::relativeTo($watchPath, base_path());

            if (str_starts_with($relativePath, $watchPath.'/') || $relativePath === $watchPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stop Watchman process
     */
    protected function stopWatchman(): void
    {
        if ($this->watchmanProcess && $this->watchmanProcess->isRunning()) {
            $this->watchmanProcess->stop(3);
        }
    }

    /**
     * Shutdown handler to clean up resources
     */
    public function onWatchmanShutdown(): void
    {
        // Hand the terminal back first — this method exits, so anything
        // queued behind it in the shutdown sequence may never run.
        if (method_exists($this, 'stopWatchConsole')) {
            $this->stopWatchConsole();
        }

        $this->stopWatchman();

        // Clean up Vite dev server and hot file if the trait is available
        if (method_exists($this, 'stopViteDevServer')) {
            $this->stopViteDevServer();
        }

        exit(0);
    }

    /**
     * Check Watchman dependencies and show installation instructions if missing
     */
    protected function checkWatchmanDependencies(): bool
    {
        // Windows flows use ManagesPollingWatcher; watchman is never spawned
        if (PHP_OS_FAMILY === 'Windows') {
            return true;
        }

        if (! $this->isWatchmanAvailable()) {
            $missing = array_filter(
                ['watchman', 'watchman-wait'],
                fn (string $binary) => ! $this->isBinaryAvailable($binary)
            );

            error(implode(' and ', $missing).' not found. Watchman is not fully installed.');
            info('Please install Watchman to use the watch command:');

            foreach ($this->getWatchmanInstallInstructions() as $instruction) {
                info("  {$instruction}");
            }

            return false;
        }

        return true;
    }
}
