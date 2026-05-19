<?php

/**
 * Jump HTTP Proxy Server (Workerman-based)
 *
 * Functional twin of `router.php` but built on Workerman instead of PHP's
 * built-in `php -S` server. Used on Windows where `php -S` exhibits a
 * dead-socket pathology: the phone WebView holds HTTP/1.1 keep-alive
 * connections open after page load; PHP -S has no SO_KEEPALIVE on its
 * listen socket, so when the phone goes away without a clean FIN, those
 * sockets stay Established for Windows' default 2-hour TCP keepalive
 * window. Each one consumes a select() slot, and once enough pile up the
 * single-threaded router stops accepting new requests entirely — browser
 * visits and subsequent phone scans both hang.
 *
 * Workerman gives us:
 *   - Proper connection lifecycle (we $connection->close() after each
 *     response, so the dead-peer case can't accumulate state).
 *   - A real event loop that keeps accepting new connections while
 *     individual requests are processed.
 *   - Stable behaviour under the parallel-asset fan-out that Vite HMR
 *     produces during dev mode.
 *
 * Routing logic (path matchers, header forwarding, URL rewriting, Vite
 * client patching, Set-Cookie multiplexing, etc.) is intentionally a
 * line-for-line port from router.php so the two stay behaviourally
 * identical for any path. router.php remains the implementation on
 * macOS/Linux where `php -S` doesn't hit the dead-socket bug.
 *
 * Usage:
 *   php http-server.php <base_path> <listen_host> <http_port> start [-d]
 *
 * Environment (passed by JumpCommand):
 *   JUMP_DISPLAY_HOST, JUMP_HTTP_PORT, JUMP_LARAVEL_PORT, JUMP_BRIDGE_PORT,
 *   JUMP_WS_PORT, JUMP_VITE_PORT, JUMP_VITE_PROXY_PORT, JUMP_BASE_PATH,
 *   APP_NAME
 */

declare(strict_types=1);

use Endroid\QrCode\Builder\Builder;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

// --- Argument + environment parsing -------------------------------------

// Mirror websocket-server.php's positional-arg pattern so Workerman's
// `start`/`-d` tokens don't get treated as values.
$args = array_slice($argv, 1);
$positional = [];
foreach ($args as $arg) {
    if (in_array($arg, ['start', 'stop', 'restart', '-d', '-g'], true)) {
        continue;
    }
    $positional[] = $arg;
}

$basePath = $positional[0] ?? getenv('JUMP_BASE_PATH') ?: null;
$listenHost = $positional[1] ?? '0.0.0.0';
$httpPort = (int) ($positional[2] ?? getenv('JUMP_HTTP_PORT') ?: 3000);

if (! $basePath || ! file_exists($basePath.'/vendor/autoload.php')) {
    fwrite(STDERR, "[Jump] http-server.php: base_path not provided or vendor/autoload.php missing\n");
    exit(1);
}

require_once $basePath.'/vendor/autoload.php';

// Shared globals read from the environment. Captured once at process start
// (these are set by JumpCommand and don't change for the life of the run).
$JUMP = [
    'basePath' => $basePath,
    'displayHost' => getenv('JUMP_DISPLAY_HOST') ?: 'localhost',
    'httpPort' => $httpPort,
    'laravelPort' => (int) (getenv('JUMP_LARAVEL_PORT') ?: 8000),
    'bridgePort' => (int) (getenv('JUMP_BRIDGE_PORT') ?: 3002),
    'wsPort' => (int) (getenv('JUMP_WS_PORT') ?: 3001),
    'vitePort' => (int) (getenv('JUMP_VITE_PORT') ?: 5173),
    'viteProxyPort' => (int) (getenv('JUMP_VITE_PROXY_PORT') ?: 3003),
    'appName' => getenv('APP_NAME') ?: 'Laravel',
];

// --- Logging ------------------------------------------------------------

/**
 * Append a request line to storage/logs/jump-router.log. Matches the
 * format jumpRequestLog() in router.php so existing tail/grep workflows
 * keep working.
 */
function jumpRouterLog(string $message): void
{
    global $JUMP;
    $logFile = $JUMP['basePath'].'/storage/logs/jump-router.log';
    $now = microtime(true);
    $ms = substr(sprintf('%.3f', $now - floor($now)), 2, 3);
    @file_put_contents(
        $logFile,
        '['.date('H:i:s.').$ms.'] [Jump] '.$message."\n",
        FILE_APPEND
    );
}

