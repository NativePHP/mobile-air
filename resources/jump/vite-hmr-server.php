<?php

/**
 * Jump Vite HMR Proxy (standalone)
 *
 * This is the Vite-side of the Jump WebSocket bridge: the phone opens a
 * WebSocket to `ws://<lan>:<viteProxyPort>/`, we open an upstream
 * WebSocket to Vite's HMR endpoint on 127.0.0.1/[::1], and we relay
 * frames in both directions.
 *
 * Why a separate file: Workerman on Windows cannot start multiple
 * `Worker` instances declared in a single PHP file (no fork; you get the
 * "multi workers init in one php file are not support" warning and only
 * the first Worker actually binds). The companion `websocket-server.php`
 * declares two workers (JumpBridge on the device port, JumpViteProxy
 * here), which means on Windows the Vite proxy never came up and HMR was
 * silently broken. Extracting this worker into its own process makes the
 * Windows path work without disturbing the macOS/Linux setup where
 * websocket-server.php's fork model already starts both workers.
 *
 * JumpCommand spawns this script on Windows in addition to
 * websocket-server.php. On other platforms websocket-server.php still
 * runs the Vite proxy via fork — see project_jump_router_split memory.
 *
 * Usage:
 *   php vite-hmr-server.php <base_path> <vite_proxy_port> start [-d]
 */

declare(strict_types=1);

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

// --- Argument parsing --------------------------------------------------

$args = array_slice($argv, 1);
$positional = [];
foreach ($args as $arg) {
    if (in_array($arg, ['start', 'stop', 'restart', '-d', '-g'], true)) {
        continue;
    }
    $positional[] = $arg;
}

$basePath = $positional[0] ?? getenv('JUMP_BASE_PATH') ?: null;
$viteProxyPort = (int) ($positional[1] ?? getenv('JUMP_VITE_PROXY_PORT') ?: 3003);

if (! $basePath || ! file_exists($basePath.'/vendor/autoload.php')) {
    fwrite(STDERR, "[Jump] vite-hmr-server.php: base_path not provided or vendor/autoload.php missing\n");
    exit(1);
}

require_once $basePath.'/vendor/autoload.php';

$GLOBALS['basePath'] = $basePath;

// --- Helpers (port from websocket-server.php) --------------------------

function jumpLog($message): void
{
    fwrite(STDERR, '['.date('H:i:s').'] [Jump] '.$message."\n");
}

/**
 * Resolve the live Vite dev-server origin (host + port) from the Laravel
 * Vite hot file. Returns [host, port] — host is what we should actually
 * connect to. macOS Node binds `localhost` to IPv6 [::1] only, so dialing
 * 127.0.0.1 would fail; we respect the file. Wildcard binds (0.0.0.0 / ::)
 * collapse to `localhost` since they're listener-only addresses.
 */
function jumpResolveViteTarget(string $basePath): array
{
    $hot = $basePath.'/public/hot';
    if (is_file($hot)) {
        $origin = rtrim(trim((string) @file_get_contents($hot)), '/');
        $parts = parse_url($origin);
        if (! empty($parts['host']) && ! empty($parts['port'])) {
            $hostRaw = trim($parts['host'], '[]');
            if (in_array($hostRaw, ['0.0.0.0', '::', '::0'], true)) {
                return ['localhost', (int) $parts['port']];
            }
            $host = str_contains($hostRaw, ':') ? '['.$hostRaw.']' : $hostRaw;

            return [$host, (int) $parts['port']];
        }
    }

    return ['localhost', 5173];
}

// --- Vite HMR proxy worker ---------------------------------------------

// Pin Workerman's log files into storage/logs/ so they end up alongside
// the bridge/router logs — otherwise on Windows they land in cwd which
// is normally the Laravel project root.
Worker::$logFile = $basePath.'/storage/logs/jump-vite-hmr.log';
Worker::$stdoutFile = $basePath.'/storage/logs/jump-vite-hmr.log';

$viteProxy = new Worker("websocket://0.0.0.0:{$viteProxyPort}");
$viteProxy->count = 1;
$viteProxy->name = 'JumpViteProxy';

