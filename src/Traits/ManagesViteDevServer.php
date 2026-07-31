<?php

namespace Native\Mobile\Traits;

use Symfony\Component\Process\Process;

trait ManagesViteDevServer
{
    protected ?Process $viteProcess = null;

    protected ?string $currentPlatform = null;

    /**
     * Get the platform-specific hot file path
     */
    protected function getHotFilePath(?string $platform = null): string
    {
        $platform = $platform ?? $this->currentPlatform;

        return match ($platform) {
            'ios' => base_path('public/ios-hot'),
            'android' => base_path('public/android-hot'),
            default => base_path('public/hot'),
        };
    }

    /**
     * Check if the nativephpMobile plugin is installed in vite.config.js
     */
    protected function hasNativephpMobileVitePlugin(): bool
    {
        $viteConfigPath = base_path('vite.config.js');

        if (! file_exists($viteConfigPath)) {
            // Also check for .ts extension
            $viteConfigPath = base_path('vite.config.ts');

            if (! file_exists($viteConfigPath)) {
                return false;
            }
        }

        $contents = file_get_contents($viteConfigPath);

        // Check for nativephpMobile plugin import or usage
        return str_contains($contents, 'nativephpMobile')
            || str_contains($contents, 'nativephp-mobile');
    }

    /**
     * Start the Vite dev server with the appropriate platform mode
     */
    protected function startViteDevServer(string $platform): bool
    {
        if (! in_array($platform, ['ios', 'android'])) {
            $this->error("Invalid platform: {$platform}");

            return false;
        }

        $this->currentPlatform = $platform;

        if (! $this->hasNativephpMobileVitePlugin()) {
            $this->line('');
            $this->warn('   nativephpMobile Vite plugin not detected in vite.config.js');
            $this->line('   <fg=gray>Add the plugin to enable automatic Vite dev server startup</fg=gray>');
            $this->line('');

            return false;
        }

        // Check if Vite is already running (using platform-specific hot file)
        $hotFilePath = $this->getHotFilePath($platform);
        if (file_exists($hotFilePath)) {
            $this->line('');
            $this->info('   Vite dev server already running');

            return true;
        }

        $this->line('');
        $this->info("   Starting Vite dev server for {$platform}...");

        // Determine the package manager command
        $command = $this->getPackageManagerCommand();

        // Start Vite dev server in the background
        $this->viteProcess = new Process(
            [...$command, 'run', 'dev', '--', "--mode={$platform}"],
            base_path()
        );
        $this->viteProcess->setTimeout(null);
        $this->viteProcess->start(function ($type, $output) {
            // Forward Vite output to console with prefix
            $lines = array_filter(explode("\n", $output));
            foreach ($lines as $line) {
                if (! empty(trim($line))) {
                    $this->line("   <fg=magenta>[vite]</fg=magenta> {$line}");
                }
            }
        });

        // Wait a moment for Vite to start and create the hot file
        $maxWait = 10; // seconds
        $waited = 0;

        while ($waited < $maxWait) {
            if (file_exists($hotFilePath)) {
                $this->info('   Vite dev server started successfully');

                return true;
            }

            if (! $this->viteProcess->isRunning()) {
                $this->error('   Vite dev server failed to start');
                $this->line('   <fg=red>'.$this->viteProcess->getErrorOutput().'</fg=red>');

                return false;
            }

            usleep(500_000); // 500ms
            $waited += 0.5;
        }

        $this->warn('   Vite dev server taking longer than expected to start');
        $this->line('   <fg=gray>Continuing anyway - hot reloading may not work immediately</fg=gray>');

        return true;
    }

    /**
     * Stop the Vite dev server if we started it
     */
    protected function stopViteDevServer(): void
    {
        if ($this->viteProcess && $this->viteProcess->isRunning()) {
            $this->line('');
            $this->info('   Stopping Vite dev server...');
            $this->viteProcess->stop(3);
        }

        $this->cleanupHotFile();
    }

    /**
     * Clean up the platform-specific hot file
     */
    protected function cleanupHotFile(): void
    {
        $hotFilePath = $this->getHotFilePath();

        if (file_exists($hotFilePath)) {
            @unlink($hotFilePath);
        }
    }

    /**
     * Get the appropriate package manager command (npm, yarn, pnpm, bun)
     */
    protected function getPackageManagerCommand(): array
    {
        // Check for lock files to determine package manager
        if (file_exists(base_path('bun.lockb')) || file_exists(base_path('bun.lock'))) {
            return ['bun'];
        }

        if (file_exists(base_path('pnpm-lock.yaml'))) {
            return ['pnpm'];
        }

        if (file_exists(base_path('yarn.lock'))) {
            return ['yarn'];
        }

        // Default to npm
        return ['npm'];
    }

    /**
     * Check and forward Vite process output periodically
     */
    protected function checkViteProcessOutput(): void
    {
        if ($this->viteProcess && $this->viteProcess->isRunning()) {
            $output = $this->viteProcess->getIncrementalOutput();
            $errorOutput = $this->viteProcess->getIncrementalErrorOutput();

            if ($output) {
                $lines = array_filter(explode("\n", $output));
                foreach ($lines as $line) {
                    if (! empty(trim($line))) {
                        $this->line("   <fg=magenta>[vite]</fg=magenta> {$line}");
                    }
                }
            }

            if ($errorOutput) {
                $lines = array_filter(explode("\n", $errorOutput));
                foreach ($lines as $line) {
                    if (! empty(trim($line))) {
                        $this->line("   <fg=magenta>[vite]</fg=magenta> <fg=yellow>{$line}</fg=yellow>");
                    }
                }
            }
        }
    }
}