// --- Workerman setup ----------------------------------------------------

// Workerman writes Worker boot/status banners to its log file by default,
// which on Windows defaults to the current directory. Pin it to the
// project's storage/logs/ so it lands somewhere the developer can find.
Worker::$logFile = $JUMP['basePath'].'/storage/logs/jump-http.log';
// stdoutFile is where Workerman redirects child-process stdout when
// daemonised. We're not daemonising on Windows (no fork), but set it
// anyway so any errant `echo` from a handler doesn't disappear silently.
Worker::$stdoutFile = $JUMP['basePath'].'/storage/logs/jump-http.log';

$worker = new Worker("http://{$listenHost}:{$httpPort}");
$worker->count = 1;
$worker->name = 'JumpHttpProxy';

$worker->onMessage = function (TcpConnection $connection, Request $request) {
    try {
        $response = jumpHandleRequest($request);
    } catch (Throwable $e) {
        jumpRouterLog($request->method().' '.$request->uri().' [500 handler-exception] '.$e->getMessage());
        $response = new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], 'Internal proxy error: '.$e->getMessage());
    }

    // Force connection teardown after every response. PHP -S can't do this
    // (it decides keep-alive purely from the request); Workerman can, and
    // that's the whole point of moving to it for the Windows code path.
    // Setting the response header is for client correctness; the actual
    // close happens via $connection->close() once send() drains.
    $response = $response->withHeader('Connection', 'close');
    $connection->close($response);
};

// --- Dispatch -----------------------------------------------------------

function jumpHandleRequest(Request $request): Response
{
    global $JUMP;

    $method = $request->method();
    $uri = $request->uri();
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $path = rtrim($path, '/');
    if ($path === '') {
        $path = '/';
    }

    // Mirror router.php: log every non-trivial request at start so we can
    // distinguish "request arrived but upstream hung" from "request never
    // landed". Skip favicon/sourcemap to keep the log signal:noise high.
    $isNoise = $path === '/favicon.ico' || str_ends_with($path, '.map');
    if (! $isNoise) {
        jumpRouterLog($method.' '.$uri.' [start]');
    }

    // Quick-reject paths -------------------------------------------------

    if ($isNoise) {
        return new Response(204);
    }

    // WebSocket upgrades hitting the HTTP port are wrong by construction
    // — HMR goes to viteProxyPort, the device bridge to wsPort. A 404
    // here keeps Laravel's `/` from being invoked with an Upgrade header
    // (which under Inertia/Fortify rotates CSRF and breaks subsequent
    // POSTs). Same behaviour as router.php.
    if (strtolower($request->header('upgrade', '')) === 'websocket') {
        return new Response(404);
    }

    // /jump/info — internal status endpoint (no upstream) ----------------

    if ($path === '/jump/info') {
        $info = [
            'name' => 'NativePHP Server',
            'app_name' => $JUMP['appName'],
            'version' => '1.0.0',
            'type' => 'nativephp-server',
        ];
        if ($JUMP['wsPort']) {
            $info['ws_port'] = (string) $JUMP['wsPort'];
        }

        jumpRouterLog($method.' '.$uri.' [200]');

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($info));
    }

    // /jump/qr — QR landing page ---------------------------------------

    if ($path === '/jump/qr' || $path === '/jump') {
        return jumpRenderQrPage($method, $uri);
    }

    // Vite vs Laravel routing --------------------------------------------

    $hotFile = $JUMP['basePath'].'/public/hot';
    $viteRunning = file_exists($hotFile);
    $vitePort = $JUMP['vitePort'];
    if ($viteRunning) {
        $hotContent = trim((string) @file_get_contents($hotFile));
        if (preg_match('/:(\d+)\/?$/', $hotContent, $m)) {
            $vitePort = (int) $m[1];
        }
    }

    if ($viteRunning) {
        // Inertia's resolvePageComponent keys modules by absolute filesystem
        // path; HMR updates therefore land here as `/<abs-path>` and Vite
        // serves those at `/@fs/<abs>`. Rewrite before proxying.
        $isFsPath = str_starts_with($path, $JUMP['basePath'].'/');
        if ($isFsPath) {
            $uri = '/@fs'.$uri;

            return jumpProxyToVite($request, $method, $uri, '/@fs'.$path, $vitePort);
        }

        $isViteRequest = str_starts_with($path, '/@')
            || str_starts_with($path, '/resources/')
            || str_starts_with($path, '/node_modules/')
            || str_starts_with($path, '/vendor/')
            || str_contains($path, '.hot-update.');

        if ($isViteRequest) {
            return jumpProxyToVite($request, $method, $uri, $path, $vitePort);
        }
    }

    return jumpProxyToLaravel($request, $method, $uri);
}

