<?php

namespace Native\Mobile\Commands;

use Endroid\QrCode\Builder\Builder;
use Illuminate\Console\Command;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\select;

class JumpCommand extends Command
{
    protected $signature = 'native:jump
                            {--platform= : Target platform (android/a or ios/i)}
                            {--ios : Target iOS platform (shorthand for --platform=ios)}
                            {--android : Target Android platform (shorthand for --platform=android)}
                            {--host=0.0.0.0 : The host address to serve the application on}
                            {--ip= : The IP address to display in the QR code (overrides auto-detection)}
                            {--http-port= : The HTTP port to serve on}
                            {--laravel-port=8000 : The Laravel dev server port to proxy to}
                            {--no-mdns : Disable mDNS service advertisement}
                            {--S|skip-build : Skip building the app bundle if app.zip already exists}';

    protected $description = 'Start the NativePHP development server for testing mobile apps';

    private int $laravelPort;
    private string $displayHost;
    private string $platform;

    public function handle()
    {
        intro('NativePHP Jump Server');

        // Get platform (from option or prompt)
        if ($this->option('ios')) {
            $this->platform = 'ios';
        } elseif ($this->option('android')) {
            $this->platform = 'android';
        } else {
            $platformOption = $this->option('platform');
            if ($platformOption && in_array(strtolower($platformOption), ['android', 'ios', 'a', 'i'])) {
                $this->platform = match(strtolower($platformOption)) {
                    'android', 'a' => 'android',
                    'ios', 'i' => 'ios',
                };
            } else {
                $this->platform = select(
                    label: 'Select target platform',
                    options: ['android' => 'Android', 'ios' => 'iOS'],
                );
            }
        }

        // Run npm build for the selected platform
        $this->runNpmBuild();

        // Kill existing servers
        $this->killExistingServers();

        // Configuration
        $host = $this->option('host');
        $httpPort = $this->option('http-port') ?? config('nativephp.server.http_port', 3000);
        $this->laravelPort = $this->option('laravel-port') ?? 8000;

        // Auto-find available port
        $httpPort = $this->findAvailablePort($httpPort);
        if ($httpPort === null) {
            $this->error('Cannot start server: No available HTTP port found.');
            return self::FAILURE;
        }

        // Check if we should open browser
        $openQr = config('nativephp.server.open_browser', true);

        // Pre-build the Laravel bundle ZIP
        $buildPath = storage_path('app/native-build');
        $zipPath = $buildPath . '/app.zip';

        if (!is_dir($buildPath)) {
            mkdir($buildPath, 0755, true);
        }

        // Get the local IP for dev server config
        $ipOption = $this->option('ip');
        if ($ipOption) {
            $this->displayHost = $ipOption;
        } else {
            $ips = $this->getAllLocalIpAddresses();
            if (empty($ips)) {
                $this->displayHost = $host === '0.0.0.0' ? 'localhost' : $host;
            } elseif (count($ips) === 1) {
                $this->displayHost = $ips[0];
            } else {
                $options = [];
                foreach ($ips as $ip) {
                    $options[$ip] = $ip;
                }
                $this->displayHost = select(
                    label: 'Multiple network interfaces detected. Select the IP for the QR code',
                    options: $options,
                    hint: 'Choose the IP your mobile device can reach (usually Wi-Fi)'
                );
            }
        }

        // Check if we should skip building
        $skipBuild = $this->option('skip-build') && file_exists($zipPath);

        if ($skipBuild) {
            $bundleSize = $this->formatBytes(filesize($zipPath));
            $this->components->twoColumnDetail('Using existing bundle', $bundleSize);
        } else {
            // Delete existing bundle to ensure fresh build
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
            $bundleResult = $this->createZipWithProgress($zipPath, $this->displayHost);

            if (!$bundleResult) {
                $this->error('Failed to create Laravel bundle. Cannot start server.');
                return self::FAILURE;
            }

            $bundleSize = file_exists($zipPath) ? $this->formatBytes(filesize($zipPath)) : 'unknown';
            $this->components->twoColumnDetail('Bundle created', $bundleSize);
        }

        // Start PHP built-in server
        $this->startPhpServer($host, $httpPort, $zipPath, $openQr);

        return self::SUCCESS;
    }

