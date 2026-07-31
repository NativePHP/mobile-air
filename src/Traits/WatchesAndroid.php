<?php

namespace Native\Mobile\Traits;

use Symfony\Component\Process\Process;

trait WatchesAndroid
{
    use ManagesPollingWatcher, ManagesWatchman;

    private array $androidWatchPaths = ['app', 'resources', 'routes', 'config', 'database', 'public'];

    private array $androidExcludePatterns = [
        '.git',
        'storage/logs',
        'storage/framework',
        'vendor',
        'node_modules',
        '.swp',
        '.tmp',
        '.log',
    ];

    private ?float $publicBuildChangeTime = null;

    private bool $publicBuildSyncScheduled = false;

    private ?float $lastAdbHealthCheck = null;

    private ?int $vitePort = null;

    protected function startAndroidHotReload(): void
    {
        if (! $this->checkAndroidHotReloadDependencies()) {
            return;
        }

        if (! $this->checkAndroidDeviceConnection()) {
            return;
        }

        // Start Vite dev server if the nativephpMobile plugin is installed
        $this->startViteDevServer('android');

        // Detect Vite port from public/hot file
        $this->vitePort = $this->detectVitePort();

        if ($this->vitePort) {
            $this->setupViteDevServerForwarding($this->vitePort);
        }

        $this->startAndroidWatching();
    }

    private function checkAndroidHotReloadDependencies(): bool
    {
        // Only check for Watchman on non-Windows systems (Windows uses polling)
        if (PHP_OS_FAMILY !== 'Windows' && ! $this->checkWatchmanDependencies()) {
            return false;
        }

        $adbCheck = Process::fromShellCommandline(
            PHP_OS_FAMILY === 'Windows' ? 'where adb' : 'which adb'
        );
        $adbCheck->run();

        if (! $adbCheck->isSuccessful()) {
            $this->error('adb not found. Please install Android SDK platform tools');

            return false;
        }

        return true;
    }

    private function checkAndroidDeviceConnection(): bool
    {
        $process = new Process(['adb', 'devices']);
        $process->run();

        if (! str_contains($process->getOutput(), 'device')) {
            \Laravel\Prompts\error('No Android device connected via ADB');
            \Laravel\Prompts\note('Make sure USB debugging is enabled and device is connected');

            return false;
        }

        return true;
    }

    private function detectVitePort(): ?int
    {
        $hotFilePath = $this->getHotFilePath('android');

        if (! file_exists($hotFilePath)) {
            return null;
        }

        $hotFileContents = trim(file_get_contents($hotFilePath));

        if (preg_match('/\]:(\d+)$/', $hotFileContents, $matches)) {
            $port = (int) $matches[1];
        } elseif (preg_match('/:(\d+)$/', $hotFileContents, $matches)) {
            $port = (int) $matches[1];
        } else {
            return null;
        }

        return $port;
    }

    private function setupViteDevServerForwarding(int $port): void
    {
        $process = new Process(['adb', 'reverse', "tcp:{$port}", "tcp:{$port}"]);
        $process->setTimeout(10);
        $process->run();

        if ($process->isSuccessful() || str_contains($process->getErrorOutput(), 'already forwarded')) {
            $this->initializeViteWatcher($port);
        }
    }

    private function initializeViteWatcher(int $port): void
    {
        // Set up reverse port forwarding so device's localhost:$port forwards to host
        $process = new Process([
            'curl',
            '-s',
            '--max-time', '2',
            "http://127.0.0.1:{$port}/@vite/client",
        ]);
        $process->run();
    }

    private function startAndroidWatching(): void
    {
        $this->info('Android hot reload active - watching for changes...');
        $this->line('<fg=yellow>Press Ctrl+C to stop</fg=yellow>');

        $watchPaths = $this->getWatchPaths();
        $excludePatterns = $this->getExcludePatterns();
        $onChange = fn (string $filePath) => $this->handleAndroidFileChange($filePath);
        $onTick = fn () => $this->performAndroidPeriodicTasks();

        // Use polling watcher on Windows, Watchman on macOS/Linux
        if (PHP_OS_FAMILY === 'Windows') {
            $this->startPollingWatcher($watchPaths, $excludePatterns, $onChange, $onTick);
        } else {
            $this->startWatchman($watchPaths, $excludePatterns, $onChange, $onTick);
        }
    }