// --- /jump/qr renderer --------------------------------------------------

function jumpRenderQrPage(string $method, string $uri): Response
{
    global $JUMP;

    try {
        if (! class_exists(Builder::class)) {
            throw new RuntimeException('QR Code library not available. Make sure endroid/qr-code is installed.');
        }

        $qrData = "jump://connect?host={$JUMP['displayHost']}&port={$JUMP['httpPort']}";

        $result = (new Builder(
            data: $qrData,
            size: 300,
            margin: 10,
        ))->build();

        $qrCodeDataUri = $result->getDataUri();

        // Prefer the shared blade template — it's the canonical design.
        // The inline fallback in router.php is intentionally NOT ported
        // here: in practice the template is always present (it ships in
        // the package), and duplicating ~1000 lines of inline HTML would
        // be a maintenance trap that drifts between code paths.
        $viewPath = __DIR__.'/views/qr.blade.php';
        if (file_exists($viewPath)) {
            $html = file_get_contents($viewPath);
            $html = str_replace('{{ $qrCodeDataUri }}', $qrCodeDataUri, $html);
            $html = str_replace('{{ $displayHost }}', $JUMP['displayHost'], $html);
            $html = str_replace('{{ $port }}', (string) $JUMP['httpPort'], $html);
        } else {
            // Minimal fallback. router.php has a richer inline page; if
            // someone is hitting this branch on Windows they're better
            // served by getting a working link than a fancy 500.
            $html = '<!doctype html><meta charset="utf-8"><title>Jump</title>'
                .'<p>Scan this URL with the Jump app: <code>'.htmlspecialchars($qrData).'</code></p>'
                .'<p><img alt="QR" src="'.htmlspecialchars($qrCodeDataUri).'"></p>';
        }

        jumpRouterLog($method.' '.$uri.' [200]');

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    } catch (Throwable $e) {
        jumpRouterLog($method.' '.$uri.' [500 qr] '.$e->getMessage());

        return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], 'Error generating QR code: '.$e->getMessage());
    }
}

// --- Vite proxy ---------------------------------------------------------

function jumpProxyToVite(Request $request, string $method, string $uri, string $path, int $vitePort): Response
{
    global $JUMP;

    $viteUrl = jumpResolveViteOrigin($vitePort).$uri;

    $headers = jumpBuildUpstreamHeaders($request);
    if ($ct = $request->header('content-type')) {
        $headers[] = 'Content-Type: '.$ct;
    }

    $body = null;
    if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        $body = $request->rawBody();
    }

    $ch = curl_init($viteUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    // 30s matches router.php after its bump from 10s — Vite cold transforms
    // (Vue SFC compile, first-hit module graph) routinely exceed 10s on
    // Windows + Herd.
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $start = microtime(true);
    $raw = curl_exec($ch);
    $upstreamMs = (int) ((microtime(true) - $start) * 1000);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false) {
        if ($errno === CURLE_COULDNT_CONNECT) {
            $detail = "Vite dev server is not listening on {$viteUrl}. Is `npm run dev` running?";
        } elseif ($errno === CURLE_OPERATION_TIMEDOUT) {
            $detail = "Vite request to {$viteUrl} timed out after 30s ({$upstreamMs}ms).";
        } else {
            $detail = "Could not reach Vite at {$viteUrl}. cURL error ({$errno}): {$error}";
        }

        jumpRouterLog("{$method} {$uri} [502 vite] {$detail}");

        return new Response(502, ['Content-Type' => 'text/plain; charset=utf-8'], "Bad Gateway: {$detail}");
    }

    $rawHeaders = substr((string) $raw, 0, $headerSize);
    $body = substr((string) $raw, $headerSize);

    // /@vite/client patching — same regex pair as router.php's
    // patchViteClient(). We rewrite the HMR endpoint in Vite's client to
    // point at our Workerman HMR proxy port (3003) instead of Vite's
    // own port, so the phone's WebSocket actually has somewhere on the
    // LAN to connect.
    if ($path === '/@vite/client') {
        $body = jumpPatchViteClient($body);
    }

    $response = new Response($httpCode);

    foreach (explode("\r\n", $rawHeaders) as $line) {
        if ($line === '' || str_starts_with($line, 'HTTP/')) {
            continue;
        }
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $name = trim(substr($line, 0, $colon));
        $value = trim(substr($line, $colon + 1));
        $lower = strtolower($name);

        // Strip transfer-encoding (we're not chunking back to the client),
        // connection/keep-alive (we force our own Connection: close in
        // onMessage), and stale content-length for the patched client.
        if (in_array($lower, ['transfer-encoding', 'connection', 'keep-alive'], true)) {
            continue;
        }
        if ($path === '/@vite/client' && $lower === 'content-length') {
            continue;
        }
        // Skip Vite's cache headers — we'll set no-store ourselves below.
        // Without this, Android WebView would re-use cached HMR modules
        // even with `?t=` busters.
        if (in_array($lower, ['cache-control', 'pragma', 'expires'], true)) {
            continue;
        }

        $response = $response->withHeader($name, $value);
    }

    $response = $response
        ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->withHeader('Pragma', 'no-cache')
        ->withHeader('Expires', '0')
        ->withBody($body);

    jumpRouterLog("{$method} {$uri} [{$httpCode} vite {$upstreamMs}ms]");

    return $response;
}

