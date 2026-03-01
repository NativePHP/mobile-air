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

        // Populate device and simulator lists
        $this->getAvailableIosDevices();

        if (! $target) {
            $target = $this->promptForWatchTarget();
        }

        if (! $target) {
            return;
        }

        $isSimulator = array_key_exists($target, $this->simulators);

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

        $this->line('Watching iOS paths: '.implode(', ', $this->getIosWatchPaths()));

        if ($isSimulator) {
            // Get the derived data path / data container path
            $derivedDataPath = Process::run("xcrun simctl get_app_container {$target} {$appId} data")
                ->output();

            $derivedDataPath = trim($derivedDataPath);

            if (empty($derivedDataPath)) {
                $this->error('Could not find app container path. Make sure the app is installed and running on the simulator.');

                return;
            }

            $this->startIosWatching($derivedDataPath, $viteHotFile);
        } else {
            $this->startIosWatchingDevice($target, $appId, $viteHotFile);
        }
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

    private function startIosWatchingDevice(string $target, string $appId, string $viteHotFile): void
    {
        // Start iproxy to forward port 9999 from the device to localhost over USB
        // This allows triggerIosReload() to reach the device's HotReloadServer
        if ($this->startIproxyForwarding($target)) {
            $this->info('USB port forwarding active - reload triggers will reach the device');
        } else {
            $this->warn('iproxy not found - files will sync but automatic reload is unavailable.');
            $this->line('Install it for automatic reload: <fg=cyan>brew install libimobiledevice</fg=cyan>');
        }

        $this->info('iOS device hot reload active - watching for changes...');
        $this->line('<fg=yellow>Press Ctrl+C to stop</fg=yellow>');

        $basePath = base_path();

        $this->startWatchman(
            $this->getIosWatchPaths(),
            $this->getIosExcludePatterns(),
            function (string $changedFile) use ($basePath, $target, $appId, $viteHotFile) {
                $this->handleIosFileChangeDevice($changedFile, $basePath, $target, $appId, $viteHotFile);
            }
        );
    }

    private function handleIosFileChangeDevice(string $changedFile, string $basePath, string $target, string $appId, string $viteHotFile): void
    {
        $relativePath = str_replace($basePath.'/', '', $changedFile);

        $this->line("<fg=blue>File changed:</fg=blue> {$relativePath}");

        if (file_exists($changedFile) && ! is_dir($changedFile)) {
            $result = Process::timeout(30)->run([
                'xcrun', 'devicectl', 'device', 'copy', 'to',
                '--device', $target,
                '--domain-type', 'appDataContainer',
                '--domain-identifier', $appId,
                '--source', $changedFile,
                '--destination', 'Documents/app/'.$relativePath,
            ]);

            if ($result->successful()) {
                $this->line("<fg=green>Synced to device:</fg=green> {$relativePath}");
            } else {
                $this->line("<fg=red>Failed to sync:</fg=red> {$relativePath}");

                if ($errorOutput = trim($result->errorOutput())) {
                    $this->line("<fg=gray>{$errorOutput}</fg=gray>");
                }
            }
        }

        if (! file_exists($viteHotFile)) {
            $this->triggerIosReload();
        }
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
        // For simulators this reaches the server directly (shared network)
        // For physical devices, iproxy forwards this to the device over USB
        $socket = @fsockopen('127.0.0.1', 9999, $errno, $errstr, 1);
        if ($socket) {
            fclose($socket);
            $this->line('<fg=green>Reload triggered</fg=green>');
        }
    }

    private function startIproxyForwarding(string $target): bool
    {
        $check = Process::run('which iproxy');

        if (! $check->successful()) {
            return false;
        }

        // Kill any existing processes on port 9999
        Process::run('lsof -ti:9999 | xargs kill 2>/dev/null');
        usleep(500000);

        // Start iproxy in background for USB port forwarding
        $escapedTarget = escapeshellarg($target);
        exec("iproxy 9999 9999 {$escapedTarget} > /dev/null 2>&1 & echo \$!", $output);
        $pid = (int) ($output[0] ?? 0);

        if ($pid > 0) {
            register_shutdown_function(function () use ($pid) {
                @exec("kill {$pid} 2>/dev/null");
            });

            return true;
        }

        return false;
    }

    private function promptForWatchTarget(): ?string
    {
        $this->info('Checking for available targets...');

        $runningSims = $this->getRunningSimulators();
        $connectedDevices = array_values($this->devices);

        if (empty($runningSims) && empty($connectedDevices)) {
            $this->error('No running simulators or connected devices found.');
            $this->line('Start a simulator or connect a device, then make sure the app is installed.');
            $this->line('If the app is not installed yet, run: php artisan native:run ios');

            return null;
        }

        $options = [];

        foreach ($connectedDevices as $device) {
            $options[$device['udid']] = sprintf(
                '%s (%s) [Device]',
                $device['name'],
                $device['version']
            );
        }

        foreach ($runningSims as $sim) {
            $options[$sim['udid']] = sprintf(
                '%s (%s) [Simulator]',
                $sim['name'],
                $sim['version']
            );
        }

        if (count($options) === 1) {
            $udid = array_key_first($options);
            $this->info("Auto-selecting: {$options[$udid]}");

            return $udid;
        }

        return select(
            label: 'Select a device or simulator to watch',
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
