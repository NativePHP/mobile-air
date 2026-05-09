<?php

namespace Native\Mobile\Commands;

use Endroid\QrCode\Builder\Builder;
use Illuminate\Console\Command;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\select;

class JumpCommand extends Command
{
    protected $signature = 'native:jump
                            {--host=0.0.0.0 : The host address to serve the application on}
                            {--ip= : The IP address to display in the QR code (overrides auto-detection)}
                            {--http-port= : The HTTP port to serve on}
                            {--ws-port= : The WebSocket bridge port}
                            {--bridge-port= : The internal TCP bridge port}
                            {--vite-proxy-port= : The port Jump uses to proxy Vite HMR to the phone}
                            {--no-serve : Do not start artisan serve automatically (use if running your own server)}
                            {--laravel-port= : The Laravel dev server port (auto-detected when artisan serve is managed)}
                            {--no-mdns : Disable mDNS service advertisement}';

    protected $description = 'Start the NativePHP development server for testing mobile apps';

    private int $laravelPort;

    private string $displayHost;

    private $laravelProcess = null;

    private array $laravelPipes = [];

    private bool $verbose = false;

    public function handle()
    {
        $this->verbose = $this->output->isVerbose();

        intro('NativePHP Jump Server');

        // Kill existing servers
        $this->killExistingServers();

        // Configuration
        $host = $this->option('host');
        $httpPort = $this->option('http-port') ?? config('nativephp.server.http_port', 3000);

        // Auto-find available port for the Jump proxy server
        $httpPort = $this->findAvailablePort($httpPort);
        if ($httpPort === null) {
            $this->error('Cannot start server: No available HTTP port found.');

            return self::FAILURE;
        }

        // Resolve the Laravel port first (we need it so bridge ports don't collide)
        if ($this->option('no-serve')) {
            $this->laravelPort = (int) ($this->option('laravel-port') ?? 8000);
        } else {
            $desiredLaravelPort = (int) ($this->option('laravel-port') ?? 8000);
            $this->laravelPort = $this->findAvailablePort($desiredLaravelPort, 100, [$httpPort]);
            if ($this->laravelPort === null) {
                $this->error('Cannot start server: No available port for artisan serve.');

                return self::FAILURE;
            }
        }

        // Pick WS + bridge ports BEFORE starting artisan serve so nativephp_call
        // in the Laravel process can dial the correct JUMP_BRIDGE_PORT (not the default 3002).
        $usedPorts = [$httpPort, $this->laravelPort];
        $wsPort = (int) ($this->option('ws-port') ?? $this->findAvailablePort(3001, 100, $usedPorts));
        $usedPorts[] = $wsPort;
        $bridgePort = (int) ($this->option('bridge-port') ?? $this->findAvailablePort(3002, 100, $usedPorts));
        $usedPorts[] = $bridgePort;
        // Vite HMR proxy: phone connects here over WebSocket, we relay frames
        // to the real Vite dev server on 127.0.0.1. Keeps users from having to
        // edit vite.config.js for network access.
        $viteProxyPort = (int) ($this->option('vite-proxy-port') ?? $this->findAvailablePort(3003, 100, $usedPorts));

        // Start or detect the Laravel dev server
        if ($this->option('no-serve')) {
            // User is running their own artisan serve — tell them what to export
            if (! $this->isPortInUse($this->laravelPort)) {
                $this->warn("No server detected on port {$this->laravelPort}. Start one with: JUMP_BRIDGE_PORT={$bridgePort} php artisan serve --port={$this->laravelPort}");
            }
        } else {
            $this->startLaravelServer($this->laravelPort, $bridgePort, $wsPort);
        }

        // Check if we should open browser
        $openQr = config('nativephp.server.open_browser', true);

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

        $this->startBridgeServer($wsPort, $bridgePort, $viteProxyPort);
        $this->components->twoColumnDetail('Bridge WebSocket', "ws://{$this->displayHost}:{$wsPort}/jump/ws");
        $this->components->twoColumnDetail('Bridge TCP', "tcp://127.0.0.1:{$bridgePort}");
        $this->components->twoColumnDetail('Vite HMR proxy', "ws://{$this->displayHost}:{$viteProxyPort}/");

        // Start PHP built-in server (serves QR page + proxies to Laravel)
        $this->startPhpServer($host, $httpPort, $openQr, $bridgePort, $wsPort, $viteProxyPort);

        return self::SUCCESS;
    }