// --- Laravel proxy ------------------------------------------------------

function jumpProxyToLaravel(Request $request, string $method, string $uri): Response
{
    global $JUMP;

    $laravelUrl = "http://127.0.0.1:{$JUMP['laravelPort']}{$uri}";

    $headers = jumpBuildUpstreamHeaders($request);
    if ($ct = $request->header('content-type')) {
        $headers[] = 'Content-Type: '.$ct;
    }

    // Tell Laravel the real public-facing host so any URL it generates
    // (redirects, asset URLs, etc.) is reachable from the phone.
    $headers[] = "Host: {$JUMP['displayHost']}:{$JUMP['httpPort']}";
    $headers[] = "X-Forwarded-Host: {$JUMP['displayHost']}:{$JUMP['httpPort']}";
    $headers[] = 'X-Forwarded-Proto: http';
    $headers[] = 'X-Forwarded-Port: '.$JUMP['httpPort'];

    $body = null;
    if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        $body = $request->rawBody();
    }

    $ch = curl_init($laravelUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $start = microtime(true);
    $raw = curl_exec($ch);
    $upstreamMs = (int) ((microtime(true) - $start) * 1000);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false) {
        if ($errno === CURLE_COULDNT_CONNECT) {
            $detail = "Laravel dev server is not listening on 127.0.0.1:{$JUMP['laravelPort']}. Is `artisan serve` running?";
        } elseif ($errno === CURLE_OPERATION_TIMEDOUT) {
            $detail = "Request to Laravel on port {$JUMP['laravelPort']} timed out. The handler took longer than 90s to respond.";
        } else {
            $detail = "Could not reach Laravel on port {$JUMP['laravelPort']}. cURL error ({$errno}): {$error}";
        }

        jumpRouterLog("{$method} {$uri} [502] {$detail}");

        return new Response(502, ['Content-Type' => 'text/plain; charset=utf-8'], "Bad Gateway: {$detail}");
    }

    $rawHeaders = substr((string) $raw, 0, $headerSize);
    $body = substr((string) $raw, $headerSize);

    $laravelOrigin = "http://127.0.0.1:{$JUMP['laravelPort']}";
    $jumpOrigin = "http://{$JUMP['displayHost']}:{$JUMP['httpPort']}";

    $response = new Response($httpCode);
    $setCookies = [];

    foreach (explode("\r\n", $rawHeaders) as $line) {
        if ($line === '' || str_starts_with($line, 'HTTP/')) {
            continue;
        }
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $name = trim(substr($line, 0, $colon));
        $value = trim(substr($line, $colon + 1));
        $lower = strtolower($name);

        if (in_array($lower, ['transfer-encoding', 'connection', 'keep-alive'], true)) {
            continue;
        }

        if ($lower === 'location') {
            $value = str_replace($laravelOrigin, $jumpOrigin, $value);
        }

        if ($lower === 'set-cookie') {
            // Laravel sends multiple Set-Cookie headers (XSRF-TOKEN +
            // session). Workerman's Response::withHeader replaces by
            // name, so we batch and pass the array at the end to keep
            // all of them. Without this the session cookie disappears
            // and POST/PATCH/DELETE break with 419.
            $setCookies[] = $value;

            continue;
        }

        $response = $response->withHeader($name, $value);
    }

    if (! empty($setCookies)) {
        $response = $response->withHeader('Set-Cookie', $setCookies);
    }

    // Rewrite any Vite dev-server origins the Inertia template emitted,
    // so the phone routes those assets through our proxy.
    if ($JUMP['vitePort']) {
        $body = str_replace(
            [
                "http://localhost:{$JUMP['vitePort']}",
                "http://127.0.0.1:{$JUMP['vitePort']}",
                "http://[::1]:{$JUMP['vitePort']}",
                "http://[::]:{$JUMP['vitePort']}",
                "http://{$JUMP['displayHost']}:{$JUMP['vitePort']}",
            ],
            "http://{$JUMP['displayHost']}:{$JUMP['httpPort']}",
            $body
        );
    }

    $response = $response->withBody($body);

    jumpRouterLog("{$method} {$uri} [{$httpCode}]");

    return $response;
}

