<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

trait WatchesIos
{
    use ManagesWatchman;

    private array $iosWatchPaths = ['app', 'resources', 'routes', 'config'];

    private array $iosExcludePatterns = [
        '.git',
        'storage/logs',
        'storage/framework',
        'vendor',
        'node_modules',
        '.swp',
        '.tmp',
        '.log',
    ];

    protected function startIosHotReload(?string $target = null): void
    {
        $this->line('');
        $this->info('Starting iOS hot reload...');

        if (! $this->checkWatchmanDependencies()) {
            return;
        }

        $appId = config('nativephp.app_id');

        if (! $target) {
            $target = $this->promptForRunningSimulator();
        }

        if (! $target) {
            return;
        }

        // Populate $this->simulators and $this->devices so we can classify the target
        $this->getAvailableIosDevices();

        $isDevice = array_key_exists($target, $this->devices);

        // Start Vite dev server if the nativephpMobile plugin is installed
        $this->startViteDevServer('ios');

        // Check if Vite hot reloading is active
        $viteHotFile = $this->getHotFilePath('ios');
        $viteRunning = file_exists($viteHotFile);

        if ($viteRunning) {
            $this->info('Vite hot reloading detected - skipping full page reloads');
        } else {
            $this->info('No Vite hot reloading detected - will trigger full page reloads');
        }

        if ($isDevice) {
            $this->line('Watching iOS paths: '.implode(', ', $this->getIosWatchPaths()));
            $this->startIosDeviceWatching($target, $appId, $viteHotFile);

            return;
        }

        // Get the derived data path / data container path
        $derivedDataPath = Process::run("xcrun simctl get_app_container {$target} {$appId} data")
            ->output();

        $derivedDataPath = trim($derivedDataPath);

        if (empty($derivedDataPath)) {
            $this->error('Could not find app container path. Make sure the app is installed and running.');

            return;
        }

        $this->line('Watching iOS paths: '.implode(', ', $this->getIosWatchPaths()));
        $this->startIosWatching($derivedDataPath, $viteHotFile);
    }

    private function startIosWatching(string $derivedDataPath, string $viteHotFile): void
    {
        $this->info('iOS hot reload active - watching for changes...');
        $this->line('<fg=yellow>Press Ctrl+C to stop</fg=yellow>');

        $basePath = base_path();
        $destinationPath = $derivedDataPath.'/Documents/app/';

        $this->startWatchman(
            $this->getIosWatchPaths(),
            $this->getIosExcludePatterns(),
            function (string $changedFile) use ($basePath, $destinationPath, $viteHotFile) {
                $this->handleIosFileChange($changedFile, $basePath, $destinationPath, $viteHotFile);
            }
        );
    }

    private function handleIosFileChange(string $changedFile, string $basePath, string $destinationPath, string $viteHotFile): void
    {
        // Get relative path from source
        $relativePath = str_replace($basePath.'/', '', $changedFile);
        $destinationFile = $destinationPath.$relativePath;

        $this->line("<fg=blue>File changed:</fg=blue> {$relativePath}");

        // Create destination directory if needed
        $destinationDir = dirname($destinationFile);
        if (! is_dir($destinationDir)) {
            mkdir($destinationDir, 0755, true);
        }

        // Copy the specific file
        if (file_exists($changedFile) && ! is_dir($changedFile)) {
            copy($changedFile, $destinationFile);
            $this->line("<fg=green>Synced to iOS:</fg=green> {$relativePath}");
        }

        // Trigger reload only if Vite hot reloading is not active
        if (! file_exists($viteHotFile)) {
            $this->triggerIosReload();
        }
    }

    private function triggerIosReload(): void
    {
        // Connect to the hot reload server to trigger a reload
        $socket = @fsockopen('127.0.0.1', 9999, $errno, $errstr, 1);
        if ($socket) {
            fclose($socket);
            $this->line('<fg=green>Reload triggered</fg=green>');
        }
    }

    private function startIosDeviceWatching(string $udid, string $appId, string $viteHotFile): void
    {
        $this->info('iOS hot reload active (real device) - watching for changes...');
        $this->line('<fg=yellow>Press Ctrl+C to stop</fg=yellow>');

        $basePath = base_path();

        $this->startWatchman(
            $this->getIosWatchPaths(),
            $this->getIosExcludePatterns(),
            function (string $changedFile) use ($udid, $appId, $basePath, $viteHotFile) {
                $this->handleIosDeviceFileChange($changedFile, $udid, $appId, $basePath, $viteHotFile);
            }
        );
    }