    /**
     * Start PHP's built-in development server with the Jump router
     */
    private function startPhpServer(string $host, int $httpPort, bool $openQr, int $bridgePort = 3002, int $wsPort = 3001, int $viteProxyPort = 3003): void
    {
        $routerPath = __DIR__.'/../../resources/jump/router.php';

        if (! file_exists($routerPath)) {
            $this->error("Router script not found at: {$routerPath}");

            return;
        }

        // Build environment variables for the router
        $env = [
            'JUMP_DISPLAY_HOST' => $this->displayHost,
            'JUMP_HTTP_PORT' => (string) $httpPort,
            'JUMP_LARAVEL_PORT' => (string) $this->laravelPort,
            'JUMP_BRIDGE_PORT' => (string) $bridgePort,
            'JUMP_WS_PORT' => (string) $wsPort,
            'JUMP_VITE_PORT' => (string) config('nativephp.server.vite_port', 5173),
            'JUMP_VITE_PROXY_PORT' => (string) $viteProxyPort,
            'JUMP_BASE_PATH' => base_path(),
            'APP_NAME' => config('app.name', 'Laravel'),
        ];

        // Merge with current environment
        $fullEnv = array_merge($_ENV, $_SERVER, $env);

        // Filter to only string values
        $fullEnv = array_filter($fullEnv, fn ($v) => is_string($v) || is_numeric($v));

        $this->displayServerInfo($host, $httpPort, $this->laravelPort);
        $this->displayTerminalQrCode($this->displayHost, $httpPort);

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

        if (! is_resource($process)) {
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
            $shutdown = function () use ($process, &$pipes) {
                $this->newLine();
                $this->components->info('Shutting down...');
                $this->stopLaravelServer();
                if (is_resource($pipes[1])) {
                    fclose($pipes[1]);
                }
                if (is_resource($pipes[2])) {
                    fclose($pipes[2]);
                }
                proc_terminate($process);
                exit(0);
            };
            pcntl_signal(SIGINT, $shutdown);
            pcntl_signal(SIGTERM, $shutdown);
        }

        // Main loop - read output from the server
        while (true) {
            // Check if process is still running
            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }

            // Read stdout (PHP server access log)
            $stdout = fgets($pipes[1]);
            if ($stdout) {
                // Filter out noisy requests (unless verbose)
                if ($this->verbose || (! str_contains($stdout, 'favicon.ico') && ! str_contains($stdout, '.map'))) {
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
                } elseif ($this->verbose) {
                    $this->line('  <fg=gray>[php] '.trim($stderr).'</>');
                }
            }

            // Drain Laravel server output to prevent pipe buffer from filling
            if ($this->laravelProcess && is_resource($this->laravelProcess)) {
                if (is_resource($this->laravelPipes[1] ?? null)) {
                    $laravelStdout = fgets($this->laravelPipes[1]);
                    if ($laravelStdout && $this->verbose) {
                        $this->line('  <fg=gray>[laravel] '.trim($laravelStdout).'</>');
                    }
                }
                if (is_resource($this->laravelPipes[2] ?? null)) {
                    $laravelStderr = fgets($this->laravelPipes[2]);
                    if ($laravelStderr && $this->verbose) {
                        $this->line('  <fg=gray>[laravel] '.trim($laravelStderr).'</>');
                    }
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
        $this->stopLaravelServer();
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }

    /**
     * Start the WebSocket bridge server for hybrid mode.
     * Runs as a background process alongside the HTTP server.
     */
    private function startBridgeServer(int $wsPort, int $bridgePort, int $viteProxyPort = 3003): void
    {
        $serverPath = __DIR__.'/../../resources/jump/websocket-server.php';

        if (! file_exists($serverPath)) {
            $this->warn('WebSocket bridge server script not found, skipping hybrid mode support.');

            return;
        }

        $phpBinary = PHP_BINARY;

        // Write bridge logs to a file the user can tail. Prior versions sent
        // stderr to /dev/null, which made it impossible to see bridge_call
        // traffic, device connects, or errors.
        $logDir = base_path('storage/logs');
        if (! is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir.'/jump-bridge.log';
        @file_put_contents($logFile, '=== '.date('Y-m-d H:i:s')." bridge server starting (ws={$wsPort} tcp={$bridgePort} vite_proxy={$viteProxyPort}) ===\n", FILE_APPEND);

        // Run in background (not Workerman daemon mode — it breaks the event loop)
        $cmd = sprintf(
            '%s %s %s %d %d %d start >> %s 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($serverPath),
            escapeshellarg(base_path()),
            $wsPort,
            $bridgePort,
            $viteProxyPort,
            escapeshellarg($logFile)
        );

        exec($cmd);

        // Give it a moment to start
        usleep(500000);

        $this->components->twoColumnDetail('Bridge log', "tail -f {$logFile}");
    }

    /**
     * Start Laravel's artisan serve as a background process.
     */
    private function startLaravelServer(int $port, int $bridgePort = 3002, int $wsPort = 3001): void
    {
        $phpBinary = PHP_BINARY;
        $artisan = base_path('artisan');

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cmd = sprintf(
            '%s %s serve --port=%d --host=127.0.0.1 --no-interaction',
            escapeshellarg($phpBinary),
            escapeshellarg($artisan),
            $port
        );

        // Pass bridge ports so nativephp_call() (JumpBridge) in Laravel dials the right TCP port.
        $env = array_merge($_ENV, $_SERVER, [
            'JUMP_BRIDGE_PORT' => (string) $bridgePort,
            'JUMP_WS_PORT' => (string) $wsPort,
        ]);
        $env = array_filter($env, fn ($v) => is_string($v) || is_numeric($v));

        $this->laravelProcess = proc_open($cmd, $descriptorSpec, $this->laravelPipes, base_path(), $env);

        if (! is_resource($this->laravelProcess)) {
            $this->error('Failed to start artisan serve');

            return;
        }

        // Set pipes to non-blocking so we don't hang
        stream_set_blocking($this->laravelPipes[1], false);
        stream_set_blocking($this->laravelPipes[2], false);
        fclose($this->laravelPipes[0]);

        // Wait for Laravel to actually start listening
        $maxWait = 50; // 5 seconds max
        for ($i = 0; $i < $maxWait; $i++) {
            usleep(100000); // 100ms
            if ($this->isPortInUse($port)) {
                break;
            }
        }

        if (! $this->isPortInUse($port)) {
            $this->warn('Laravel server may not have started correctly on port '.$port);
        }

        $this->components->twoColumnDetail('Laravel server', "http://127.0.0.1:{$port}");
    }

    /**
     * Stop the managed Laravel server process.
     */
    private function stopLaravelServer(): void
    {
        if ($this->laravelProcess && is_resource($this->laravelProcess)) {
            if (is_resource($this->laravelPipes[1] ?? null)) {
                fclose($this->laravelPipes[1]);
            }
            if (is_resource($this->laravelPipes[2] ?? null)) {
                fclose($this->laravelPipes[2]);
            }
            proc_terminate($this->laravelProcess);
            proc_close($this->laravelProcess);
            $this->laravelProcess = null;
        }
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

            // Skip internal endpoints unless verbose
            if (! $this->verbose && str_contains($path, '/jump/')) {
                return;
            }

            // Color code by status
            if ($status >= 400) {
                $this->line("<fg=red>{$method} {$path} [{$status}]</>");
            } elseif ($status >= 300) {
                $this->line("<fg=yellow>{$method} {$path} [{$status}]</>");
            } elseif ($method !== 'GET') {
                // Surface non-GET traffic (Livewire POSTs, form submits) so
                // you can correlate UI actions with server handlers.
                $this->line("<fg=cyan>{$method} {$path} [{$status}]</>");
            } elseif ($this->verbose) {
                // GET 2xx are silent by default to reduce asset-load noise.
                $this->line("<fg=gray>{$method} {$path} [{$status}]</>");
            }
        } elseif ($this->verbose) {
            // Unrecognized output — show it raw so you don't miss PHP warnings/notices.
            $this->line('  <fg=gray>'.$output.'</>');
        }
    }

    private function displayServerInfo($host, $httpPort, $laravelPort)
    {
        $this->components->twoColumnDetail('Server running', 'Press Ctrl+C to stop');
    }

    /**
     * Display a QR code in the terminal using Unicode block characters.
     * Scannable with the phone's native camera — opens the Jump app via deep link.
     */
    private function displayTerminalQrCode(string $host, int $port): void
    {
        try {
            if (! class_exists(Builder::class)) {
                return;
            }

            $qrData = "jump://connect?host={$host}&port={$port}";

            $result = (new Builder(
                data: $qrData,
                size: 300,
                margin: 2,
            ))->build();

            $matrix = $result->getMatrix();
            $size = $matrix->getBlockCount();

            $this->newLine();
            $this->line('  <fg=white;bg=black>Scan with your camera to open in Jump</>');
            $this->newLine();

            // Render two rows at a time using Unicode half-block characters:
            // ▀ (upper half) = top black, bottom white
            // ▄ (lower half) = top white, bottom black
            // █ (full block) = both black
            //   (space)      = both white
            for ($y = 0; $y < $size; $y += 2) {
                $line = '  '; // left margin
                for ($x = 0; $x < $size; $x++) {
                    $top = $matrix->getBlockValue($x, $y);
                    $bottom = ($y + 1 < $size) ? $matrix->getBlockValue($x, $y + 1) : 0;

                    if ($top && $bottom) {
                        $line .= '█';
                    } elseif ($top && ! $bottom) {
                        $line .= '▀';
                    } elseif (! $top && $bottom) {
                        $line .= '▄';
                    } else {
                        $line .= ' ';
                    }
                }
                $this->line($line);
            }

            $this->newLine();
            $this->line("  <fg=gray>{$qrData}</>");
            $this->newLine();
        } catch (\Throwable $e) {
            // QR display is optional — don't break the server
        }
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
                $output = shell_exec('hostname -I 2>/dev/null');
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
                $output = shell_exec('ipconfig 2>NUL');
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
                'xdg-open '.escapeshellarg($url).' > /dev/null 2>&1 &',
                'sensible-browser '.escapeshellarg($url).' > /dev/null 2>&1 &',
                'x-www-browser '.escapeshellarg($url).' > /dev/null 2>&1 &',
            ];
            foreach ($commands as $command) {
                exec($command, $output, $returnCode);
                if ($returnCode === 0) {
                    break;
                }
            }
        } elseif (PHP_OS_FAMILY === 'Windows') {
            exec('start "" '.escapeshellarg($url));
        }
    }