    /**
     * Start PHP's built-in development server with the Jump router
     */
    private function startPhpServer(string $host, int $httpPort, string $zipPath, bool $openQr): void
    {
        $routerPath = __DIR__ . '/../../resources/jump/router.php';

        if (!file_exists($routerPath)) {
            $this->error("Router script not found at: {$routerPath}");
            return;
        }

        // Build environment variables for the router
        $env = [
            'JUMP_ZIP_PATH' => $zipPath,
            'JUMP_DISPLAY_HOST' => $this->displayHost,
            'JUMP_HTTP_PORT' => (string) $httpPort,
            'JUMP_LARAVEL_PORT' => (string) $this->laravelPort,
            'JUMP_BASE_PATH' => base_path(),
            'APP_NAME' => config('app.name', 'Laravel'),
        ];

        // Merge with current environment
        $fullEnv = array_merge($_ENV, $_SERVER, $env);

        // Filter to only string values
        $fullEnv = array_filter($fullEnv, fn($v) => is_string($v) || is_numeric($v));

        $this->displayServerInfo($host, $httpPort, $this->laravelPort);

        // Auto-open browser with QR code
        if ($openQr) {
            $this->openBrowser($host, $httpPort);
        }

        // Build the PHP server command
        $phpBinary = PHP_BINARY;
        $serverHost = $host === '0.0.0.0' ? '0.0.0.0' : $host;

        $descriptorSpec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $cmd = sprintf(
            '%s -S %s:%d %s',
            escapeshellarg($phpBinary),
            $serverHost,
            $httpPort,
            escapeshellarg($routerPath)
        );

        $process = proc_open($cmd, $descriptorSpec, $pipes, base_path(), $fullEnv);

        if (!is_resource($process)) {
            $this->error('Failed to start PHP server');
            return;
        }

        // Set pipes to non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Close stdin - we don't need to write to the server
        fclose($pipes[0]);

        // Handle signals for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use ($process, &$pipes) {
                $this->newLine();
                $this->components->info('Shutting down server...');
                if (is_resource($pipes[1])) fclose($pipes[1]);
                if (is_resource($pipes[2])) fclose($pipes[2]);
                proc_terminate($process);
                exit(0);
            });
            pcntl_signal(SIGTERM, function () use ($process, &$pipes) {
                if (is_resource($pipes[1])) fclose($pipes[1]);
                if (is_resource($pipes[2])) fclose($pipes[2]);
                proc_terminate($process);
                exit(0);
            });
        }

        // Main loop - read output from the server
        while (true) {
            // Check if process is still running
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            // Read stdout (PHP server access log)
            $stdout = fgets($pipes[1]);
            if ($stdout) {
                // Filter out noisy requests
                if (!str_contains($stdout, 'favicon.ico') && !str_contains($stdout, '.map')) {
                    // Parse and format the output
                    $this->formatServerOutput($stdout);
                }
            }

            // Read stderr (our custom log messages from router)
            $stderr = fgets($pipes[2]);
            if ($stderr) {
                // Our router logs to stderr with [Jump] prefix
                if (str_contains($stderr, '[Jump]')) {
                    $message = trim(str_replace('[Jump]', '', $stderr));
                    $this->components->twoColumnDetail('Device', $message);
                }
            }

            // Handle signals if available
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Small sleep to prevent CPU spinning
            usleep(10000); // 10ms
        }

        // Cleanup
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }

    /**
     * Format PHP server output for cleaner display
     */
    private function formatServerOutput(string $output): void
    {
        $output = trim($output);
        if (empty($output)) {
            return;
        }

        // PHP built-in server format: [Date Time] Client:Port [Status]: Method Path
        if (preg_match('/\[.+\]\s+(\d+\.\d+\.\d+\.\d+):(\d+)\s+\[(\d+)\]:\s+(\w+)\s+(.+)/', $output, $matches)) {
            $status = $matches[3];
            $method = $matches[4];
            $path = $matches[5];

            // Skip certain paths
            if (str_contains($path, '/jump/')) {
                return; // Our internal endpoints
            }

            // Color code by status
            if ($status >= 400) {
                $this->line("<fg=red>{$method} {$path} [{$status}]</>");
            } elseif ($status >= 300) {
                $this->line("<fg=yellow>{$method} {$path} [{$status}]</>");
            }
            // Don't log successful requests to reduce noise
        }
    }

    private function runNpmBuild(): void
    {
        $mode = $this->platform;

        // Check if package.json exists
        if (!file_exists(base_path('package.json'))) {
            return;
        }

        $buildOutput = [];
        $buildExitCode = 0;

        $this->components->task("Building assets for {$mode}", function () use ($mode, &$buildOutput, &$buildExitCode) {
            $command = "npm run build -- --mode={$mode}";
            exec("cd " . escapeshellarg(base_path()) . " && {$command} 2>&1", $buildOutput, $buildExitCode);
            return $buildExitCode === 0;
        });

        if ($buildExitCode !== 0 && !empty($buildOutput)) {
            $this->line(implode("\n", array_slice($buildOutput, -5)));
        }
    }

    private function createZipWithProgress($zipPath, $devHost = null): bool
    {
        $source = realpath(base_path());
        $buildPath = storage_path('app/native-build');

        if (!is_dir($buildPath)) {
            mkdir($buildPath, 0755, true);
        }

        $tempDir = $buildPath . DIRECTORY_SEPARATOR . 'temp-' . uniqid();

        // Exclude directories - match run command's pattern
        $excludedDirs = [
            '.git',
            '.idea',
            '.vscode',
            'node_modules',
            'storage',
            'nativephp/ios',
            'nativephp/android',
            'vendor/nativephp/mobile/resources',
            'output',
            'tests',
            '.github',
        ];

        try {
            // Phase 1: Copy files with progress
            $this->copyFilesWithProgress($source, $tempDir, $excludedDirs);

            // Copy and clean .env file
            if (file_exists($source . DIRECTORY_SEPARATOR . '.env')) {
                $envPath = $tempDir . DIRECTORY_SEPARATOR . '.env';
                copy($source . DIRECTORY_SEPARATOR . '.env', $envPath);
                $this->cleanEnvFile($envPath);
            }

            // Copy native.php bootstrap file
            $nativePhpSource = __DIR__ . '/../../resources/jump/native/native.php';
            $nativePhpDest = $tempDir . '/native.php';
            if (file_exists($nativePhpSource)) {
                copy($nativePhpSource, $nativePhpDest);
            }

            // Copy artisan.php wrapper
            $artisanPhpSource = __DIR__ . '/../../resources/jump/native/artisan.php';
            $artisanPhpDest = $tempDir . '/artisan.php';
            if (file_exists($artisanPhpSource)) {
                copy($artisanPhpSource, $artisanPhpDest);
            }

            // Create required Laravel directories
            $requiredDirs = [
                'bootstrap/cache',
                'storage/framework/cache',
                'storage/framework/sessions',
                'storage/framework/views',
                'storage/logs',
            ];
            foreach ($requiredDirs as $dir) {
                $dirPath = $tempDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
                if (!is_dir($dirPath)) {
                    mkdir($dirPath, 0755, true);
                }
                file_put_contents($dirPath . DIRECTORY_SEPARATOR . '.gitkeep', '');
            }

            // Add dev server config
            if ($devHost) {
                $devServerConfig = [
                    'host' => $devHost,
                    'port' => 3000,
                    'connectedAt' => date('c'),
                ];
                file_put_contents(
                    $tempDir . '/storage/framework/native_dev_server.json',
                    json_encode($devServerConfig, JSON_PRETTY_PRINT)
                );
            }

            // Phase 2: Create zip with progress bar
            $this->createZipWithProgressBar($tempDir, $zipPath, $excludedDirs);

            // Phase 3: Cleanup
            $this->cleanupTempDir($tempDir);

            if (!file_exists($zipPath) || filesize($zipPath) === 0) {
                throw new \Exception("ZIP file was not created or is empty");
            }

            return true;
        } catch (\Exception $e) {
            $this->error("Failed to create bundle: " . $e->getMessage());
            if (is_dir($tempDir)) {
                $this->cleanupTempDir($tempDir);
            }
            return false;
        }
    }

    private function copyFilesWithProgress($source, $destination, $excludedDirs = []): void
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $this->copyWithRobocopy($source, $destination, $excludedDirs);
        } else {
            $this->copyWithRsync($source, $destination, $excludedDirs);
        }
    }

    private function copyWithRobocopy($source, $destination, $excludedDirs = []): void
    {
        $excludeArgs = '';
        foreach ($excludedDirs as $dir) {
            $dir = ltrim($dir, '/\\');
            $dir = str_replace('/', '\\', $dir);
            $excludeArgs .= " /XD \"{$source}\\{$dir}\"";
        }

        $exitCode = 0;
        $this->components->task('Copying files', function () use ($source, $destination, $excludeArgs, &$exitCode) {
            $cmd = "robocopy \"{$source}\" \"{$destination}\" /MIR /NFL /NDL /NJH /NJS /NP /R:0 /W:0{$excludeArgs}";
            exec($cmd, $output, $exitCode);
            return $exitCode < 8;
        });

        if ($exitCode >= 8) {
            throw new \Exception("Robocopy failed with exit code {$exitCode}");
        }
    }

    private function copyWithRsync($source, $destination, $excludedDirs = []): void
    {
        $excludedDirs[] = 'vendor/*/vendor';
        $excludedDirs[] = 'vendor/nativephp/mobile/vendor';
        $excludeFlags = implode(' ', array_map(fn ($d) => "--exclude='" . ltrim($d, '/\\') . "'", $excludedDirs));

        $exitCode = 0;
        $this->components->task('Copying files', function () use ($source, $destination, $excludeFlags, &$exitCode) {
            $cmd = "rsync -aL {$excludeFlags} \"{$source}/\" \"{$destination}/\"";
            exec($cmd, $output, $exitCode);
            return $exitCode === 0;
        });

        if ($exitCode !== 0) {
            throw new \Exception("rsync failed with exit code {$exitCode}");
        }
    }

    private function createZipWithProgressBar($source, $destination, $excludedDirs = []): void
    {
        $source = realpath($source);

        if (PHP_OS_FAMILY === 'Windows') {
            $this->createZipWith7Zip($source, $destination, $excludedDirs);
        } else {
            $this->createZipWithZipArchive($source, $destination, $excludedDirs);
        }
    }

    private function createZipWithZipArchive($source, $destination, $excludedDirs = []): void
    {
        $this->components->task('Creating zip archive', function () use ($source, $destination, $excludedDirs) {
            $zip = new \ZipArchive;
            $result = $zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

            if ($result !== true) {
                return false;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($files as $file) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($source) + 1);

                $shouldSkip = false;
                foreach ($excludedDirs as $excluded) {
                    if (strpos($relativePath, $excluded . DIRECTORY_SEPARATOR) === 0 || $relativePath === $excluded) {
                        $shouldSkip = true;
                        break;
                    }
                }

                if ($shouldSkip || $file->getFilename() === '.' || $file->getFilename() === '..') {
                    continue;
                }

                $zipPath = str_replace('\\', '/', $relativePath);
                if (is_file($filePath)) {
                    $zip->addFile($filePath, $zipPath);
                } elseif (is_dir($filePath)) {
                    $zip->addEmptyDir($zipPath);
                }
            }

            $requiredDirs = [
                'bootstrap/cache',
                'storage/framework/cache',
                'storage/framework/sessions',
                'storage/framework/views',
                'storage/logs',
            ];

            foreach ($requiredDirs as $dir) {
                if (!$zip->statName($dir)) {
                    $zip->addEmptyDir($dir);
                }
            }

            $zip->close();
            return true;
        });
    }

    private function createZipWith7Zip($source, $destination, $excludedDirs = []): void
    {
        $sevenZip = config('nativephp.android.7zip-location', 'C:\\Program Files\\7-Zip\\7z.exe');

        if (!file_exists($sevenZip)) {
            $this->error("7-Zip not found at: {$sevenZip}");
            $this->line("Install 7-Zip from https://7-zip.org or set NATIVEPHP_7ZIP_LOCATION in your .env");
            throw new \Exception("7-Zip not found");
        }

        if (file_exists($destination)) {
            unlink($destination);
        }

        $exitCode = 0;
        $this->components->task('Creating zip archive', function () use ($sevenZip, $source, $destination, &$exitCode) {
            $cmd = "\"{$sevenZip}\" a -tzip \"{$destination}\" \"{$source}\\*\" -xr!node_modules";
            exec($cmd, $output, $exitCode);
            return $exitCode === 0;
        });

        if ($exitCode !== 0) {
            throw new \Exception("7-Zip failed with exit code {$exitCode}");
        }

        if (!file_exists($destination) || filesize($destination) === 0) {
            throw new \Exception("7-Zip failed to create the archive");
        }
    }

    private function cleanEnvFile($envPath)
    {
        if (!file_exists($envPath)) {
            return;
        }

        $content = file_get_contents($envPath);
        $lines = explode("\n", $content);
        $cleanedLines = [];

        foreach ($lines as $line) {
            if (preg_match('/^(DB_|MAIL_|AWS_|PUSHER_|REDIS_)/', trim($line))) {
                continue;
            }
            $cleanedLines[] = $line;
        }

        file_put_contents($envPath, implode("\n", $cleanedLines));
    }

    private function cleanupTempDir($tempDir)
    {
        if (!is_dir($tempDir)) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            exec("rmdir /s /q \"{$tempDir}\" 2>NUL", $output, $exitCode);
            if ($exitCode === 0) {
                return;
            }
        } else {
            exec("rm -rf " . escapeshellarg($tempDir));
            return;
        }

        $this->recursiveDelete($tempDir);
    }

    private function recursiveDelete($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }

    private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    private function displayServerInfo($host, $httpPort, $laravelPort)
    {
        $this->components->twoColumnDetail('Server running', 'Press Ctrl+C to stop');
    }

    private function getAllLocalIpAddresses(): array
    {
        $ips = [];

        if (PHP_OS_FAMILY === 'Darwin') {
            $output = shell_exec("ifconfig | grep 'inet ' | awk '{print \$2}'");
            if ($output) {
                $ips = array_filter(array_map('trim', explode("\n", $output)));
            }
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $output = shell_exec("ip -4 addr show scope global 2>/dev/null | grep -oP '(?<=inet\\s)\\d+(\\.\\d+){3}'");
            if ($output) {
                $ips = array_filter(array_map('trim', explode("\n", $output)));
            }
            if (empty($ips)) {
                $output = shell_exec("hostname -I 2>/dev/null");
                if ($output) {
                    $ips = array_filter(array_map('trim', explode(' ', $output)));
                }
            }
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec('powershell -Command "(Get-NetIPAddress -AddressFamily IPv4).IPAddress" 2>NUL');
            if ($output) {
                $ips = array_filter(array_map('trim', explode("\n", $output)));
            }
            if (empty($ips)) {
                $output = shell_exec("ipconfig 2>NUL");
                if ($output && preg_match_all('/IPv4 Address[.\s]*:\s*(\d+\.\d+\.\d+\.\d+)/', $output, $matches)) {
                    $ips = $matches[1];
                }
            }
        }

        // Filter out invalid IPs (loopback, APIPA)
        return array_values(array_filter($ips, function ($ip) {
            if (str_starts_with($ip, '127.')) {
                return false;
            }
            if (str_starts_with($ip, '169.254.')) {
                return false;
            }
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }));
    }

    private function getLocalIpAddress()
    {
        $ips = $this->getAllLocalIpAddresses();
        return $ips[0] ?? null;
    }

    private function openBrowser($host, $port)
    {
        $displayHost = $host === '0.0.0.0' ? 'localhost' : $host;
        $url = "http://{$displayHost}:{$port}/jump/qr";

        if (PHP_OS_FAMILY === 'Darwin') {
            $this->openOrRefreshMacOS($url);
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $commands = [
                "xdg-open " . escapeshellarg($url) . " > /dev/null 2>&1 &",
                "sensible-browser " . escapeshellarg($url) . " > /dev/null 2>&1 &",
                "x-www-browser " . escapeshellarg($url) . " > /dev/null 2>&1 &",
            ];
            foreach ($commands as $command) {
                exec($command, $output, $returnCode);
                if ($returnCode === 0) {
                    break;
                }
            }
        } elseif (PHP_OS_FAMILY === 'Windows') {
            exec('start "" ' . escapeshellarg($url));
        }
    }

    private function openOrRefreshMacOS($url)
    {
        $script = <<<APPLESCRIPT
tell application "System Events"
    set browserList to {"Google Chrome", "Safari", "Arc", "Brave Browser", "Microsoft Edge"}
    set foundTab to false

    repeat with browserName in browserList
        if exists (process browserName) then
            try
                if browserName is "Google Chrome" or browserName is "Brave Browser" or browserName is "Microsoft Edge" or browserName is "Arc" then
                    tell application browserName
                        set windowList to every window
                        repeat with w in windowList
                            set tabList to every tab of w
                            repeat with t in tabList
                                if URL of t contains "/jump" then
                                    set active tab index of w to (index of t)
                                    set index of w to 1
                                    tell t to reload
                                    activate
                                    set foundTab to true
                                    exit repeat
                                end if
                            end repeat
                            if foundTab then exit repeat
                        end repeat
                    end tell
                else if browserName is "Safari" then
                    tell application "Safari"
                        set windowList to every window
                        repeat with w in windowList
                            set tabList to every tab of w
                            repeat with t in tabList
                                if URL of t contains "/jump" then
                                    set current tab of w to t
                                    set index of w to 1
                                    tell t to do JavaScript "location.reload()"
                                    activate
                                    set foundTab to true
                                    exit repeat
                                end if
                            end repeat
                            if foundTab then exit repeat
                        end repeat
                    end tell
                end if
            end try
            if foundTab then exit repeat
        end if
    end repeat

    return foundTab
end tell
APPLESCRIPT;

        $result = trim(shell_exec("osascript -e " . escapeshellarg($script) . " 2>/dev/null") ?? '');

        if ($result !== 'true') {
            exec("open '{$url}' > /dev/null 2>&1 &");
        }
    }

    private function killExistingServers()
    {
        $currentPid = getmypid();

        if (PHP_OS_FAMILY === 'Windows') {
            // Kill PHP servers running the jump router
            $output = shell_exec('wmic process where "commandline like \'%router.php%\'" get processid 2>NUL');
            if (!$output) {
                $output = shell_exec('powershell -Command "Get-WmiObject Win32_Process | Where-Object { $_.CommandLine -like \'*router.php*\' } | Select-Object -ExpandProperty ProcessId" 2>NUL');
            }

            if ($output) {
                $pids = array_filter(preg_split('/\s+/', trim($output)), function($pid) use ($currentPid) {
                    return is_numeric($pid) && $pid != $currentPid && !empty($pid);
                });

                if (count($pids) > 0) {
                    $this->components->task('Cleaning up ' . count($pids) . ' existing server(s)', function () use ($pids) {
                        foreach ($pids as $pid) {
                            exec("taskkill /F /PID {$pid} 2>NUL");
                        }
                        usleep(500000);
                        return true;
                    });
                }
            }
        } else {
            // Unix: Kill PHP servers running the jump router
            $output = shell_exec("pgrep -f 'router.php' 2>/dev/null");

            if ($output) {
                $pids = array_filter(explode("\n", trim($output)));
                $pids = array_filter($pids, function($pid) use ($currentPid) {
                    return $pid != $currentPid && !empty($pid);
                });

                if (count($pids) > 0) {
                    $this->components->task('Cleaning up ' . count($pids) . ' existing server(s)', function () use ($pids) {
                        foreach ($pids as $pid) {
                            exec("kill -9 {$pid} 2>/dev/null");
                        }
                        usleep(500000);
                        return true;
                    });
                }
            }
        }
    }

    private function isPortInUse($port)
    {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($connection) {
            fclose($connection);
            return true;
        }
        return false;
    }

    private function findAvailablePort($startPort, $maxAttempts = 100, $excludePorts = [])
    {
        $port = $startPort;
        for ($i = 0; $i < $maxAttempts; $i++) {
            if (!$this->isPortInUse($port) && !in_array($port, $excludePorts)) {
                if ($port !== $startPort) {
                    $this->line("  Port {$startPort} in use, using {$port}");
                }
                return $port;
            }
            $port++;
        }
        return null;
    }
}