    /**
     * Perform periodic tasks during Android file watching
     */
    private function performAndroidPeriodicTasks(): void
    {
        // Check if we should sync public/build
        $this->checkAndSyncPublicBuild();

        // Check adb connection health
        $this->checkAdbConnection();

        // Check for Vite process output
        $this->checkViteProcessOutput();
    }

    private function handleAndroidFileChange(string $filePath): void
    {
        // Normalize paths to use forward slashes for consistent comparison across platforms
        $basePath = str_replace('\\', '/', base_path());
        $normalizedPath = str_replace('\\', '/', $filePath);
        $relativePath = str_replace($basePath.'/', '', $normalizedPath);

        if (str_starts_with($relativePath, 'public/build')) {
            $this->publicBuildChangeTime = microtime(true);
            $this->publicBuildSyncScheduled = true;

            return;
        }

        if (! file_exists($filePath) || is_dir($filePath) || $this->shouldSkipAndroidFile($relativePath)) {
            return;
        }

        // Special handling for hot file - sync but don't reload
        if (str_ends_with($relativePath, 'public/android-hot')) {
            $this->line('<fg=cyan>Vite dev server detected, syncing hot file...</fg=cyan>');
            $this->syncAndroidFile($filePath, $relativePath);

            return;
        }

        if ($this->isViteHandledFile($relativePath)) {
            return;
        }

        $this->line("Changed: {$relativePath}");

        if ($this->syncAndroidFile($filePath, $relativePath)) {
            $this->triggerAndroidReload();
        }
    }

    private function checkAndSyncPublicBuild(): void
    {
        if (! $this->publicBuildSyncScheduled) {
            return;
        }

        if ($this->publicBuildChangeTime && (microtime(true) - $this->publicBuildChangeTime) > 0.5) {
            $this->publicBuildSyncScheduled = false;
            $this->publicBuildChangeTime = null;

            $this->syncPublicBuildDirectory();
        }
    }

    private function checkAdbConnection(): void
    {
        // Skip health check if no Vite port detected
        if (! $this->vitePort) {
            return;
        }

        $now = microtime(true);

        // Check every 30 seconds
        if ($this->lastAdbHealthCheck !== null && ($now - $this->lastAdbHealthCheck) < 30) {
            return;
        }

        $this->lastAdbHealthCheck = $now;

        // Check if adb reverse is still active
        $process = new Process(['adb', 'reverse', '--list']);
        $process->run();

        if (! $process->isSuccessful() || ! str_contains($process->getOutput(), "tcp:{$this->vitePort}")) {
            $reverseProcess = new Process(['adb', 'reverse', "tcp:{$this->vitePort}", "tcp:{$this->vitePort}"]);
            $reverseProcess->run();
        }
    }

    private function syncPublicBuildDirectory(): void
    {
        $packageName = config('nativephp.app_id');
        $localBuildPath = base_path('public/build');
        $deviceBasePath = "/data/data/{$packageName}/app_storage/laravel/public";
        $tempPath = '/data/local/tmp/build_'.uniqid();

        if (! is_dir($localBuildPath)) {
            return;
        }

        $pushProcess = new Process(['adb', 'push', $localBuildPath, $tempPath]);
        $pushProcess->run();

        if (! $pushProcess->isSuccessful()) {
            return;
        }

        // Step 2: Remove old build directory
        $rmProcess = new Process(['adb', 'shell', 'run-as', $packageName, 'rm', '-rf', $deviceBasePath.'/build']);
        $rmProcess->run();

        // Step 3: Create public directory if needed
        $mkdirProcess = new Process(['adb', 'shell', 'run-as', $packageName, 'mkdir', '-p', $deviceBasePath]);
        $mkdirProcess->run();

        // Step 4: Copy from temp to app storage
        $cpProcess = new Process(['adb', 'shell', 'run-as', $packageName, 'cp', '-r', $tempPath, $deviceBasePath.'/build']);
        $cpProcess->run();

        $cleanupProcess = new Process(['adb', 'shell', 'rm', '-rf', $tempPath]);
        $cleanupProcess->run();

        if (! $cpProcess->isSuccessful()) {
            return;
        }

        $this->triggerAndroidReload();
    }

