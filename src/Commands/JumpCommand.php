<?php

namespace Native\Mobile\Commands;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
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
                            {--no-mdns : Disable mDNS service advertisement}
                            {--browser : Open the QR code page in the default browser (useful when terminal rendering is unreliable)}';

    protected $description = 'Start the NativePHP development server for testing mobile apps';

    private int $laravelPort;

    private string $displayHost;

    private $laravelProcess = null;

    private array $laravelPipes = [];

    private $bridgeProcess = null;

    // Windows-only: separate process for the Vite HMR proxy because
    // Workerman can't fork two Workers from one file on Windows.
    private $viteHmrProcess = null;

    private bool $verbose = false;

    public function handle()
    {
        $this->verbose = $this->output->isVerbose();

        intro('NativePHP Jump Server');

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

        // Open the browser-rendered QR page only when --browser is passed.
        // Terminal QR is the default; the browser page is the fallback for
        // environments where terminal rendering can't produce a scannable
        // image (font/line-height issues, narrow viewports, etc.).
        // Intentionally ignore config('nativephp.server.open_browser') —
        // published consumer configs default it to true, which would
        // override the flag-driven UX we want here.
        $openQr = (bool) $this->option('browser');

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
        // On Windows we run a Workerman-based HTTP proxy instead of `php -S`.
        // `php -S` on Windows holds dead HTTP/1.1 keep-alive sockets in
        // Established state for the OS's full 2-hour TCP keepalive window
        // (no SO_KEEPALIVE on the listen socket), and a few of those exhaust
        // the single-threaded server's accept loop — browser visits and
        // subsequent phone scans hang. Workerman manages connection
        // lifecycle correctly and closes after each response.
        //
        // macOS/Linux keep using `php -S` + router.php because they don't
        // hit the dead-socket pathology and we don't want to introduce a
        // new code path on platforms that already work.
        $useWorkerman = PHP_OS_FAMILY === 'Windows';

        $routerPath = __DIR__.'/../../resources/jump/router.php';
        $workermanServerPath = __DIR__.'/../../resources/jump/http-server.php';
        $serverScriptPath = $useWorkerman ? $workermanServerPath : $routerPath;

        if (! file_exists($serverScriptPath)) {
            $this->error("Server script not found at: {$serverScriptPath}");

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

        if ($useWorkerman) {
            // Workerman script takes positional args: base_path, host, port,
            // then the Workerman `start` token. Env vars are also read by
            // the script for everything beyond host/port.
            $cmd = sprintf(
                '%s %s %s %s %d start',
                escapeshellarg($phpBinary),
                escapeshellarg($serverScriptPath),
                escapeshellarg(base_path()),
                escapeshellarg($serverHost),
                $httpPort
            );

            $this->components->twoColumnDetail('HTTP proxy', '<fg=cyan>workerman</> (windows: avoids `php -S` dead-socket wedge)');
        } else {
            $cmd = sprintf(
                '%s -S %s:%d %s',
                escapeshellarg($phpBinary),
                $serverHost,
                $httpPort,
                escapeshellarg($serverScriptPath)
            );
        }

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

        // Open the browser-rendered QR page once the HTTP server is up.
        // Terminal-rendered QR codes are unreliable across font/terminal
        // combinations (line-height gaps in half-blocks, or oversized
        // full-block renderings that don't fit the visible viewport), so the
        // browser page at /jump/qr is the canonical scan target. The
        // terminal QR above is a best-effort fallback for headless/SSH use.
        if ($openQr) {
            for ($i = 0; $i < 50; $i++) {
                if ($this->isPortInUse($httpPort)) {
                    break;
                }
                usleep(100000); // 100ms; up to 5s total
            }
            $this->openBrowser($host, $httpPort);
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

        // Run in background (not Workerman daemon mode — it breaks the event loop).
        if (PHP_OS_FAMILY === 'Windows') {
            // `&` is a command separator on Windows (not "background"), and the
            // previous `pclose(popen("start /B ..."))` approach hangs: cmd.exe's
            // stdout pipe (created by popen) gets inherited by the grandchild
            // PHP via CreateProcess(bInheritHandles=TRUE) and the pipe never
            // sees EOF, so pclose blocks until the long-lived bridge exits.
            //
            // Use proc_open with explicit file handles (no inheritable pipes)
            // and bypass_shell so no cmd.exe sits in the middle. Intentionally
            // do NOT proc_close the resource — that would wait on the
            // long-running child. The OS process is independent and the PHP
            // resource is cleaned up at script shutdown.
            $cmd = sprintf(
                '%s %s %s %d %d %d start',
                escapeshellarg($phpBinary),
                escapeshellarg($serverPath),
                escapeshellarg(base_path()),
                $wsPort,
                $bridgePort,
                $viteProxyPort
            );
            $desc = [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', $logFile, 'a'],
                2 => ['file', $logFile, 'a'],
            ];
            // Keep the resource on the instance so its destructor doesn't fire
            // mid-command (proc_close blocks waiting for the long-lived child).
            // On Ctrl+C the PHP process is hard-terminated by Windows and the
            // bridge stays running, matching Mac/Linux behaviour.
            $this->bridgeProcess = @proc_open($cmd, $desc, $pipes, base_path(), null, ['bypass_shell' => true]);
        } else {
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
        }

        // Give it a moment to start
        usleep(500000);

        $this->components->twoColumnDetail('Bridge log', "tail -f {$logFile}");

        // On Windows, Workerman cannot start multiple Worker instances from
        // one PHP file (no fork() — the second Worker is silently dropped
        // with the "multi workers init in one php file are not support"
        // warning). websocket-server.php declares both JumpBridge (this
        // process) and JumpViteProxy, so on Windows the Vite HMR proxy
        // never binds and `npm run dev` file changes never reach the phone.
        // Launch the Vite HMR proxy as its own process to work around that.
        // macOS/Linux don't need this — fork in websocket-server.php starts
        // both Workers correctly.
        if (PHP_OS_FAMILY === 'Windows') {
            $this->startViteHmrProxyForWindows($viteProxyPort, $logFile);
        }
    }

    /**
     * Launch the standalone Vite HMR proxy Workerman process. Windows only —
     * see startBridgeServer for the multi-worker-in-one-file rationale.
     */
    private function startViteHmrProxyForWindows(int $viteProxyPort, string $logFile): void
    {
        $serverPath = __DIR__.'/../../resources/jump/vite-hmr-server.php';

        if (! file_exists($serverPath)) {
            $this->warn('Vite HMR proxy script not found, HMR will not work on Windows.');

            return;
        }

        $phpBinary = PHP_BINARY;
        $cmd = sprintf(
            '%s %s %s %d start',
            escapeshellarg($phpBinary),
            escapeshellarg($serverPath),
            escapeshellarg(base_path()),
            $viteProxyPort
        );
        $desc = [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];

        // Same proc_open pattern as the bridge: keep the resource alive on
        // the instance so its destructor doesn't proc_close (which would
        // block waiting on the long-lived child). The OS process is
        // independent and Windows hard-terminates everything on Ctrl+C.
        $this->viteHmrProcess = @proc_open($cmd, $desc, $pipes, base_path(), null, ['bypass_shell' => true]);

        usleep(500000);

        if ($this->isPortInUse($viteProxyPort)) {
            $this->components->twoColumnDetail('Vite HMR proxy', "ws://0.0.0.0:{$viteProxyPort}/ (windows: separate process)");
        } else {
            $this->warn("Vite HMR proxy did not bind to port {$viteProxyPort} — file changes will not hot-reload on the phone.");
        }
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

        // --no-reload is required on Windows/Herd: without it, Laravel's
        // ServeCommand strips most env vars from the spawned `php -S` child
        // (only a small allowlist survives), which on Windows can break PHP's
        // socket initialization and produces the opaque
        // "Failed to listen on 127.0.0.1:<port> (reason: ?)" error across
        // every port it tries. We're managing this server's lifecycle from
        // Jump anyway, so the env-reload watcher provides no value here.
        $cmd = sprintf(
            '%s %s serve --port=%d --host=127.0.0.1 --no-interaction --no-reload',
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

        // Warm Laravel before the phone arrives. PHP's first request is cold:
        // opcache empty, Wayfinder/Inertia/service-provider boot, autoload
        // scan — easily >30s on Windows + Herd for an Inertia app. The
        // router's curl proxy has a fixed transfer timeout, so a cold first
        // request lands in the phone as "Could not connect to Laravel on
        // port 8000". Pre-warming here trades a one-time delay during
        // startup (where it's expected) for a fast first scan.
        $this->warmLaravelServer($port);
    }

    /**
     * Issue a single throwaway GET / to the managed Laravel server so the
     * opcache, Inertia/Wayfinder bootstrap, and config caching are primed
     * before the device proxies its first request.
     */
    private function warmLaravelServer(int $port): void
    {
        if (! function_exists('curl_init')) {
            return;
        }

        $start = microtime(true);
        $ch = curl_init("http://127.0.0.1:{$port}/");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        // Identify ourselves so logs can attribute the warmup hit.
        curl_setopt($ch, CURLOPT_USERAGENT, 'NativePHP-Jump-Warmup/1.0');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $elapsed = round(microtime(true) - $start, 2);

        if ($response === false) {
            $this->components->twoColumnDetail(
                'Laravel warmup',
                "<fg=yellow>failed after {$elapsed}s: {$error}</>"
            );

            return;
        }

        $this->components->twoColumnDetail(
            'Laravel warmup',
            "<fg=green>ready in {$elapsed}s (HTTP {$httpCode})</>"
        );
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

            // High error correction is required when we pack the QR into
            // terminal half-blocks: font line-height variations on Windows
            // cmd/PowerShell can cause individual modules to be misread by
            // scanners. The QR still decodes "successfully" (it doesn't
            // checksum-fail), but the data is wrong, so the receiving app
            // gets a garbage host/port and hangs trying to connect. High EC
            // adds redundancy that fixes those flipped modules in-scanner.
            // Margin stays at 1 since the surrounding terminal background
            // gives the scanner additional effective quiet zone.
            $result = (new Builder(
                data: $qrData,
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: 300,
                margin: 1,
            ))->build();

            $matrix = $result->getMatrix();
            $size = $matrix->getBlockCount();

            $this->newLine();
            $this->line('  <fg=white;bg=black>Scan with your camera to open in Jump</>');
            $this->newLine();

            // Half-block packing: each terminal row carries TWO QR matrix
            // rows. ▀ = top module on, ▄ = bottom module on, █ = both on,
            // space = both off. Cuts the rendered height in half and gives
            // approximately square cells (most terminal cells are ~1:2
            // aspect, so 1 char wide × 0.5 char tall ≈ square).
            //
            // Caveat: depends on the font drawing half-blocks with no
            // vertical gap. Modern Windows Terminal (Cascadia Code) and most
            // monospace fonts on macOS/Linux handle this correctly. Older
            // cmd.exe with Consolas may leave a thin gap between rows.
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
            $browserHost = $host === '0.0.0.0' ? 'localhost' : $host;
            $browserUrl = "http://{$browserHost}:{$port}/jump/qr";
            $this->line("  <fg=yellow>Can't scan the QR code? Try it in the browser: <fg=cyan>{$browserUrl}</></>");
            $this->line('  <fg=gray>Use the --browser option to auto-open your default browser on future runs.</>');
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

    private function isPortInUse($port)
    {
        // Connect-test: does anything accept on this port right now?
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($connection) {
            fclose($connection);

            return true;
        }

        // Bind-test: catches ports held by a process that isn't accepting
        // (stuck/half-dead Windows servers, TIME_WAIT, bound-but-not-listening).
        // fsockopen alone missed these, which is why a previous run's artisan
        // serve on 8000 could fool us into picking 8000 again.
        $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        if ($socket === false) {
            return true;
        }
        fclose($socket);

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
