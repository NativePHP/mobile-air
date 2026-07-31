<?php

namespace Native\Mobile\Traits;

use Symfony\Component\Process\Process;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

trait ManagesWatchman
{
    protected ?Process $watchmanProcess = null;

    protected ?string $watchmanRoot = null;

    /**
     * Check if Watchman is installed and available
     */
    protected function isWatchmanAvailable(): bool
    {
        $command = PHP_OS_FAMILY === 'Windows' ? 'where watchman' : 'which watchman';
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
                'sudo apt-get install watchman',
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
    protected function startWatchman(array $paths, array $excludePatterns, callable $onChange, ?callable $onTick = null): void
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

        // Handle SIGINT (Ctrl+C) and SIGTERM gracefully
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'onWatchmanShutdown']);
            pcntl_signal(SIGTERM, [$this, 'onWatchmanShutdown']);
        }

        // Give watchman a moment to start and check for immediate errors
        usleep(500_000); // 500ms

        if (! $this->watchmanProcess->isRunning()) {
            $errorOutput = $this->watchmanProcess->getErrorOutput();
            $this->error('Watchman failed to start: '.$errorOutput);

            return;
        }

        // Process output in a non-blocking loop
        while ($this->watchmanProcess->isRunning()) {
            $output = $this->watchmanProcess->getIncrementalOutput();
            $errorOutput = $this->watchmanProcess->getIncrementalErrorOutput();

            if ($errorOutput) {
                $this->line("<fg=yellow>Watchman:</fg=yellow> {$errorOutput}");
            }

            if ($output) {
                $lines = array_filter(explode("\n", trim($output)));

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
                    }
                }
            }

            // Run periodic tasks if callback provided
            if ($onTick !== null) {
                $onTick();
            }

            usleep(100_000); // 100ms
        }

        // If we get here, the process stopped unexpectedly
        $exitCode = $this->watchmanProcess->getExitCode();
        $errorOutput = $this->watchmanProcess->getErrorOutput();

        if ($exitCode !== 0) {
            $this->error("Watchman exited with code {$exitCode}: {$errorOutput}");
        }
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
            $watchPath = str_replace(base_path().'/', '', $watchPath);

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
        if (! $this->isWatchmanAvailable()) {
            error('Watchman is not installed.');
            info('Please install Watchman to use the watch command:');

            foreach ($this->getWatchmanInstallInstructions() as $instruction) {
                info("  {$instruction}");
            }

            return false;
        }

        return true;
    }
}