// --- Helpers ------------------------------------------------------------

/**
 * Collect HTTP-forwardable request headers, stripping hop-by-hop ones.
 * Workerman's Request normalises header names to lowercase already.
 */
function jumpBuildUpstreamHeaders(Request $request): array
{
    $skip = ['connection', 'keep-alive', 'transfer-encoding', 'upgrade', 'host', 'content-type'];
    $out = [];

    foreach ($request->header() as $name => $value) {
        if (in_array(strtolower($name), $skip, true)) {
            continue;
        }
        // Workerman returns header() values as strings (last value wins
        // for repeated headers — same as PHP-S behaviour). Forward as-is.
        $out[] = $name.': '.$value;
    }

    return $out;
}

/**
 * Decide the host:port we should curl Vite at. Reads public/hot since
 * the Laravel Vite plugin records the real bind address there, including
 * IPv6-only binds (`[::1]`) which 127.0.0.1 doesn't reach.
 */
function jumpResolveViteOrigin(int $vitePort): string
{
    global $JUMP;

    $hotFile = $JUMP['basePath'].'/public/hot';
    if (is_file($hotFile)) {
        $origin = rtrim(trim((string) @file_get_contents($hotFile)), '/');
        $parts = parse_url($origin);
        if (! empty($parts['host']) && ! empty($parts['port'])) {
            $hostRaw = trim($parts['host'], '[]');
            // Wildcard binds aren't valid connect targets; localhost is.
            if (in_array($hostRaw, ['0.0.0.0', '::', '::0'], true)) {
                return 'http://localhost:'.$parts['port'];
            }
            $host = str_contains($hostRaw, ':') ? '['.$hostRaw.']' : $hostRaw;

            return 'http://'.$host.':'.$parts['port'];
        }
    }

    return 'http://localhost:'.$vitePort;
}

/**
 * Patch the HMR endpoint inside Vite's `/@vite/client` so the phone
 * opens its HMR WebSocket against our Workerman HMR proxy port rather
 * than dialling Vite directly (which is on localhost/[::1] and isn't
 * reachable from the device).
 */
function jumpPatchViteClient(string $body): string
{
    global $JUMP;

    $proxyPort = $JUMP['viteProxyPort'];
    $displayHost = $JUMP['displayHost'];
    $totalReplaced = 0;

    $body = preg_replace(
        '/const hmrPort = (null|\d+);/',
        'const hmrPort = '.(int) $proxyPort.';',
        $body,
        1,
        $count
    );
    $totalReplaced += $count;

    $body = preg_replace(
        '/const directSocketHost = "[^"]*";/',
        'const directSocketHost = "'.$displayHost.':'.(int) $proxyPort.'/";',
        $body,
        1,
        $count
    );
    $totalReplaced += $count;

    if ($totalReplaced === 0) {
        jumpRouterLog('WARN /@vite/client patching matched no patterns — Vite may have refactored the client template');
    }

    return $body;
}

// --- Run ----------------------------------------------------------------

@file_put_contents(
    $JUMP['basePath'].'/storage/logs/jump-router.log',
    '=== '.date('Y-m-d H:i:s').' http-server (workerman) starting on '.$listenHost.':'.$httpPort." ===\n",
    FILE_APPEND
);

Worker::runAll();
