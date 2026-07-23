<?php

namespace Native\Mobile\Commands;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Illuminate\Console\Command;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Traits\PlatformFileOperations;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;

class JumpCommand extends Command
{
    use PlatformFileOperations;

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

    // Default 0 so a teardown reached before handle() assigns it (early
    // return-FAILURE, or Cmd+C during killExistingServers) doesn't fatal on an
    // uninitialized typed property; reapOwnedPorts/killListenersOnPort no-op on 0.
    private int $laravelPort = 0;

    private string $displayHost;

    private $laravelProcess = null;

    private array $laravelPipes = [];

    private $bridgeProcess = null;

    // Windows-only: separate process for the Vite HMR proxy because
    // Workerman can't fork two Workers from one file on Windows.
    private $viteHmrProcess = null;

    private bool $verbose = false;

    /** Handle to the mDNS/Bonjour advertiser subprocess (LAN discovery). */
    private $mdnsProcess = null;

    /** True when $mdnsProcess is our pure-PHP responder (needs stop-file teardown, not a hard kill). */
    private bool $mdnsIsPhpResponder = false;

    /** @var resource|null Router (php -S) proc_open handle. */
    private $routerProcess = null;

    /** @var list<int> Process-group leader PIDs of every grouped subtree we spawn. */
    private array $childLeaders = [];

    /** @var list<int> Direct child PIDs (== leaders when grouped) for the no-pcntl ps fallback. */
    private array $childPids = [];

    /** Re-entrancy guard: signal handler AND shutdown function both call the teardown. */
    private bool $shuttingDown = false;

    // Ports this run owns; 0 until chosen, so an early-startup signal reaps nothing spuriously.
    private int $httpPort = 0;

    private int $wsPort = 0;

    private int $bridgePort = 0;

    private int $viteProxyPort = 0;

