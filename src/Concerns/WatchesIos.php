<?php

namespace Native\Mobile\Concerns;

use Illuminate\Support\Facades\Process;
use Native\Mobile\Edge\NativeRouter;

use function Laravel\Prompts\select;

trait WatchesIos
{
    use InteractsWithWatchTerminal, ManagesWatchman;

    /**
     * UDID of the simulator or device being watched.
     */
    private ?string $iosTarget = null;

    /**
     * Data container of the app on a simulator — the host can write into it
     * directly. Null when watching a physical device, where the same writes
     * have to go through `xcrun devicectl`.
     */
    private ?string $iosAppContainer = null;

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
        // Everything here depends on macOS-only tooling (xcrun simctl/devicectl,
        // iproxy, lsof), so bail out early with a clear message on Windows/Linux
        // instead of failing cryptically once device discovery runs xcrun.
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->error('iOS hot reload requires macOS with Xcode command line tools. Use `php artisan native:watch android` on this platform.');

            return;
        }

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

        $this->iosTarget = $target;
        $isSimulator = array_key_exists($target, $this->simulators);

        // Start Vite dev server if the nativephpMobile plugin is installed
        if ($this->shouldRunVite()) {
            $this->startViteDevServer('ios');
        }

        // Check if Vite hot reloading is active
        $viteHotFile = $this->getHotFilePath('ios');
        $viteRunning = file_exists($viteHotFile);

        if ($viteRunning) {
            $this->info('Vite hot reloading detected - skipping full page reloads');
        } elseif ($this->shouldRunVite()) {
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

            $this->iosAppContainer = $derivedDataPath;

            // Seed the hot file into the container. The app resolves Vite through
            // Vite::useHotFile(public_path('ios-hot')), and by the time watching
            // starts the file already exists, so no change event will ever
            // carry it across.
            if ($viteRunning) {
                $this->info('Vite dev server detected - syncing hot file to simulator');
                $hotFileDestination = $derivedDataPath.'/Documents/app/public/ios-hot';
                $hotFileDirectory = dirname($hotFileDestination);

                if (! is_dir($hotFileDirectory) && ! @mkdir($hotFileDirectory, 0755, true) && ! is_dir($hotFileDirectory)) {
                    $this->warn("Could not create {$hotFileDirectory} - the app will not find the Vite dev server.");
                } elseif (! @copy($viteHotFile, $hotFileDestination)) {
                    $this->warn("Could not copy the hot file to {$hotFileDestination} - the app will not find the Vite dev server.");
                } else {
                    $this->triggerIosReload();
                }
            }

            $this->startIosWatching($derivedDataPath, $viteHotFile);
        } else {
            $this->startIosWatchingDevice($target, $appId);
        }
    }

    private function startIosWatching(string $derivedDataPath, string $viteHotFile): void
    {
        $basePath = base_path();
        $destinationPath = $derivedDataPath.'/Documents/app/';

        $this->startWatchConsole('ios', $this->iosDeviceLabel());

        try {
            $this->startWatchman(
                $this->getIosWatchPaths(),
                $this->getIosExcludePatterns(),
                function (string $changedFile) use ($basePath, $destinationPath, $viteHotFile) {
                    $this->syncIosFile($changedFile, $basePath, $destinationPath, $viteHotFile);
                },
                fn () => $this->pumpWatchTerminal(),
                function (array $changedFiles) use ($basePath, $viteHotFile) {
                    $this->triggerIosReloadForBatch($changedFiles, $basePath, $viteHotFile);
                },
            );
        } finally {
            // Reached when the watcher stops on its own (watchman died); the
            // quit key and signal paths tear the console down themselves.
            $this->stopWatchConsole();
        }
    }

    private function startIosWatchingDevice(string $target, string $appId): void
    {
        // Start iproxy to forward port 9999 from the device to localhost over USB
        // This allows triggerIosReload() to reach the device's HotReloadServer
        if ($this->startIproxyForwarding($target)) {
            $this->info('USB port forwarding active - reload triggers will reach the device');
        } else {
            $this->warn('iproxy not found - files will sync but automatic reload is unavailable.');
            $this->line('Install it for automatic reload: <fg=cyan>brew install libimobiledevice</fg=cyan>');
        }

        $basePath = base_path();

        $this->startWatchConsole('ios', $this->iosDeviceLabel());

        try {
            $this->startWatchman(
                $this->getIosWatchPaths(),
                $this->getIosExcludePatterns(),
                function (string $changedFile) use ($basePath, $target, $appId) {
                    $this->handleIosFileChangeDevice($changedFile, $basePath, $target, $appId);
                },
                fn () => $this->pumpWatchTerminal(),
                fn () => $this->triggerIosReload(),
            );
        } finally {
            // Reached when the watcher stops on its own (watchman died); the
            // quit key and signal paths tear the console down themselves.
            $this->stopWatchConsole();
        }
    }

    private function handleIosFileChangeDevice(string $changedFile, string $basePath, string $target, string $appId): void
    {
        $relativePath = str_replace($basePath.'/', '', $changedFile);

        if (file_exists($changedFile) && ! is_dir($changedFile)) {
            if (! $this->copyToIosDevice($changedFile, 'Documents/app/'.$relativePath, $target, $appId)) {
                return;
            }

            $this->watchSynced($relativePath);
        }

        // Physical devices can't reach the Vite dev server on localhost, so a
        // full reload always applies — fired once per batch by the caller.
    }

    /**
     * Copy one changed file into the simulator's app container. Reloading is
     * deliberately not done here — see [triggerIosReloadForBatch].
     */
    private function syncIosFile(string $changedFile, string $basePath, string $destinationPath, string $viteHotFile): void
    {
        // Get relative path from source
        $relativePath = str_replace($basePath.'/', '', $changedFile);
        $destinationFile = $destinationPath.$relativePath;

        // Create destination directory if needed
        $destinationDir = dirname($destinationFile);
        if (! is_dir($destinationDir)) {
            mkdir($destinationDir, 0755, true);
        }

        // Copy the specific file
        if (file_exists($changedFile) && ! is_dir($changedFile)) {
            copy($changedFile, $destinationFile);
            $this->watchSynced($relativePath);
        }
    }

    /**
     * Trigger at most one reload for a whole batch of changed files, and only
     * once every file in it has been synced.
     *
     * A single save usually produces several watchman events — the file plus
     * its containing directory. Triggering per file raced the copies against
     * the reload: the app coalesces triggers that arrive mid-reload, so the
     * later files could be synced but never picked up.
     */
    private function triggerIosReloadForBatch(array $changedFiles, string $basePath, string $viteHotFile): void
    {
        $viteIsRunning = file_exists($viteHotFile);

        foreach ($changedFiles as $changedFile) {
            $relativePath = str_replace($basePath.'/', '', $changedFile);

            // Vite owns its own HMR; a native reload would fight it.
            if ($viteIsRunning && $this->isViteHandledIosFile($relativePath)) {
                continue;
            }

            $this->triggerIosReload();

            return;
        }
    }

    /**
     * Push a single file into the app's data container on a physical device.
     */
    private function copyToIosDevice(string $source, string $destination, string $target, string $appId): bool
    {
        $result = Process::timeout(30)->run([
            'xcrun', 'devicectl', 'device', 'copy', 'to',
            '--device', $target,
            '--domain-type', 'appDataContainer',
            '--domain-identifier', $appId,
            '--source', $source,
            '--destination', $destination,
        ]);

        if ($result->successful()) {
            return true;
        }

        $this->watchNote(
            '<fg=red>Failed to sync</> <fg=cyan>'.basename($destination).'</>'
            .(($error = trim($result->errorOutput())) !== '' ? " <fg=gray>{$error}</>" : '')
        );

        return false;
    }

    /**
     * Human-readable name of the watched simulator/device for the footer.
     */
    private function iosDeviceLabel(): ?string
    {
        $entry = $this->simulators[$this->iosTarget] ?? $this->devices[$this->iosTarget] ?? null;

        return $entry['name'] ?? $this->iosTarget;
    }

    /**
     * Make $uri the app's active native screen.
     *
     * The intent goes to the same place edited files do — base_path() inside
     * the app's container — and the standard reload trigger makes PHP pick it
     * up on its way through the hot-reload handler. See
     * NativeRouter::takeScreenIntent().
     */
    private function navigateIosTo(string $uri): bool
    {
        $intentFile = $this->writeScreenIntentFile($uri);
        $relativePath = NativeRouter::SCREEN_INTENT_PATH;

        try {
            if ($this->iosAppContainer !== null) {
                if (! @copy($intentFile, $this->iosAppContainer.'/Documents/app/'.$relativePath)) {
                    return false;
                }
            } elseif (! $this->copyToIosDevice(
                $intentFile,
                'Documents/app/'.$relativePath,
                (string) $this->iosTarget,
                (string) config('nativephp.app_id'),
            )) {
                return false;
            }
        } finally {
            @unlink($intentFile);
        }

        $this->triggerIosReload();

        return true;
    }

    private function triggerIosReload(): void
    {
        // Connect to the hot reload server to trigger a reload
        // For simulators this reaches the server directly (shared network)
        // For physical devices, iproxy forwards this to the device over USB
        $socket = @fsockopen('127.0.0.1', 9999, $errno, $errstr, 1);

        if ($socket) {
            // Hold the connection open long enough for iproxy to forward
            // it to the device over USB before we close
            usleep(200000);
            fclose($socket);
        } else {
            // Transient rather than a scrollback line: the app being down is a
            // state, not an event, so repeating it once per save is just noise.
            $this->watchActivity("reload failed — nothing listening on port 9999 ({$errstr})", 'yellow');
        }
    }

    private function startIproxyForwarding(string $target): bool
    {
        $iproxyPath = trim(Process::run('which iproxy')->output());

        if (empty($iproxyPath)) {
            return false;
        }

        // Kill any existing processes on port 9999
        Process::run('lsof -ti:9999 | xargs kill 2>/dev/null');
        usleep(500000);

        // Start iproxy in background for USB port forwarding
        // v2 syntax: iproxy -u UDID LOCAL_PORT:DEVICE_PORT
        $escapedTarget = escapeshellarg($target);
        $logFile = base_path('nativephp/iproxy.log');
        exec("{$iproxyPath} -u {$escapedTarget} 9999:9999 > {$logFile} 2>&1 & echo \$!", $output);
        $pid = (int) ($output[0] ?? 0);

        if ($pid <= 0) {
            $this->line('<fg=red>Failed to start iproxy</fg=red>');

            return false;
        }

        register_shutdown_function(function () use ($pid) {
            @exec("kill {$pid} 2>/dev/null");
        });

        // register_shutdown_function does NOT run when the watcher is stopped
        // with Ctrl-C (SIGINT) — the usual way — so iproxy would be orphaned
        // and keep holding port 9999, breaking the next run's hot reload.
        // Install signal handlers that tear it down before exiting.
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $teardown = function () use ($pid) {
                @exec("kill {$pid} 2>/dev/null");
                exit(0);
            };
            pcntl_signal(SIGINT, $teardown);
            pcntl_signal(SIGTERM, $teardown);
        }

        // Give iproxy time to start and check it's still running
        usleep(500000);

        $stillRunning = Process::run("kill -0 {$pid} 2>/dev/null")->successful();

        if (! $stillRunning) {
            $this->line('<fg=red>iproxy exited immediately</fg=red>');

            if (file_exists($logFile) && $log = trim(file_get_contents($logFile))) {
                $this->line("<fg=gray>{$log}</fg=gray>");
            }

            return false;
        }

        $this->line("iproxy running (PID {$pid}), log: {$logFile}");

        return true;
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

    private function isViteHandledIosFile(string $relativePath): bool
    {
        $vitePatterns = [
            '/^resources\/js\/.*\.(vue|js|ts|jsx|tsx)$/i',
            '/^resources\/css\/.*\.(css|scss|sass|less)$/i',
        ];

        foreach ($vitePatterns as $pattern) {
            if (preg_match($pattern, $relativePath)) {
                return true;
            }
        }

        return false;
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
