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

    /** Handle to the mDNS/Bonjour advertiser subprocess (LAN discovery). */
    private $mdnsProcess = null;

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

        // Register this instance (PID + ports) so a later `native:jump` start
        // can distinguish this live server from a crashed one. Cleaned up on
        // exit — register_shutdown_function fires on normal return, exit() from
        // the signal handler, and fatals alike.
        $this->writeInstanceRegistry($httpPort, $this->laravelPort, $wsPort, $bridgePort, $viteProxyPort);
        register_shutdown_function([$this, 'removeInstanceRegistry']);

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
            // The router proxies `GET /` to Laravel via a blocking curl, so it
            // is held open for the entire native-screen lifetime too. Same
            // single-worker starvation as the Laravel server — give the router
            // its own worker pool so /jump/info, /jump/qr and asset proxying
            // stay responsive while a native runloop request is in flight.
            'PHP_CLI_SERVER_WORKERS' => (string) max(4, (int) config('nativephp.server.workers', 10)),
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

        // Advertise on the LAN only once the router actually answers — an ad
        // for a server that failed to start manufactures phantom "server
        // nearby" pills that time out on tap.
        for ($i = 0; $i < 50; $i++) { // 5s max
            if ($this->isPortInUse($httpPort)) {
                $this->advertiseOnNetwork($httpPort);
                break;
            }
            usleep(100000);
        }
        if (! is_resource($this->mdnsProcess)) {
            $this->warn($this->isPortInUse($httpPort)
                ? 'LAN discovery unavailable (no dns-sd/avahi) — use the QR code.'
                : "Server did not start on port {$httpPort} — not advertising on the network.");
        }

        // The advertiser must never outlive the server: an orphaned dns-sd
        // keeps announcing a phantom server until some future run sweeps it.
        // register_shutdown_function covers normal return, exit() and fatals.
        register_shutdown_function([$this, 'stopAdvertiser']);

        // Handle signals for graceful shutdown
        if (function_exists('pcntl_signal')) {
            $shutdown = function () use ($process, &$pipes, $httpPort, $wsPort, $bridgePort, $viteProxyPort) {
                $this->newLine();
                $this->components->info('Shutting down...');
                $this->stopLaravelServer();
                $this->stopAdvertiser();
                if (is_resource($pipes[1])) {
                    fclose($pipes[1]);
                }
                if (is_resource($pipes[2])) {
                    fclose($pipes[2]);
                }
                proc_terminate($process);
                $this->reapOwnedPorts($httpPort, $wsPort, $bridgePort, $viteProxyPort);
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

            // Drain Laravel server output to prevent pipe buffer from filling
            if ($this->laravelProcess && is_resource($this->laravelProcess)) {
                if (is_resource($this->laravelPipes[1] ?? null)) {
                    fgets($this->laravelPipes[1]);
                }
                if (is_resource($this->laravelPipes[2] ?? null)) {
                    fgets($this->laravelPipes[2]);
                }
            }

            // Handle signals if available
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Small sleep to prevent CPU spinning
            usleep(10000); // 10ms
        }

        // Cleanup (router died on its own — crash or external kill). Stop the
        // advertiser FIRST: leaving it running orphans `dns-sd -R` to launchd,
        // which keeps announcing a phantom server the app can discover but
        // never reach.
        $this->stopAdvertiser();
        $this->stopLaravelServer();
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $this->reapOwnedPorts($httpPort, $wsPort, $bridgePort, $viteProxyPort);
    }

    /**
     * Terminate the mDNS/Bonjour advertiser subprocess. When `dns-sd -R`
     * exits, mDNSResponder deregisters the service and multicasts goodbye
     * packets, so browsing devices drop the entry within seconds instead of
     * caching it for the record TTL (up to 75 minutes).
     */
    public function stopAdvertiser(): void
    {
        if (is_resource($this->mdnsProcess)) {
            proc_terminate($this->mdnsProcess);
            proc_close($this->mdnsProcess);
            $this->mdnsProcess = null;
        }
    }

    /**
     * Kill every process still listening on this instance's ports — the same
     * reap cleanupDeadInstances() performs for crashed runs, applied at our own
     * shutdown. proc_terminate() only signals direct children: it misses the
     * php -S workers artisan serve leaves behind (SIGTERM kills artisan serve
     * before its Process destructor stops them) and the bridge server, which
     * runs fully detached. In --no-serve mode the Laravel server belongs to the
     * user, so its port is left alone.
     */
    private function reapOwnedPorts(int $httpPort, int $wsPort, int $bridgePort, int $viteProxyPort): void
    {
        $ports = [$httpPort, $wsPort, $bridgePort, $viteProxyPort];

        if (! $this->option('no-serve')) {
            $ports[] = $this->laravelPort;
        }

        foreach ($ports as $port) {
            $this->killListenersOnPort($port);
        }
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

        // --no-reload is REQUIRED for artisan serve to honour
        // PHP_CLI_SERVER_WORKERS (Laravel only spins up the multi-worker
        // built-in server with that flag — otherwise it warns and falls back
        // to a single worker, re-introducing the native-runloop starvation).
        // Jump runs its own file watcher in websocket-server.php, so artisan
        // serve's built-in .env reload is redundant here anyway.
        $cmd = sprintf(
            '%s %s serve --port=%d --host=127.0.0.1 --no-interaction --no-reload',
            escapeshellarg($phpBinary),
            escapeshellarg($artisan),
            $port
        );

        // Pass bridge ports so nativephp_call() (JumpBridge) in Laravel dials the right TCP port.
        //
        // PHP_CLI_SERVER_WORKERS is critical for native-UI apps: a native
        // screen's `GET /` runs the element runloop, which BLOCKS the request
        // for the whole lifetime of the screen. With the built-in server's
        // default single worker, that one blocked request starves every other
        // request (asset loads, /jump/info health checks, the next screen's
        // GET /) — the device's 10s health check then times out and the
        // session is torn down (the "scan → bounced home", "re-scan hangs"
        // loop). WebView (v3) apps never hit this because their `GET /`
        // returns immediately. Give the server a worker pool so the blocking
        // runloop request only occupies one of them.
        $env = array_merge($_ENV, $_SERVER, [
            'JUMP_BRIDGE_PORT' => (string) $bridgePort,
            'JUMP_WS_PORT' => (string) $wsPort,
            'PHP_CLI_SERVER_WORKERS' => (string) max(4, (int) config('nativephp.server.workers', 10)),
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
            $this->line('  <fg=green>iOS</>  Scan with Camera app or Jump app');
            $this->line('  <fg=blue>Android</>  Scan from within the Jump app');
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

    /**
     * Publish an mDNS/Bonjour service (`_jump._tcp`) so the app can discover
     * this dev server on the LAN and connect by tapping it — no QR scan. This
     * is purely additive: it advertises the SAME host + port the QR encodes, so
     * the app's connect flow is unchanged, and if no advertiser is available we
     * just skip (the QR still works). Best-effort, killed on shutdown.
     */
    private function advertiseOnNetwork(int $httpPort): void
    {
        // Advertise the SAME host the QR encodes ($displayHost is the
        // user-selected interface on multi-homed machines). Falling back to
        // getLocalIpAddress() here could point the pill at an interface the
        // phone can't reach while the QR works.
        $ip = $this->displayHost ?: ($this->getLocalIpAddress() ?: '127.0.0.1');
        $label = basename(base_path());

        // TXT records carry the reachable LAN IP, port and a friendly name, so
        // the app never has to resolve a flaky `.local` hostname (and iOS can
        // read host+port straight from the browse metadata, no resolve step).
        $txtHost = 'host='.$ip;
        $txtPort = 'port='.$httpPort;
        $txtName = 'name='.$label;

        $dnssd = trim((string) @shell_exec('command -v dns-sd 2>/dev/null'));
        $avahi = $dnssd === '' ? trim((string) @shell_exec('command -v avahi-publish-service 2>/dev/null')) : '';

        if ($dnssd !== '') {
            // macOS / Bonjour: dns-sd -R <name> <type> <domain> <port> [k=v ...]
            $cmd = sprintf(
                '%s -R %s _jump._tcp local %d %s %s %s',
                escapeshellarg($dnssd),
                escapeshellarg($label),
                $httpPort,
                escapeshellarg($txtHost),
                escapeshellarg($txtPort),
                escapeshellarg($txtName),
            );
        } elseif ($avahi !== '') {
            // Linux / Avahi: avahi-publish-service <name> <type> <port> [k=v ...]
            $cmd = sprintf(
                '%s %s _jump._tcp %d %s %s %s',
                escapeshellarg($avahi),
                escapeshellarg($label),
                $httpPort,
                escapeshellarg($txtHost),
                escapeshellarg($txtPort),
                escapeshellarg($txtName),
            );
        } else {
            return; // no advertiser on this platform — QR-only, no harm
        }

        $spec = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $proc = @proc_open($cmd, $spec, $pipes);
        if (is_resource($proc)) {
            $this->mdnsProcess = $proc;
            $this->components->info("Discoverable on this network as \"{$label}\" — open the app to connect without scanning.");
        }
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
            // Unix: reap only DEAD jump instances (and orphaned advertisers),
            // leaving any live sibling server running. This is what lets you
            // serve multiple projects at once on different ports — each fresh
            // start auto-finds free ports (findAvailablePort) and only cleans up
            // the leftovers of runs whose master process is gone.
            $this->cleanupDeadInstances();
        }
    }

    /** Directory holding one JSON file per live native:jump instance. */
    private function jumpRegistryDir(): string
    {
        $dir = sys_get_temp_dir().'/nativephp-jump-instances';
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private function instanceRegistryFile(): string
    {
        return $this->jumpRegistryDir().'/'.getmypid().'.json';
    }

    /**
     * Record this instance's PID + ports so a future `native:jump` start can
     * tell a live sibling (leave it alone) from a crashed one (reap its ports).
     */
    private function writeInstanceRegistry(int $httpPort, int $laravelPort, int $wsPort, int $bridgePort, int $viteProxyPort): void
    {
        @file_put_contents($this->instanceRegistryFile(), json_encode([
            'master_pid' => getmypid(),
            'http_port' => $httpPort,
            'laravel_port' => $laravelPort,
            'ws_port' => $wsPort,
            'bridge_port' => $bridgePort,
            'vite_port' => $viteProxyPort,
        ]));
    }

    public function removeInstanceRegistry(): void
    {
        @unlink($this->instanceRegistryFile());
    }

    private function isPidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return trim((string) @shell_exec('ps -p '.$pid.' -o pid= 2>/dev/null')) !== '';
    }

    /**
     * Kill jump-owned processes still listening on a port. IDENTITY-CHECKED:
     * a port recorded by a crashed instance may have since been re-bound by a
     * completely unrelated process (any `artisan serve`, a docker proxy, …) —
     * blindly `kill -9`-ing by port number executes innocents. Only processes
     * whose command line is recognizably part of a jump run are killed.
     */
    private function killListenersOnPort(int $port): void
    {
        if ($port <= 0) {
            return;
        }
        $out = trim((string) @shell_exec('lsof -nP -iTCP:'.$port.' -sTCP:LISTEN -t 2>/dev/null'));
        if ($out === '') {
            return;
        }
        foreach (array_unique(preg_split('/\s+/', $out)) as $pid) {
            if (! is_numeric($pid) || (int) $pid === getmypid()) {
                continue;
            }

            $info = trim((string) @shell_exec('ps -p '.(int) $pid.' -o ppid=,command= 2>/dev/null'));
            if ($info === '' || ! preg_match('/^\s*(\d+)\s+(.*)$/s', $info, $m)) {
                continue;
            }
            $ppid = (int) $m[1];
            $command = trim($m[2]);

            if (! $this->isJumpOwnedProcess($command, $ppid)) {
                $this->warn("Port {$port} is held by an unrelated process (pid {$pid}) — leaving it alone.");

                continue;
            }

            @exec('kill -9 '.(int) $pid.' 2>/dev/null');
        }
    }

    /**
     * Does this command line belong to a process a `native:jump` run spawns?
     * Matches the jump router, the bridge server, mDNS advertisers, the
     * MANAGED `artisan serve` (identified by the `--no-reload` flag jump
     * always passes — a user's own `artisan serve` doesn't have it), and
     * ORPHANED `php -S … server.php` workers (PPID 1 — a live user server's
     * workers still have their artisan parent).
     */
    private function isJumpOwnedProcess(string $command, int $ppid): bool
    {
        if (str_contains($command, 'resources/jump/router.php')
            || str_contains($command, 'resources/jump/websocket-server.php')
            || str_contains($command, '_jump._tcp')) {
            return true;
        }

        if (preg_match('/artisan[\'"]? serve/', $command) && str_contains($command, '--no-reload')) {
            return true;
        }

        if ($ppid === 1
            && preg_match('/php[^ ]* -S 127\.0\.0\.1:\d+/', $command)
            && str_contains($command, 'server.php')) {
            return true;
        }

        return false;
    }

    /**
     * Reap leftovers from PREVIOUS jump runs without touching live siblings.
     * A registry entry whose master PID is still alive is a concurrent server —
     * leave it (and its ports) running. An entry whose master is gone is a
     * crash: kill whatever still listens on its recorded ports and drop the
     * file. Finally sweep orphaned mDNS advertisers (PPID 1) that a crashed run
     * leaves advertising a phantom "server nearby".
     */
    private function cleanupDeadInstances(): void
    {
        $portKeys = ['http_port', 'laravel_port', 'ws_port', 'bridge_port', 'vite_port'];

        $entries = [];
        foreach (glob($this->jumpRegistryDir().'/*.json') ?: [] as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (is_array($data)) {
                $entries[$file] = $data;
            } else {
                @unlink($file);
            }
        }

        $isLive = function (array $data): bool {
            $pid = (int) ($data['master_pid'] ?? 0);

            return $pid > 0 && $pid !== getmypid() && $this->isPidAlive($pid);
        };

        // Ports a LIVE sibling owns — never reap these, even if a stale entry
        // from a crashed run happens to name the same (since-reused) port.
        $livePorts = [];
        foreach ($entries as $data) {
            if ($isLive($data)) {
                foreach ($portKeys as $key) {
                    if (! empty($data[$key])) {
                        $livePorts[(int) $data[$key]] = true;
                    }
                }
            }
        }

        foreach ($entries as $file => $data) {
            if ($isLive($data)) {
                continue; // live sibling server — leave it (and its ports) alone
            }

            foreach ($portKeys as $key) {
                $port = (int) ($data[$key] ?? 0);
                if ($port > 0 && ! isset($livePorts[$port])) {
                    $this->killListenersOnPort($port);
                }
            }
            @unlink($file);
        }

        $this->killOrphanedAdvertisers();
    }

    /**
     * Kill `dns-sd -R` / avahi advertisers for `_jump._tcp` orphaned to launchd
     * (PPID 1). A live server's advertiser is a direct child of its native:jump
     * process, so PPID 1 reliably means "owner crashed" — never a running server.
     */
    private function killOrphanedAdvertisers(): void
    {
        $ps = (string) @shell_exec('ps -Ao pid,ppid,command 2>/dev/null');
        foreach (preg_split('/\n/', $ps) as $line) {
            if (! preg_match('/_jump\._tcp/', $line)) {
                continue;
            }
            if (! preg_match('/dns-sd -R|avahi-publish-service/', $line)) {
                continue;
            }
            if (preg_match('/^\s*(\d+)\s+(\d+)\s/', $line, $m) && (int) $m[2] === 1) {
                @exec('kill -9 '.(int) $m[1].' 2>/dev/null');
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