    public function handle()
    {
        $this->verbose = $this->output->isVerbose();

        intro('NativePHP Jump Server');

        // WSL2's default NAT networking gives eth0 a virtual-switch address
        // (172.x) that a phone on the LAN cannot reach — the QR code would
        // encode an IP that silently hangs on scan. Mirrored networking mode
        // puts the real LAN IP on eth0, so only warn when NOT mirrored.
        if ($this->isRunningInWSL()) {
            $mode = trim((string) @shell_exec('wslinfo --networking-mode 2>/dev/null'));
            if ($mode !== 'mirrored') {
                $this->warn('WSL2 NAT networking detected — the auto-detected IP (172.x) is NOT reachable from your phone.');
                note(<<<'NOTE'
                    Scanning the QR code will hang unless you do one of the following:

                      - Enable mirrored networking: set networkingMode=mirrored under [wsl2]
                        in %UserProfile%\.wslconfig, then run `wsl --shutdown` (Windows 11).
                        Jump then works with no further setup.
                      - Set up `netsh interface portproxy` on Windows for the HTTP, WebSocket
                        and Vite-proxy ports, and pass your Windows LAN IP via --ip.
                      - Run `php artisan native:jump` from Windows directly instead of WSL.
                    NOTE);
            }
        }

        // Arm teardown BEFORE any spawn so a Cmd+C during the blocking startup
        // (port waits, the 120s Laravel warmup, the interactive IP prompt) is
        // honoured and tears the whole fleet down deterministically.
        $this->installSignalHandlers();
        register_shutdown_function([$this, 'shutdownEverything']);

        // Reclaim a prior run's leaked ports NOW (this call was dead code) so we
        // reuse 3000/8000/3001/3002/3003 instead of escalating to 3008/8002/…
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

        $this->httpPort = $httpPort;
        $this->wsPort = $wsPort;
        $this->bridgePort = $bridgePort;
        $this->viteProxyPort = $viteProxyPort;

        // Start or detect the Laravel dev server
        if ($this->option('no-serve')) {
            // User is running their own artisan serve — tell them what to export.
            // The VAR=value command prefix is POSIX-shell-only, so Windows gets
            // the PowerShell form (cmd.exe's `set X=v && …` embeds a trailing
            // space in the value).
            if (! $this->isPortInUse($this->laravelPort)) {
                $hint = PHP_OS_FAMILY === 'Windows'
                    ? "\$env:JUMP_BRIDGE_PORT={$bridgePort}; php artisan serve --port={$this->laravelPort}"
                    : "JUMP_BRIDGE_PORT={$bridgePort} php artisan serve --port={$this->laravelPort}";
                $this->warn("No server detected on port {$this->laravelPort}. Start one with: {$hint}");
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

        // Register this instance (PID + ports) so a later `native:jump` start
        // can distinguish this live server from a crashed one. Cleaned up on
        // exit — register_shutdown_function fires on normal return, exit() from
        // the signal handler, and fatals alike.
        $this->writeInstanceRegistry(); // now reads $this->* ports + childLeaders/childPids

        // Start PHP built-in server (serves QR page + proxies to Laravel)
        $this->startPhpServer($host, $httpPort, $openQr, $bridgePort, $wsPort, $viteProxyPort);

        return self::SUCCESS;
    }

    /**
     * Shim run as argv0: become a fresh session/group leader (macOS has no
     * `setsid` binary), then exec the real program so proc_get_status()['pid']
     * IS the group leader (pid === pgid). pcntl_exec does NOT search PATH, so
     * argv[1] must be an ABSOLUTE binary (all callers pass PHP_BINARY); its
     * args are argv[2..].
     */
    private function setsidShim(): string
    {
        return 'posix_setsid(); $a=$argv; pcntl_exec($a[1], array_slice($a, 2));';
    }

    /**
     * proc_open $argv as the leader of its own process group so the entire
     * subtree (php -S workers, artisan serve's php -S grandchild + its workers,
     * the Workerman master + workers) can be reaped by killing -pgid. Degrades
     * on Windows / pcntl-less builds to a shell-less array proc_open; teardown
     * then falls back to the ps-tree walk + port sweep.
     *
     * @param  list<string>  $argv  Absolute binary first, then its args.
     * @return array{0: resource|false, 1: int} [process, leaderPid] (0 = ungrouped)
     */
    private function spawnGroupLeader(array $argv, ?string $cwd, ?array $env, array $descriptors, ?array &$pipes): array
    {
        $canGroup = PHP_OS_FAMILY !== 'Windows'
            && function_exists('posix_setsid')
            && function_exists('pcntl_exec')
            && function_exists('posix_kill');

        $cmd = $canGroup
            ? array_merge([PHP_BINARY, '-d', 'error_reporting=0', '-r', $this->setsidShim()], $argv)
            : $argv; // array form bypasses /bin/sh on PHP 7.4+

        $process = @proc_open($cmd, $descriptors, $pipes, $cwd, $env);
        $pid = is_resource($process) ? (int) (@proc_get_status($process)['pid'] ?? 0) : 0;

        if ($pid > 0) {
            $this->childPids[] = $pid;               // always tracked (ps fallback)
            if ($canGroup) {
                $this->childLeaders[] = $pid;        // pid === pgid here
            }
        }

        return [$process, $canGroup ? $pid : 0];
    }

    /** Install async signal handlers before any child is spawned. No-op without pcntl. */
    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return; // Windows / no pcntl: shutdown fn + next-run killExistingServers are the net.
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true); // deliver mid-curl/mid-select/mid-usleep, not once per loop
        }
        $handler = function (int $signo) {
            $this->shutdownEverything();
            exit(128 + $signo);
        };
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGHUP, $handler); // closed terminal tab
    }

    /**
     * The single idempotent teardown — runs on SIGINT/SIGTERM/SIGHUP, normal
     * router exit, and register_shutdown_function (fatals / exit()).
     */
    public function shutdownEverything(): void
    {
        if ($this->shuttingDown) {
            return; // signal handler + shutdown function may both reach here
        }
        $this->shuttingDown = true;

        // Make teardown uninterruptible. With async signals armed, a second
        // Ctrl+C (common when a stop looks hung) would re-enter the handler and
        // exit() mid-kill — aborting the SIGTERM→SIGKILL escalation and the port
        // backstop, re-orphaning the very fleet we're killing. Ignore
        // terminating signals for the duration of the teardown.
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, SIG_IGN);
            pcntl_signal(SIGTERM, SIG_IGN);
            pcntl_signal(SIGHUP, SIG_IGN);
        }

        try {
            $this->newLine();
            $this->components->info('Shutting down...');
        } catch (\Throwable) {
            // tty gone (SIGHUP from a closed tab) — keep cleaning up silently
        }

        // Advertiser first: its graceful exit multicasts the mDNS goodbye packet.
        $this->stopAdvertiser();

        if (! empty($this->childLeaders)) {
            // PRIMARY (macOS/Linux): blast each spawned subtree by its group.
            foreach ($this->childLeaders as $leader) {
                $this->killGroup($leader);
            }
        } elseif (PHP_OS_FAMILY !== 'Windows' && ! empty($this->childPids)) {
            // FALLBACK (Unix without pcntl): snapshot + kill each tracked subtree.
            $this->terminateTrees($this->childPids);
        } elseif (PHP_OS_FAMILY === 'Windows') {
            foreach ($this->childPids as $pid) {
                @exec("taskkill /F /T /PID {$pid} 2>NUL"); // /T = whole tree
            }
        }

        // BACKSTOP A: proc_terminate + proc_close tracked handles. Kills the
        // ungrouped path and, crucially, reaps the now-dead group-leader zombies.
        $this->stopLaravelServer();
        foreach (['routerProcess', 'bridgeProcess', 'viteHmrProcess'] as $prop) {
            if (is_resource($this->{$prop})) {
                @proc_terminate($this->{$prop});
                @proc_close($this->{$prop});
                $this->{$prop} = null;
            }
        }

        // BACKSTOP B: identity-checked port sweep for anything that still slipped
        // (respects --no-serve; killListenersOnPort no-ops on port 0).
        $this->reapOwnedPorts($this->httpPort, $this->wsPort, $this->bridgePort, $this->viteProxyPort);

        $this->removeInstanceRegistry();
    }

    /** SIGTERM then SIGKILL an entire process group by its leader PID. */
    private function killGroup(int $leaderPid): void
    {
        if ($leaderPid <= 0 || ! function_exists('posix_kill')) {
            return;
        }
        @posix_kill(-$leaderPid, SIGTERM);                 // negative PID => whole group
        for ($i = 0; $i < 15 && $this->isPidAlive($leaderPid); $i++) {
            usleep(100_000);                               // up to ~1.5s graceful
        }
        @posix_kill(-$leaderPid, SIGKILL);
    }

    /**
     * Identity-checked group kill for a leader PID read from ANOTHER run's
     * registry file. That PID belongs to a since-dead process, so the OS may
     * have recycled it into an unrelated process group — an unguarded
     * killGroup() would then blast that innocent group. Only kill when the
     * leader still looks like one of our Jump processes. (Current-run leaders in
     * shutdownEverything() are trusted and skip this check.)
     */
    private function killGroupIfOwned(int $leaderPid): void
    {
        if ($leaderPid <= 0 || ! $this->isPidAlive($leaderPid)) {
            return;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            $command = trim((string) @shell_exec('ps -p '.$leaderPid.' -o command= 2>/dev/null'));
            $ppid = (int) trim((string) @shell_exec('ps -p '.$leaderPid.' -o ppid= 2>/dev/null'));
            if ($command === '' || ! $this->isJumpOwnedProcess($command, $ppid, $leaderPid)) {
                return; // recycled or not ours — never group-kill a stranger
            }
        }
        $this->killGroup($leaderPid);
    }

    /** No-pcntl fallback: snapshot each root's descendants, then SIGTERM→SIGKILL. */
    private function terminateTrees(array $rootPids): void
    {
        $tree = $this->buildProcessTree();
        $targets = [];
        $me = getmypid();
        foreach ($rootPids as $pid) {
            $targets[$pid] = true;
            foreach ($this->descendantsOf($tree, $pid) as $d) {
                $targets[$d] = true;
            }
        }
        unset($targets[$me]);
        $targets = array_keys($targets);

        foreach ($targets as $pid) {
            $this->signalPid($pid, 15);
        }
        for ($i = 0; $i < 20; $i++) {
            $targets = array_values(array_filter($targets, fn ($p) => $this->isPidAlive($p)));
            if (! $targets) {
                return;
            }
            usleep(100_000);
        }
        foreach ($targets as $pid) {
            $this->signalPid($pid, 9);
        }
    }

    /** Snapshot the whole process table once: ppid -> [child pids]. */
    private function buildProcessTree(): array
    {
        $tree = [];
        $ps = (string) @shell_exec('ps -Ao pid=,ppid= 2>/dev/null');
        foreach (preg_split('/\n/', trim($ps)) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)/', $line, $m)) {
                $tree[(int) $m[2]][] = (int) $m[1];
            }
        }

        return $tree;
    }

    /** BFS all descendants of $pid from a pre-built tree. */
    private function descendantsOf(array $tree, int $pid): array
    {
        $out = [];
        $stack = [$pid];
        while ($stack) {
            foreach ($tree[array_pop($stack)] ?? [] as $c) {
                if (! isset($out[$c])) {
                    $out[$c] = true;
                    $stack[] = $c;
                }
            }
        }

        return array_keys($out);
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

        // Detect how Jump should render this app. If the start route is a
        // Route::native screen the app is native-ui (streams Element.* frames
        // over the WS bridge); otherwise it's a classic WebView app (Blade /
        // Livewire / Inertia over HTTP) that the client renders by forwarding
        // its HTTP responses. Surfaced to the client via /jump/info's `ui`.
        $startUrl = config('nativephp.start_url') ?: '/';
        $appUi = NativeRouter::isNativeRoute($startUrl) ? 'native-ui' : 'webview';

        // Build environment variables for the router
        $env = [
            'JUMP_DISPLAY_HOST' => $this->displayHost,
            'JUMP_HTTP_PORT' => (string) $httpPort,
            'JUMP_LARAVEL_PORT' => (string) $this->laravelPort,
            'JUMP_BRIDGE_PORT' => (string) $bridgePort,
            'JUMP_WS_PORT' => (string) $wsPort,
            'JUMP_APP_UI' => $appUi,
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

        if ($useWorkerman) {
            // Windows: stream_set_blocking() has no effect on proc_open pipes
            // (PHP bugs #51800/#65650), so the main loop's fgets() would block
            // forever once the Workerman proxy goes quiet — freezing log relay,
            // the liveness check AND the artisan-serve pipe drain. Log to a
            // file instead (same pattern as startBridgeServer).
            $logDir = base_path('storage/logs');
            if (! is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $httpLogFile = $logDir.'/jump-http.log';
            $descriptorSpec = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['file', $httpLogFile, 'a'],
                2 => ['file', $httpLogFile, 'a'],
            ];
        } else {
            $descriptorSpec = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ];
        }

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
            $this->components->twoColumnDetail('HTTP proxy log', "powershell -Command Get-Content -Path '{$httpLogFile}' -Tail 20 -Wait");
        } else {
            $cmd = sprintf(
                '%s -S %s:%d %s',
                escapeshellarg($phpBinary),
                $serverHost,
                $httpPort,
                escapeshellarg($serverScriptPath)
            );
        }

        if ($useWorkerman) {
            // Windows: unchanged string-cmd proc_open (bypass not needed here).
            $process = proc_open($cmd, $descriptorSpec, $pipes, base_path(), $fullEnv);
        } else {
            $serverArgv = [$phpBinary, '-S', "{$serverHost}:{$httpPort}", $serverScriptPath];
            [$process] = $this->spawnGroupLeader($serverArgv, base_path(), $fullEnv, $descriptorSpec, $pipes);
        }

        if (! is_resource($process)) {
            $this->error('Failed to start PHP server');

            return;
        }
        $this->routerProcess = $process;

        // Router leader now known — refresh the registry so a hard `kill -9` of
        // the parent is still recoverable by group next run.
        $this->writeInstanceRegistry();

        // Set pipes to non-blocking. Windows has no stdout/stderr pipes here
        // (file descriptors above) — and stream_set_blocking wouldn't work on
        // them anyway (PHP bugs #51800/#65650).
        if (! $useWorkerman) {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
        }

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
            if (! $this->isPortInUse($httpPort)) {
                $this->warn("Server did not start on port {$httpPort} — not advertising on the network.");
            } elseif (PHP_OS_FAMILY !== 'Windows') {
                // Windows falls back to the pure-PHP responder, which warns for
                // itself if it can't bind — so only warn here on macOS/Linux.
                $this->warn('LAN discovery unavailable (no dns-sd/avahi) — use the QR code.');
            }
        }

        // Signal handlers and the advertiser/registry shutdown functions are
        // installed once in handle() (before any spawn) and all funnel through
        // shutdownEverything(); nothing to wire up per-process here.

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

            // Relay server output. Windows skips this: its stdout/stderr go to
            // jump-http.log (non-blocking pipes are impossible there and a
            // blocking fgets() would wedge the whole loop — see descriptor spec).
            if (! $useWorkerman) {
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

            // Small sleep to prevent CPU spinning. Windows sleeps longer — the
            // loop is only a proc_get_status liveness check there (no log relay).
            usleep($useWorkerman ? 200000 : 10000); // 200ms / 10ms
        }

        // Router died on its own (crash / external kill) — same idempotent teardown.
        $this->shutdownEverything();
    }

    /**
     * Windows fallback advertiser: spawn the pure-PHP DNS-SD responder
     * (resources/jump/mdns-server.php) when no Bonjour dns-sd.exe is present.
     * Stored on $this->mdnsProcess so the existing stopAdvertiser() teardown
     * reaps it unchanged. Best-effort — if it can't bind UDP 5353 the QR still
     * works, so we only warn.
     */
    private function startPhpMdnsResponder(int $httpPort, string $ip, string $label): void
    {
        $serverPath = __DIR__.'/../../resources/jump/mdns-server.php';
        if (! file_exists($serverPath)) {
            return;
        }

        $logDir = base_path('storage/logs');
        if (! is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir.'/jump-mdns.log';

        // Pass our own PID so the responder can watch it: if Jump is hard-killed
        // or crashes (no graceful stopAdvertiser), the responder notices the
        // parent is gone and multicasts its own goodbye instead of lingering.
        $cmd = sprintf(
            '%s %s %s %s %d %s %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($serverPath),
            escapeshellarg(base_path()),
            escapeshellarg($label),
            $httpPort,
            escapeshellarg($ip),
            getmypid() ?: 0,
        );
        $desc = [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];

        // bypass_shell so stopAdvertiser()'s proc_terminate() signals php.exe
        // itself (not a wrapping cmd.exe), matching the bridge/vite spawns.
        $proc = @proc_open($cmd, $desc, $pipes, base_path(), null, ['bypass_shell' => true]);
        if (! is_resource($proc)) {
            return;
        }

        // Give it a beat to bind (or fail on a missing ext-sockets / busy port).
        usleep(400000);
        if (! (@proc_get_status($proc)['running'] ?? false)) {
            proc_close($proc);
            $this->warn('LAN discovery unavailable — the pure-PHP mDNS responder exited (see storage/logs/jump-mdns.log). Use the QR code.');

            return;
        }

        $this->mdnsProcess = $proc;
        $this->mdnsIsPhpResponder = true;
        if (($pid = (int) (@proc_get_status($proc)['pid'] ?? 0)) > 0) {
            $this->childPids[] = $pid;
        }
        $this->components->info("Discoverable on this network as \"{$label}\" — open the app to connect without scanning.");
    }

    /**
     * Terminate the mDNS/Bonjour advertiser subprocess. When `dns-sd -R`
     * exits, mDNSResponder deregisters the service and multicasts goodbye
     * packets, so browsing devices drop the entry within seconds instead of
     * caching it for the record TTL (up to 75 minutes).
     */
    public function stopAdvertiser(): void
    {
        if (! is_resource($this->mdnsProcess)) {
            return;
        }

        if ($this->mdnsIsPhpResponder) {
            // The pure-PHP responder can't catch proc_terminate() (a hard
            // TerminateProcess on Windows), so a plain kill would skip the
            // DNS-SD goodbye and leave a phantom "server nearby" in the app for
            // the record TTL. Instead drop the stop-file it polls: it multicasts
            // the goodbye (TTL-0) and exits on its own. proc_terminate stays as a
            // backstop only if it doesn't exit within the grace window.
            $stopFile = base_path('storage/framework/jump-mdns.stop');
            @touch($stopFile);
            for ($i = 0; $i < 15; $i++) { // up to ~1.5s
                if (! (@proc_get_status($this->mdnsProcess)['running'] ?? false)) {
                    break;
                }
                usleep(100000);
            }
            @unlink($stopFile);
            if (@proc_get_status($this->mdnsProcess)['running'] ?? false) {
                @proc_terminate($this->mdnsProcess);
            }
            @proc_close($this->mdnsProcess);
            $this->mdnsProcess = null;
            $this->mdnsIsPhpResponder = false;

            return;
        }

        proc_terminate($this->mdnsProcess);
        proc_close($this->mdnsProcess);
        $this->mdnsProcess = null;
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
            // Track the PID so shutdownEverything()'s `taskkill /F /T` reaps the
            // Workerman master AND its JumpBridge/JumpViteProxy worker tree —
            // proc_terminate() alone (backstop A) only signals the master.
            $bpid = is_resource($this->bridgeProcess) ? (int) (@proc_get_status($this->bridgeProcess)['pid'] ?? 0) : 0;
            if ($bpid > 0) {
                $this->childPids[] = $bpid;
            }
        } else {
            // Was: exec("php … start >> log 2>&1 &"). The trailing & reparented
            // the Workerman master to PID 1 at birth, untracked and unsignalable.
            // Spawn it as its OWN process-group leader so killGroup() reaps the
            // master + JumpBridge + JumpViteProxy workers together.
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $logFile, 'a'],
                2 => ['file', $logFile, 'a'],
            ];
            $argv = [$phpBinary, $serverPath, base_path(), (string) $wsPort, (string) $bridgePort, (string) $viteProxyPort, 'start'];
            [$proc] = $this->spawnGroupLeader($argv, base_path(), null, $descriptors, $pipes);
            // Track the handle so backstop A can proc_terminate/proc_close it.
            // Never proc_close during the run (blocks on the long-lived child).
            $this->bridgeProcess = is_resource($proc) ? $proc : null;
        }

        // Give it a moment to start
        usleep(500000);

        // `tail` doesn't exist on stock Windows — show a paste-able PowerShell
        // equivalent there (works from cmd.exe or an existing PS session).
        $tailHint = PHP_OS_FAMILY === 'Windows'
            ? "powershell -Command Get-Content -Path '{$logFile}' -Tail 20 -Wait"
            : "tail -f {$logFile}";
        $this->components->twoColumnDetail('Bridge log', $tailHint);

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
        // Track the PID so `taskkill /F /T` reaps the Workerman tree on shutdown.
        $vpid = is_resource($this->viteHmrProcess) ? (int) (@proc_get_status($this->viteHmrProcess)['pid'] ?? 0) : 0;
        if ($vpid > 0) {
            $this->childPids[] = $vpid;
        }

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

        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: pipes can't be made non-blocking (PHP bugs #51800/#65650)
            // and nothing drains them once the main loop stalls — artisan serve
            // logs a line per request, so a full 4KB anonymous pipe would block
            // the Laravel server mid-session. Log to a file instead; the main
            // loop's drain self-disables via its is_resource() guards.
            $logDir = base_path('storage/logs');
            if (! is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $laravelLogFile = $logDir.'/jump-laravel.log';
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['file', $laravelLogFile, 'a'],
                2 => ['file', $laravelLogFile, 'a'],
            ];
        } else {
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
        }

        // --no-reload is REQUIRED for two reasons:
        //  1. It lets artisan serve honour PHP_CLI_SERVER_WORKERS — Laravel only
        //     spins up the multi-worker built-in server with that flag; otherwise
        //     it warns and falls back to a single worker, re-introducing the
        //     native-runloop starvation.
        //  2. On Windows/Herd, without it Laravel's ServeCommand strips most env
        //     vars from the spawned `php -S` child (only a small allowlist
        //     survives), which can break PHP's socket initialization and produce
        //     the opaque "Failed to listen on 127.0.0.1:<port> (reason: ?)" error
        //     across every port it tries.
        // Jump runs its own file watcher in websocket-server.php, so artisan
        // serve's built-in .env reload is redundant here anyway.
        $serveArgv = [
            $phpBinary, $artisan, 'serve',
            "--port={$port}", '--host=127.0.0.1', '--no-interaction', '--no-reload',
        ];

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

        [$this->laravelProcess] = $this->spawnGroupLeader($serveArgv, base_path(), $env, $descriptorSpec, $this->laravelPipes);

        if (! is_resource($this->laravelProcess)) {
            $this->error('Failed to start artisan serve');

            return;
        }

        // Set pipes to non-blocking so we don't hang. On Windows stdout/stderr
        // are file descriptors (see above) — only stdin exists as a pipe.
        if (PHP_OS_FAMILY !== 'Windows') {
            stream_set_blocking($this->laravelPipes[1], false);
            stream_set_blocking($this->laravelPipes[2], false);
        }
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

        // Which path to warm on. For a native-UI app, `GET /` runs the element
        // runloop, which BLOCKS for the whole lifetime of the screen — the same
        // property that makes PHP_CLI_SERVER_WORKERS necessary. Warming it means
        // the request never returns and every single start burns the full 120s
        // timeout before continuing:
        //
        //   Laravel warmup  failed after 120s: Operation timed out … 0 bytes received
        //
        // Any request boots the framework (autoload, providers, config, opcache),
        // which is what warming is actually for, so native-UI apps are warmed on
        // a deliberately unrouted path: full bootstrap, 404, no runloop. WebView
        // apps keep warming `/`, where compiling the actual page is the point.
        $startUrl = config('nativephp.start_url') ?: '/';
        $warmPath = NativeRouter::isNativeRoute($startUrl) ? '/__nativephp_warmup' : '/';

        $start = microtime(true);
        $ch = curl_init("http://127.0.0.1:{$port}{$warmPath}");
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

        // A 404 is the expected, healthy result on the native-UI warm path.
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
            $this->line('  <fg=green>iOS</>  Scan with Camera app or Jump app');
            $this->line('  <fg=blue>Android</>  Scan from within the Jump app');
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

        // `command -v` is a POSIX-shell builtin — on Windows shell_exec runs via
        // cmd.exe, where it fails (and /dev/null is a bad redirect target), so
        // resolution always came up empty even with Bonjour's dns-sd.exe
        // installed. Use `where` there (first line of output); avahi is
        // unix-only so skip that probe. dns-sd.exe accepts the identical
        // `-R <name> _jump._tcp local <port> <txt…>` syntax.
        if (PHP_OS_FAMILY === 'Windows') {
            $dnssd = trim((string) strtok((string) @shell_exec('where dns-sd 2>NUL'), "\r\n"));
            $avahi = '';
        } else {
            $dnssd = trim((string) @shell_exec('command -v dns-sd 2>/dev/null'));
            $avahi = $dnssd === '' ? trim((string) @shell_exec('command -v avahi-publish-service 2>/dev/null')) : '';
        }

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
        } elseif (PHP_OS_FAMILY === 'Windows') {
            // No Bonjour dns-sd.exe on this Windows box — fall back to our
            // self-contained pure-PHP DNS-SD responder so LAN discovery still
            // works without asking the user to install Bonjour. It advertises
            // the identical _jump._tcp record (host/port/name) the QR encodes.
            $this->startPhpMdnsResponder($httpPort, $ip, $label);

            return;
        } else {
            return; // no advertiser on this platform — QR-only, no harm
        }

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['file', $null, 'w'],
            2 => ['file', $null, 'w'],
        ];
        // bypass_shell on Windows (same pattern as startBridgeServer) so
        // stopAdvertiser()'s proc_terminate() signals dns-sd.exe itself — not a
        // wrapping cmd.exe — preserving the goodbye-packet deregistration.
        $proc = PHP_OS_FAMILY === 'Windows'
            ? @proc_open($cmd, $spec, $pipes, base_path(), null, ['bypass_shell' => true])
            : @proc_open($cmd, $spec, $pipes);
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
            // Backgrounding with `&` makes the shell exit 0 whether or not the
            // opener exists, so probe availability first instead of trusting
            // the exit code. The redirect + & stay: they keep a slow opener
            // from blocking the serve loop.
            foreach (['xdg-open', 'sensible-browser', 'x-www-browser'] as $bin) {
                $path = trim((string) @shell_exec('command -v '.escapeshellarg($bin).' 2>/dev/null'));
                if ($path !== '') {
                    exec(escapeshellarg($path).' '.escapeshellarg($url).' > /dev/null 2>&1 &');

                    return;
                }
            }
            $this->components->warn("No browser opener found (xdg-open/sensible-browser/x-www-browser). Open {$url} manually.");
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
            // Kill leftover jump servers from a prior run. Match ALL our scripts
            // (router + Workerman bridge + Windows vite-hmr proxy), not just the
            // router, and tree-kill (/T) so php -S workers and Workerman worker
            // processes are reaped too — otherwise ws/bridge/vite ports escalate.
            // Match by the package path fragment WITH FORWARD SLASHES — the
            // spawned command lines embed __DIR__.'/../../resources/jump/…'
            // verbatim (no realpath), so the tail keeps '/'. Bare filenames
            // ('router.php') would tree-kill unrelated `php -S … router.php`
            // dev servers. Also scope to THIS project — base_path() is an
            // argument of every jump child — so live siblings serving other
            // projects survive, matching the Unix branch's multi-project
            // behaviour. http-server.php is the Windows HTTP proxy (was
            // never reaped before).
            $pids = [];
            $base = base_path();
            $wqlBase = str_replace('\\', '\\\\', $base); // backslashes must be doubled in WQL
            $needles = [
                'resources/jump/router.php',
                'resources/jump/http-server.php',
                'resources/jump/websocket-server.php',
                'resources/jump/vite-hmr-server.php',
            ];
            foreach ($needles as $needle) {
                $output = shell_exec('wmic process where "commandline like \'%'.$needle.'%\' and commandline like \'%'.$wqlBase.'%\'" get processid 2>NUL');
                if (! $output) {
                    $output = shell_exec('powershell -Command "Get-WmiObject Win32_Process | Where-Object { $_.CommandLine -like \'*'.$needle.'*\' -and $_.CommandLine -like \'*'.$base.'*\' } | Select-Object -ExpandProperty ProcessId" 2>NUL');
                }
                if ($output) {
                    foreach (preg_split('/\s+/', trim($output)) as $pid) {
                        if (is_numeric($pid) && (int) $pid !== $currentPid && (int) $pid > 0) {
                            $pids[(int) $pid] = true; // dedupe across patterns
                        }
                    }
                }
            }
            $pids = array_keys($pids);

            if (count($pids) > 0) {
                $this->components->task('Cleaning up '.count($pids).' existing server(s)', function () use ($pids) {
                    foreach ($pids as $pid) {
                        exec("taskkill /F /T /PID {$pid} 2>NUL"); // /T = whole tree
                    }
                    usleep(500000);

                    return true;
                });
            }
        } else {
            // Unix: reap only DEAD jump instances (and orphaned advertisers),
            // leaving any live sibling server running. This is what lets you
            // serve multiple projects at once on different ports — each fresh
            // start auto-finds free ports (findAvailablePort) and only cleans up
            // the leftovers of runs whose master process is gone.
            $this->cleanupDeadInstances();

            // A live sibling serving THIS project is different: the newest
            // invocation should own the session (otherwise a forgotten
            // terminal tab holds 3000-3003 forever and every new run walks
            // up through 3004+, breaking the ports the device already knows).
            $this->takeOverSameProjectInstances();
        }
    }

    /**
     * Stop any LIVE jump instance serving this same project so this run can
     * claim the canonical ports. Graceful first — SIGINT lets the sibling's
     * own shutdown handler stop its Laravel server, advertiser, bridge and
     * registry entry — with a force-reap fallback if it doesn't die in time.
     * Instances of OTHER projects are untouched.
     */
    private function takeOverSameProjectInstances(): void
    {
        foreach (glob($this->jumpRegistryDir().'/*.json') ?: [] as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (! is_array($data)) {
                continue;
            }

            $pid = (int) ($data['master_pid'] ?? 0);
            $project = $data['project'] ?? null;

            if ($project !== base_path() || $pid <= 0 || $pid === getmypid() || ! $this->isPidAlive($pid)) {
                continue;
            }

            $this->components->task("Stopping previous Jump for this project (pid {$pid})", function () use ($pid, $file, $data) {
                $this->signalPid($pid, 2); // SIGINT — run its graceful shutdown

                // Give its shutdown handler up to 5s to clean up after itself.
                for ($i = 0; $i < 50 && $this->isPidAlive($pid); $i++) {
                    usleep(100_000);
                }

                if ($this->isPidAlive($pid)) {
                    $this->signalPid($pid, 9); // SIGKILL
                    foreach ((array) ($data['group_leaders'] ?? []) as $leader) {
                        $this->killGroupIfOwned((int) $leader); // registry PID may be recycled
                    }
                    foreach (['http_port', 'laravel_port', 'ws_port', 'bridge_port', 'vite_port'] as $key) {
                        if (! empty($data[$key])) {
                            $this->killListenersOnPort((int) $data[$key]);
                        }
                    }
                }

                @unlink($file);

                return true;
            });
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
    private function writeInstanceRegistry(): void
    {
        @file_put_contents($this->instanceRegistryFile(), json_encode([
            'master_pid' => getmypid(),
            'project' => base_path(),
            'http_port' => $this->httpPort,
            'laravel_port' => $this->laravelPort,
            'ws_port' => $this->wsPort,
            'bridge_port' => $this->bridgePort,
            'vite_port' => $this->viteProxyPort,
            'group_leaders' => array_values($this->childLeaders),
            'child_pids' => array_values($this->childPids),
        ]));
    }

    public function removeInstanceRegistry(): void
    {
        @unlink($this->instanceRegistryFile());
    }

    /** Send a signal by number, with or without the posix extension. */
    private function signalPid(int $pid, int $signal): void
    {
        if ($pid <= 0) {
            return;
        }
        if (function_exists('posix_kill')) {
            @posix_kill($pid, $signal);
        } else {
            @exec('kill -'.$signal.' '.$pid.' 2>/dev/null');
        }
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
        $pids = $out === '' ? [] : preg_split('/\s+/', $out);

        // lsof isn't installed on minimal Debian/Ubuntu, many Docker images and
        // some WSL distros — without a fallback the reap silently no-ops and
        // every restart escalates to higher ports. Fall back to ss on Linux
        // only (macOS always ships lsof and has no ss). `ss -p` only emits
        // pid= for same-user sockets — exactly the jump-owned set — and every
        // PID found still passes through the identity gate below.
        if (empty($pids) && PHP_OS_FAMILY === 'Linux') {
            $ssOut = (string) @shell_exec('ss -ltnpH "sport = :'.$port.'" 2>/dev/null');
            if (preg_match_all('/pid=(\d+)/', $ssOut, $m)) {
                $pids = $m[1];
            }
        }

        if (empty($pids)) {
            return;
        }
        foreach (array_unique($pids) as $pid) {
            if (! is_numeric($pid) || (int) $pid === getmypid()) {
                continue;
            }

            $info = trim((string) @shell_exec('ps -p '.(int) $pid.' -o ppid=,command= 2>/dev/null'));
            if ($info === '' || ! preg_match('/^\s*(\d+)\s+(.*)$/s', $info, $m)) {
                continue;
            }
            $ppid = (int) $m[1];
            $command = trim($m[2]);

            if (! $this->isJumpOwnedProcess($command, $ppid, (int) $pid)) {
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
    private function isJumpOwnedProcess(string $command, int $ppid, int $pid = 0): bool
    {
        if (str_contains($command, 'resources/jump/router.php')
            || str_contains($command, 'resources/jump/websocket-server.php')
            || str_contains($command, '_jump._tcp')) {
            return true;
        }

        // The bridge server runs under Workerman, which REWRITES its process
        // title (`WorkerMan: worker process JumpBridge websocket://…`,
        // `… JumpViteProxy …`) — the original websocket-server.php command
        // line is not visible in ps output. Match any Workerman process whose
        // worker name carries our Jump prefix (JumpBridge, JumpViteProxy, …).
        if (str_contains($command, 'WorkerMan') && str_contains($command, 'Jump')) {
            return true;
        }

        if (preg_match('/artisan[\'"]? serve/', $command) && str_contains($command, '--no-reload')) {
            return true;
        }

        // The managed Laravel dev server. `artisan serve` execs
        // `php -S <host>:<port> …/Foundation/resources/server.php`, and with
        // PHP_CLI_SERVER_WORKERS that master forks a worker per slot. NONE of
        // them carry "artisan serve" in their command line, and their ppid is
        // the master (or the serve process) — not 1 — so the ppid===1 test below
        // rejected all ~11 of them and left port 8000 held forever:
        //
        //   Port 8000 is held by an unrelated process (pid 2000) — leaving it alone.
        //   … once per worker, every shutdown
        //
        // startLaravelServer() exports JUMP_BRIDGE_PORT into that server's
        // environment and the forked workers inherit it, so it is a definitive
        // marker: it distinguishes our server from a plain `php artisan serve`
        // the user started themselves, which must never be killed.
        if ($pid > 0
            && preg_match('/php[^ ]* -S \S+:\d+/', $command)
            && str_contains($command, 'server.php')
            && str_contains($this->processEnvironment($pid), 'JUMP_BRIDGE_PORT=')) {
            return true;
        }

        // Fallback for when the environment isn't readable (Windows, or a
        // process we can't inspect): an orphaned `php -S` on loopback.
        if ($ppid === 1
            && preg_match('/php[^ ]* -S 127\.0\.0\.1:\d+/', $command)
            && str_contains($command, 'server.php')) {
            return true;
        }

        return false;
    }

    /**
     * A process's environment as a flat string, for identity checks. Empty when
     * it can't be read — callers must treat that as "unknown", never as "ours".
     */
    private function processEnvironment(int $pid): string
    {
        if ($pid <= 0) {
            return '';
        }

        // Linux: /proc is authoritative and NUL-separated.
        if (PHP_OS_FAMILY === 'Linux' && @is_readable("/proc/{$pid}/environ")) {
            return str_replace("\0", ' ', (string) @file_get_contents("/proc/{$pid}/environ"));
        }

        // macOS: `ps -E` appends the environment after the command line. Only
        // works for our own processes, which is exactly the case we care about.
        if (PHP_OS_FAMILY === 'Darwin') {
            return (string) @shell_exec('ps -Ewww -p '.$pid.' 2>/dev/null');
        }

        return '';
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

            foreach ((array) ($data['group_leaders'] ?? []) as $leader) {
                $this->killGroupIfOwned((int) $leader); // registry PID may be recycled
            }

            foreach ($portKeys as $key) {
                $port = (int) ($data[$key] ?? 0);
                if ($port > 0 && ! isset($livePorts[$port])) {
                    $this->killListenersOnPort($port);
                }
            }
            @unlink($file);
        }

        // Belt-and-suspenders: reap jump-owned orphans holding the DEFAULT ports
        // that left NO registry entry — e.g. spawned by a pre-fix build, or a run
        // whose registry file was removed on a partial shutdown. This is the case
        // the registry-driven sweep above cannot see. killListenersOnPort is
        // identity-gated (only kills a process whose command matches a jump
        // server), and we skip any port a LIVE sibling owns — so it never touches
        // an unrelated service (e.g. `php artisan boost:mcp` on 3002) or a running
        // project. Covers the router (3000) + artisan serve (8000) + bridge/ws +
        // vite-proxy default ports.
        foreach ([3000, 8000, 3001, 3002, 3003] as $port) {
            if (! isset($livePorts[$port])) {
                $this->killListenersOnPort($port);
            }
        }

        $this->killOrphanedAdvertisers();
        $this->killOrphanedJumpProcesses();
    }

    /**
     * Reap jump-owned processes orphaned to launchd/init (PPID 1) — chiefly the
     * Workerman bridge/vite MASTERS, which hold no listening port (so the port
     * sweep can't see them, and killing a worker just makes the master re-fork).
     * A LIVE server's master is a child of its running native:jump (PPID != 1),
     * so PPID 1 reliably means "owner gone" and never touches a running project.
     * Identity-checked via isJumpOwnedProcess so only our own processes die.
     */
    private function killOrphanedJumpProcesses(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return; // Windows path handles its own reclaim in killExistingServers()
        }
        $ps = (string) @shell_exec('ps -Ao pid,ppid,command 2>/dev/null');
        foreach (preg_split('/\n/', $ps) as $line) {
            if (! preg_match('/^\s*(\d+)\s+(\d+)\s+(.*)$/', $line, $m)) {
                continue;
            }
            $pid = (int) $m[1];
            $ppid = (int) $m[2];
            $command = trim($m[3]);
            if ($pid === getmypid() || $ppid !== 1) {
                continue; // only reap processes orphaned to init
            }
            if ($this->isJumpOwnedProcess($command, $ppid, $pid)) {
                @exec('kill -9 '.$pid.' 2>/dev/null');
            }
        }
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