    private function openOrRefreshMacOS($url)
    {
        $script = <<<'APPLESCRIPT'
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

        $result = trim(shell_exec('osascript -e '.escapeshellarg($script).' 2>/dev/null') ?? '');

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
            if (! $output) {
                $output = shell_exec('powershell -Command "Get-WmiObject Win32_Process | Where-Object { $_.CommandLine -like \'*router.php*\' } | Select-Object -ExpandProperty ProcessId" 2>NUL');
            }

            if ($output) {
                $pids = array_filter(preg_split('/\s+/', trim($output)), function ($pid) use ($currentPid) {
                    return is_numeric($pid) && $pid != $currentPid && ! empty($pid);
                });

                if (count($pids) > 0) {
                    $this->components->task('Cleaning up '.count($pids).' existing server(s)', function () use ($pids) {
                        foreach ($pids as $pid) {
                            exec("taskkill /F /PID {$pid} 2>NUL");
                        }
                        usleep(500000);

                        return true;
                    });
                }
            }
        } else {
            // Unix: Kill WebSocket bridge servers and Workerman workers
            exec("pkill -9 -f 'websocket-server.php' 2>/dev/null");
            exec("pkill -9 -f 'WorkerMan:' 2>/dev/null");
            usleep(300000);

            // Unix: Kill PHP servers running the jump router
            $output = shell_exec("pgrep -f 'router.php' 2>/dev/null");

            if ($output) {
                $pids = array_filter(explode("\n", trim($output)));
                $pids = array_filter($pids, function ($pid) use ($currentPid) {
                    return $pid != $currentPid && ! empty($pid);
                });

                if (count($pids) > 0) {
                    $this->components->task('Cleaning up '.count($pids).' existing server(s)', function () use ($pids) {
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
            if (! $this->isPortInUse($port) && ! in_array($port, $excludePorts)) {
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