$viteProxy->onWebSocketConnect = function (TcpConnection $phone, $httpBuffer) use ($basePath) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $query = parse_url($requestUri, PHP_URL_QUERY) ?: '';
    [$viteHost, $vitePort] = jumpResolveViteTarget($basePath);

    // Echo the client's requested subprotocol back in the handshake response.
    // Chrome/Android WebView (per RFC 6455) aborts with "Sent non-empty
    // Sec-WebSocket-Protocol header but no response was received" if we
    // don't — iOS WebKit is forgiving, so only Android fails visibly.
    // Workerman doesn't do this automatically; inject via $connection->headers
    // which gets added to the 101 response at Protocols/Websocket.php:468.
    $requestedProto = $_SERVER['HTTP_SEC_WEBSOCKET_PROTOCOL'] ?? '';
    if ($requestedProto !== '') {
        // Server must pick exactly one of the offered subprotocols. Vite
        // only uses 'vite-hmr' / 'vite-ping', so echo the first offered.
        $chosen = trim(explode(',', $requestedProto)[0]);
        $phone->headers = ['Sec-WebSocket-Protocol: '.$chosen];
    }

    // Forward the exact path + query the phone used so Vite sees the same
    // token (?token=…) it baked into /@vite/client.
    $upstreamUrl = "ws://{$viteHost}:{$vitePort}".($query ? "/?{$query}" : '/');
    $upstream = new AsyncTcpConnection($upstreamUrl);
    $upstream->websocketClientProtocol = 'vite-hmr';

    // Hold frames sent by the phone until Vite finishes its handshake.
    // Vite HMR's wire protocol is text-only (JSON payloads); control frames
    // (ping/pong) are handled by Workerman internally and don't fire
    // onMessage. Forcing "\x81" (text) in both directions avoids a stale
    // websocketType from leaking in — Android's WebView WS parser is
    // stricter about opcode correctness than iOS WebKit's.
    $phoneBuffer = [];
    $upstreamReady = false;

    $phone->upstream = $upstream;
    $upstream->phone = $phone;

    $upstream->onWebSocketConnect = function ($upstream) use (&$upstreamReady, &$phoneBuffer, $phone) {
        $upstreamReady = true;
        foreach ($phoneBuffer as $data) {
            $upstream->websocketType = "\x81";
            $upstream->send($data);
        }
        $phoneBuffer = [];
        jumpLog('Vite HMR proxy: upstream connected for device '.$phone->id);
    };

    $upstream->onMessage = function ($upstream, $data) use ($phone) {
        $phone->websocketType = "\x81";
        $phone->send($data);
    };

    $upstream->onClose = function ($upstream) use ($phone) {
        if ($phone->getStatus() !== TcpConnection::STATUS_CLOSED) {
            $phone->close();
        }
    };

    $upstream->onError = function ($upstream, $code, $msg) use ($phone) {
        jumpLog("Vite HMR proxy: upstream error [{$code}] {$msg}");
        if ($phone->getStatus() !== TcpConnection::STATUS_CLOSED) {
            $phone->close();
        }
    };

    // Re-route phone → upstream. Must assign per-connection so other phones
    // don't stomp this one's upstream reference.
    $phone->onMessage = function ($phone, $data) use (&$phoneBuffer, &$upstreamReady) {
        if (! $upstreamReady) {
            $phoneBuffer[] = $data;

            return;
        }
        $phone->upstream->websocketType = "\x81";
        $phone->upstream->send($data);
    };

    $phone->onClose = function ($phone) {
        if (isset($phone->upstream) && $phone->upstream->getStatus() !== TcpConnection::STATUS_CLOSED) {
            $phone->upstream->close();
        }
    };

    jumpLog("Vite HMR proxy: device {$phone->id} connecting to {$upstreamUrl}");
    $upstream->connect();
};

@file_put_contents(
    $basePath.'/storage/logs/jump-bridge.log',
    '=== '.date('Y-m-d H:i:s').' vite-hmr proxy starting (port='.$viteProxyPort.") ===\n",
    FILE_APPEND
);

Worker::runAll();