    private function shouldSkipAndroidFile(string $relativePath): bool
    {
        $skipPatterns = [
            '/\.DS_Store$/',
            '/Thumbs\.db$/',
            '/\.git/',
            '/\.(jpg|jpeg|png|gif|bmp|ico|svg)$/i',
            '/\.(mp4|avi|mov|wmv|mp3|wav)$/i',
            '/\.(zip|tar|gz|rar|7z)$/i',
            '/\.(pdf|doc|docx|xls|xlsx)$/i',
            '/storage\/logs\//',
            '/storage\/framework\/cache\//',
            '/storage\/framework\/sessions\//',
            '/storage\/framework\/views\//',
            '/bootstrap\/cache\//',
            '/public\/storage\//',
            '/public\/vendor\//',
        ];

        foreach ($skipPatterns as $pattern) {
            if (preg_match($pattern, $relativePath)) {
                return true;
            }
        }

        return false;
    }

    private function isViteHandledFile(string $relativePath): bool
    {
        // Files that Vite handles via HMR - don't sync or reload for these
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

    private function getWatchPaths(): array
    {
        $paths = config('nativephp.hot_reload.watch_paths', $this->androidWatchPaths);

        // Convert relative paths to absolute paths
        return array_map(function ($path) {
            if (! str_starts_with($path, '/')) {
                return base_path($path);
            }

            return $path;
        }, $paths);
    }

    private function getExcludePatterns(): array
    {
        return config('nativephp.hot_reload.exclude_patterns', $this->androidExcludePatterns);
    }

    private function syncAndroidFile(string $localPath, string $relativePath): bool
    {
        $packageName = config('nativephp.app_id');
        $deviceBasePath = "/data/data/{$packageName}/app_storage/laravel";

        // Fix the relative path calculation for Windows
        $basePath = str_replace('\\', '/', base_path());
        $normalizedLocalPath = str_replace('\\', '/', $localPath);

        // Calculate proper relative path
        if (str_starts_with($normalizedLocalPath, $basePath)) {
            $calculatedRelativePath = substr($normalizedLocalPath, strlen($basePath) + 1); // +1 for trailing slash
        } else {
            $calculatedRelativePath = $relativePath;
        }

        // Normalize paths for cross-platform compatibility
        $localPath = $normalizedLocalPath;
        $relativePath = $calculatedRelativePath;
        $devicePath = "{$deviceBasePath}/{$relativePath}";

        // Check if the file actually exists and is a file (not directory)
        if (! file_exists($localPath)) {
            return false;
        }

        if (is_dir($localPath)) {
            return true;
        }

        // Generate a unique temp filename to avoid conflicts
        $tempFileName = 'hotreload_'.uniqid().'_'.basename($localPath);
        $tempPath = "/data/local/tmp/{$tempFileName}";

        $pushProcess = new Process(['adb', 'push', $localPath, $tempPath]);
        $pushProcess->run();

        if (! $pushProcess->isSuccessful()) {
            return false;
        }

        $deviceDir = dirname($devicePath);
        $mkdirProcess = new Process(['adb', 'shell', 'run-as', $packageName, 'mkdir', '-p', $deviceDir]);
        $mkdirProcess->run();

        $copyProcess = new Process(['adb', 'shell', 'run-as', $packageName, 'cp', $tempPath, $devicePath]);
        $copyProcess->run();

        $cleanupProcess = new Process(['adb', 'shell', 'rm', $tempPath]);
        $cleanupProcess->run();

        return $copyProcess->isSuccessful();
    }

    private function triggerAndroidReload(): void
    {
        $packageName = config('nativephp.app_id');

        $reloadSignal = json_encode([
            'timestamp' => time(),
            'reload_type' => 'hot_reload',
            'platform' => 'android',
        ]);

        $localTemp = sys_get_temp_dir().'/reload_signal.json';
        file_put_contents($localTemp, $reloadSignal);

        $tempSignalPath = '/data/local/tmp/reload_signal.json';
        $signalPath = "/data/data/{$packageName}/app_storage/laravel/storage/framework/reload_signal.json";

        $pushProcess = new Process(['adb', 'push', $localTemp, $tempSignalPath]);
        $pushProcess->run();

        $copyProcess = new Process(['adb', 'shell', 'run-as', $packageName, 'cp', $tempSignalPath, $signalPath]);
        $copyProcess->run();

        $cleanupProcess = new Process(['adb', 'shell', 'rm', $tempSignalPath]);
        $cleanupProcess->run();

        @unlink($localTemp);
    }
}