    private function handleIosDeviceFileChange(string $changedFile, string $udid, string $appId, string $basePath, string $viteHotFile): void
    {
        $relativePath = str_replace($basePath.'/', '', $changedFile);

        $this->line("<fg=blue>File changed:</fg=blue> {$relativePath}");

        if (! file_exists($changedFile) || is_dir($changedFile)) {
            return;
        }

        $destination = 'Documents/app/'.$relativePath;

        $result = Process::run([
            'xcrun', 'devicectl', 'device', 'copy', 'to',
            '--device', $udid,
            '--source', $changedFile,
            '--destination', $destination,
            '--domain-type', 'appDataContainer',
            '--domain-identifier', $appId,
            '--quiet',
        ]);

        if ($result->successful()) {
            $this->line("<fg=green>Synced to device:</fg=green> {$relativePath}");
        } else {
            $this->line("<fg=red>Sync failed:</fg=red> {$relativePath}");
            $errorMessage = trim($result->errorOutput() ?: $result->output());
            if ($errorMessage !== '') {
                $this->line('<fg=gray>'.$errorMessage.'</fg=gray>');
            }
        }

        // Vite HMR handles JS/CSS reloads over the LAN for the device's WebView.
        // The loopback-based triggerIosReload() is simulator-only, so we skip it here.
        // PHP/blade changes are synced to the device container but won't live-reload
        // until an app-side listener exists; for now the next navigation picks them up.
    }

    private function promptForRunningSimulator(): ?string
    {
        $this->info('Checking for running simulators...');
        $runningSims = $this->getRunningSimulators();

        if (empty($runningSims)) {
            $this->error('No running iOS simulators found.');
            $this->line('First, start a simulator and open the "'.config('app.name').'" app on it.');
            $this->line('If the app is not installed on that simulator yet, run: php artisan native:run ios');

            return null;
        }

        // If there's only one running simulator, automatically select it
        if (count($runningSims) === 1) {
            $sim = $runningSims[0];
            $this->info("Auto-selecting simulator: {$sim['name']} ({$sim['version']})");

            return $sim['udid'];
        }

        $options = [];
        foreach ($runningSims as $sim) {
            $label = sprintf(
                '%s (%s) [%s]',
                $sim['name'],
                $sim['version'],
                $sim['udid']
            );
            $options[$sim['udid']] = $label;
        }

        return select(
            label: 'Select a running simulator to watch',
            options: $options
        );
    }

    private function getRunningSimulators(): array
    {
        // Get all available simulators first
        $this->getAvailableIosDevices();

        // Filter to only running simulators
        $runningSimulators = [];

        foreach ($this->simulators as $udid => $simulator) {
            // Check if simulator is booted
            $result = Process::run(['xcrun', 'simctl', 'list', 'devices', '--json']);

            if ($result->successful()) {
                $devices = json_decode($result->output(), true);

                foreach ($devices['devices'] as $runtime => $runtimeDevices) {
                    foreach ($runtimeDevices as $device) {
                        if ($device['udid'] === $udid && $device['state'] === 'Booted') {
                            $runningSimulators[] = $simulator;
                            break 2;
                        }
                    }
                }
            }
        }

        return $runningSimulators;
    }

    private function getIosWatchPaths(): array
    {
        $paths = config('nativephp.hot_reload.watch_paths', $this->iosWatchPaths);

        // Convert relative paths to absolute paths
        return array_map(function ($path) {
            if (! str_starts_with($path, '/')) {
                return base_path($path);
            }

            return $path;
        }, $paths);
    }

    private function getIosExcludePatterns(): array
    {
        return config('nativephp.hot_reload.exclude_patterns', $this->iosExcludePatterns);
    }

    private function killHotReloadServers(): void
    {
        // Find processes listening on port 9999
        $result = Process::run(['lsof', '-ti:9999']);

        if ($result->successful()) {
            $pids = array_filter(explode("\n", trim($result->output())));

            foreach ($pids as $pid) {
                if (is_numeric($pid)) {
                    // Try graceful shutdown first
                    Process::run(['kill', '-15', $pid]);
                    sleep(3); // Wait 3 seconds for graceful shutdown

                    // Check if process is still running, force kill if needed
                    $stillRunning = Process::run(['kill', '-0', $pid])->successful();
                    if ($stillRunning) {
                        Process::run(['kill', '-9', $pid]);
                    }
                }
            }
        }
    }

    private function quitOtherRunningApps(string $target, string $currentAppId): void
    {
        // Get list of running apps on the simulator
        $result = Process::run(['xcrun', 'simctl', 'spawn', $target, 'launchctl', 'list']);

        if (! $result->successful()) {
            return;
        }

        $lines = explode("\n", $result->output());

        foreach ($lines as $line) {
            // Look for app bundle identifiers that are not our current app
            if (preg_match('/\s+(\w+\.\w+\.\w+)\s*$/', $line, $matches)) {
                $bundleId = $matches[1];

                // Skip our current app and system apps
                if ($bundleId === $currentAppId || strpos($bundleId, 'com.apple.') === 0) {
                    continue;
                }

                // Quit the app
                Process::run(['xcrun', 'simctl', 'terminate', $target, $bundleId]);
            }
        }
    }
}
