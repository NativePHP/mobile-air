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
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
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
        $response = jumpHandleRequest($request, $connection);
    } catch (Throwable $e) {
        jumpRouterLog($request->method().' '.$request->uri().' [500 handler-exception] '.$e->getMessage());
        $response = new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], 'Internal proxy error: '.$e->getMessage());
    }

    // A null response means the handler went asynchronous (the Laravel proxy)
    // and will call jumpSendResponse() itself once the upstream replies. This
    // is what keeps the single Windows worker free: `Route::native` requests
    // are held open for the whole native-screen lifetime, and a blocking wait
    // here would stall every other request behind them (see jumpProxyToLaravel).
    if ($response === null) {
        return;
    }

    jumpSendResponse($connection, $response);
};

/**
 * Send a response and tear the connection down.
 *
 * Forcing teardown after every response is the whole point of moving the
 * Windows path to Workerman: PHP -S decides keep-alive purely from the
 * request, Workerman lets us close explicitly. Setting the header is for
 * client correctness; the actual close happens via close() once send() drains.
 */
function jumpSendResponse(TcpConnection $connection, Response $response): void
{
    $connection->close($response->withHeader('Connection', 'close'));
}

// --- Dispatch -----------------------------------------------------------

/**
 * Route a request. Returns a Response to send immediately, or null when the
 * handler has taken ownership of $connection and will respond asynchronously.
 */
function jumpHandleRequest(Request $request, TcpConnection $connection): ?Response
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
            // How the client should render this app: 'native-ui' (stream
            // Element.* frames over the WS bridge) or 'webview' (forward HTTP
            // responses). Set by JumpCommand via JUMP_APP_UI. Must match
            // router.php — omitting it makes the Jump app fall back to webview
            // and HTTP-forward a native-ui route, which blocks in the native
            // event loop (appears to the user as a hung/500 webview).
            'ui' => getenv('JUMP_APP_UI') ?: 'native-ui',
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

    jumpProxyToLaravel($request, $method, $uri, $connection);

    return null; // async — jumpProxyToLaravel owns the connection from here
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

/**
 * Proxy a request to the Laravel dev server — asynchronously.
 *
 * WHY ASYNC (this is load-bearing on Windows, do not "simplify" back to curl):
 * a `Route::native` route deliberately holds its HTTP response open for the
 * entire native-screen lifetime — the request reaches Laravel, renders the
 * component, and parks in nativephp_element_wait_event() publishing frames over
 * the WS bridge. macOS/Linux absorb that with PHP_CLI_SERVER_WORKERS (php -S
 * forks a pool, see JumpCommand), so router.php can afford a blocking curl with
 * CURLOPT_TIMEOUT 0. Workerman on Windows cannot fork: $worker->count is pinned
 * to 1, so a blocking curl here freezes the ONLY worker for the whole screen
 * lifetime and every other request — /jump/info, assets, and the app's
 * re-entry handshake — queues behind it. The old 90s CURLOPT_TIMEOUT turned
 * that into a 502-then-retry loop that wedged the proxy indefinitely.
 *
 * Using AsyncTcpConnection keeps the event loop free while the runloop request
 * is parked, which lets us drop the timeout entirely and match router.php.
 * Only the transport changed: the response post-processing is untouched and
 * still runs, verbatim, in jumpBuildLaravelResponse().
 */
function jumpProxyToLaravel(Request $request, string $method, string $uri, TcpConnection $connection): void
{
    global $JUMP;

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
    // Only on methods that carry a body — curl didn't send Content-Length on
    // GET and some handlers treat a bodyless GET with one as malformed.
    if ($body !== null) {
        $headers[] = 'Content-Length: '.strlen($body);
    }

    // Ask for a close-delimited response: the upstream ends the body by closing
    // the socket, so onClose is an unambiguous "response complete" signal and we
    // never have to second-guess Content-Length.
    $headers[] = 'Connection: close';

    $wire = sprintf("%s %s HTTP/1.1\r\n", $method, $uri)
        .implode("\r\n", $headers)
        ."\r\n\r\n"
        .((string) $body);

    $start = microtime(true);
    $raw = '';
    $settled = false;
    $connectTimer = null;

    try {
        $upstream = new AsyncTcpConnection("tcp://127.0.0.1:{$JUMP['laravelPort']}");
    } catch (Throwable $e) {
        jumpSendResponse($connection, jumpLaravelError(
            $method, $uri,
            "Could not open a connection to Laravel on port {$JUMP['laravelPort']}: ".$e->getMessage()
        ));

        return;
    }

    // Settle exactly once, whichever of the four paths gets there first
    // (response complete / upstream error / connect timeout / client hang-up).
    $finish = function (?Response $response) use (&$settled, &$connectTimer, $connection, $upstream) {
        if ($settled) {
            return;
        }
        $settled = true;
        if ($connectTimer !== null) {
            Timer::del($connectTimer);
            $connectTimer = null;
        }
        $upstream->onClose = null; // our own close() must not re-enter
        $upstream->close();
        if ($response !== null) {
            jumpSendResponse($connection, $response);
        }
    };

    $upstream->onConnect = function (AsyncTcpConnection $c) use ($wire, &$connectTimer) {
        if ($connectTimer !== null) {
            Timer::del($connectTimer);
            $connectTimer = null;
        }
        $c->send($wire);
    };

    $upstream->onMessage = function (AsyncTcpConnection $c, $chunk) use (&$raw) {
        $raw .= $chunk;
    };

    // Upstream closed — with `Connection: close` that means the response is
    // complete. NOTE: there is deliberately no read timeout here; a parked
    // native runloop request is expected to stay open indefinitely.
    $upstream->onClose = function () use (&$raw, $method, $uri, $start, $finish) {
        if ($raw === '') {
            $ms = (int) ((microtime(true) - $start) * 1000);
            $finish(jumpLaravelError(
                $method, $uri,
                "Laravel closed the connection after {$ms}ms without sending a response."
            ));

            return;
        }

        $finish(jumpBuildLaravelResponse($raw, $method, $uri));
    };

    $upstream->onError = function (AsyncTcpConnection $c, $code, $msg) use ($method, $uri, $finish) {
        global $JUMP;
        $finish(jumpLaravelError(
            $method, $uri,
            "Laravel dev server is not listening on 127.0.0.1:{$JUMP['laravelPort']}. Is `artisan serve` running? (error {$code}: {$msg})"
        ));
    };

    // Client hung up — app exited, re-entered, or dismissed the screen. Drop
    // the upstream with it, otherwise every exit/re-enter strands another
    // Laravel worker parked in the native runloop until the server restarts.
    $connection->onClose = function () use (&$settled, &$connectTimer, $upstream) {
        if ($settled) {
            return;
        }
        $settled = true;
        if ($connectTimer !== null) {
            Timer::del($connectTimer);
            $connectTimer = null;
        }
        $upstream->onClose = null;
        $upstream->close();
    };

    // AsyncTcpConnection has no built-in connect timeout; reproduce the 5s
    // CONNECTTIMEOUT the curl implementation used. Cancelled on connect.
    $connectTimer = Timer::add(5, function () use ($method, $uri, $finish) {
        global $JUMP;
        $finish(jumpLaravelError(
            $method, $uri,
            "Timed out connecting to Laravel on 127.0.0.1:{$JUMP['laravelPort']} after 5s. Is `artisan serve` running?"
        ));
    }, [], false);

    $upstream->connect();
}

/**
 * Log and build the 502 the client sees when the upstream is unreachable.
 * Kept as a real status + human-readable detail so the native error screens
 * planned for v4 have something meaningful to render.
 */
function jumpLaravelError(string $method, string $uri, string $detail): Response
{
    jumpRouterLog("{$method} {$uri} [502] {$detail}");

    return new Response(502, ['Content-Type' => 'text/plain; charset=utf-8'], "Bad Gateway: {$detail}");
}

/**
 * Decode a chunked transfer-encoded body. curl did this for us; over a raw
 * socket we have to do it ourselves.
 */
function jumpDechunk(string $body): string
{
    $out = '';
    $offset = 0;
    $len = strlen($body);

    while ($offset < $len) {
        $lineEnd = strpos($body, "\r\n", $offset);
        if ($lineEnd === false) {
            break;
        }
        $sizeHex = substr($body, $offset, $lineEnd - $offset);
        if (($semi = strpos($sizeHex, ';')) !== false) { // chunk extensions
            $sizeHex = substr($sizeHex, 0, $semi);
        }
        $sizeHex = trim($sizeHex);
        if ($sizeHex === '' || ! ctype_xdigit($sizeHex)) {
            break;
        }
        $size = (int) hexdec($sizeHex);
        if ($size <= 0) {
            break; // terminal chunk
        }
        $out .= substr($body, $lineEnd + 2, $size);
        $offset = $lineEnd + 2 + $size + 2; // chunk data + trailing CRLF
    }

    // If it didn't parse as chunked at all, hand back what we got rather than
    // silently serving an empty body.
    if ($out === '' && ! str_starts_with(ltrim($body), '0')) {
        return $body;
    }

    return $out;
}

/**
 * Turn a raw upstream HTTP response into a Workerman Response.
 *
 * Everything below the header/body split is carried over verbatim from the
 * previous curl implementation — Set-Cookie batching and Vite origin rewriting
 * are unchanged on purpose, so the async switch is a transport-only change.
 */
function jumpBuildLaravelResponse(string $raw, string $method, string $uri): Response
{
    global $JUMP;

    // Split status line + headers from the body, stepping over any 1xx
    // informational block (e.g. "100 Continue") that precedes the real one.
    $rawHeaders = $raw;
    $body = '';
    while (true) {
        $split = strpos($raw, "\r\n\r\n");
        if ($split === false) {
            $rawHeaders = $raw;
            $body = '';
            break;
        }
        $rawHeaders = substr($raw, 0, $split);
        $body = substr($raw, $split + 4);
        if (preg_match('#^HTTP/\d\.\d\s+1\d\d#', $rawHeaders)) {
            $raw = $body; // informational — the real response follows
            continue;
        }
        break;
    }

    $httpCode = 200;
    if (preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $rawHeaders, $m)) {
        $httpCode = (int) $m[1];
    }

    $laravelOrigin = "http://127.0.0.1:{$JUMP['laravelPort']}";
    $jumpOrigin = "http://{$JUMP['displayHost']}:{$JUMP['httpPort']}";

    $response = new Response($httpCode);
    $setCookies = [];
    $isChunked = false;

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
            // curl de-chunked for us; over a raw socket we have to notice.
            if ($lower === 'transfer-encoding' && stripos($value, 'chunked') !== false) {
                $isChunked = true;
            }

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

    if ($isChunked) {
        $body = jumpDechunk($body);
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
