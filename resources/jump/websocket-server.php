<?php

/**
 * Jump WebSocket Bridge Server
 *
 * Single-process server that handles:
 * - WebSocket connections from the mobile device (device bridge)
 * - TCP connections from PHP (nativephp_call bridge)
 * - WebSocket proxy for Vite HMR (phone ↔ Jump ↔ Vite)
 *
 * Usage:
 *   php websocket-server.php <base_path> [ws_port] [bridge_port] [vite_proxy_port] start [-d]
 */

// Parse arguments
$args = array_slice($argv, 1);
$positional = [];
foreach ($args as $arg) {
    if ($arg === 'start' || $arg === 'stop' || $arg === 'restart' || $arg === '-d' || $arg === '-g') {
        continue;
    }
    $positional[] = $arg;
}

$basePath = $positional[0] ?? getenv('JUMP_BASE_PATH');
$wsPort = $positional[1] ?? getenv('JUMP_WS_PORT') ?: '3001';
$bridgePort = $positional[2] ?? getenv('JUMP_BRIDGE_PORT') ?: '3002';
$viteProxyPort = $positional[3] ?? getenv('JUMP_VITE_PROXY_PORT') ?: '3003';

if (! $basePath || ! file_exists($basePath.'/vendor/autoload.php')) {
    fwrite(STDERR, "[Jump] Error: base_path not provided or vendor/autoload.php not found\n");
    exit(1);
}

require_once $basePath.'/vendor/autoload.php';

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

// Make basePath available globally for file watcher
$GLOBALS['basePath'] = $basePath;

// Single WebSocket worker — the TCP server is created inside onWorkerStart
// so both run in the SAME process and can share $deviceConnections
$wsWorker = new Worker("websocket://0.0.0.0:{$wsPort}");
$wsWorker->count = 1;
$wsWorker->name = 'JumpBridge';

// Shared state (same process)
$deviceConnections = [];
$pendingCalls = [];

$wsWorker->onConnect = function (TcpConnection $connection) use (&$deviceConnections) {
    $deviceConnections[$connection->id] = $connection;
    jumpLog('Device connected via WebSocket (total: '.count($deviceConnections).')');
};

$wsWorker->onMessage = function (TcpConnection $connection, $data) use (&$pendingCalls) {
    $message = json_decode($data, true);
    if (! $message || ! isset($message['type'])) {
        return;
    }

    switch ($message['type']) {
        case 'bridge_response':
            $requestId = $message['id'] ?? null;
            if ($requestId && isset($pendingCalls[$requestId])) {
                $tcpConnection = $pendingCalls[$requestId];
                unset($pendingCalls[$requestId]);

                $response = json_encode([
                    'id' => $requestId,
                    'result' => $message['result'] ?? [],
                    'error' => $message['error'] ?? null,
                ]);

                $packed = pack('N', strlen($response)).$response;
                $tcpConnection->send($packed);
            }
            break;

        case 'native_event':
            jumpLog("Native event: {$message['event']}");
            break;

        case 'pong':
            break;
    }
};

$wsWorker->onClose = function (TcpConnection $connection) use (&$deviceConnections, &$pendingCalls) {
    unset($deviceConnections[$connection->id]);
    jumpLog('Device disconnected (remaining: '.count($deviceConnections).')');

    if (empty($deviceConnections)) {
        foreach ($pendingCalls as $requestId => $tcpConnection) {
            $error = json_encode([
                'id' => $requestId,
                'error' => 'Device disconnected',
            ]);
            $packed = pack('N', strlen($error)).$error;
            $tcpConnection->send($packed);
        }
        $pendingCalls = [];
    }
};

// Create the TCP server inside onWorkerStart so it runs in the SAME process
$wsWorker->onWorkerStart = function () use (&$deviceConnections, &$pendingCalls, $bridgePort) {
    $tcpBuffers = [];

    // Internal TCP server for PHP bridge calls
    $tcpServer = new Worker("tcp://127.0.0.1:{$bridgePort}");

    $tcpServer->onConnect = function (TcpConnection $connection) use (&$tcpBuffers) {
        $tcpBuffers[$connection->id] = '';
    };

    $tcpServer->onMessage = function (TcpConnection $connection, $data) use (&$deviceConnections, &$pendingCalls, &$tcpBuffers) {
        $tcpBuffers[$connection->id] = ($tcpBuffers[$connection->id] ?? '').$data;
        $buffer = &$tcpBuffers[$connection->id];

        while (strlen($buffer) >= 4) {
            $unpacked = unpack('N', substr($buffer, 0, 4));
            $messageLength = $unpacked[1];

            if (strlen($buffer) < 4 + $messageLength) {
                break;
            }

            $messageData = substr($buffer, 4, $messageLength);
            $buffer = substr($buffer, 4 + $messageLength);

            $message = json_decode($messageData, true);
            if (! $message || ! isset($message['type'])) {
                continue;
            }

            if ($message['type'] === 'bridge_call') {
                $callId = $message['id'] ?? 'unknown';
                $method = $message['method'] ?? 'unknown';

                if (empty($deviceConnections)) {
                    jumpLog("bridge_call method={$method} rejected: no device connected");
                    $error = json_encode([
                        'id' => $callId,
                        'error' => 'No device connected',
                    ]);
                    $packed = pack('N', strlen($error)).$error;
                    $connection->send($packed);

                    continue;
                }

                $pendingCalls[$callId] = $connection;
                $encoded = json_encode($message);
                foreach ($deviceConnections as $deviceConnection) {
                    $deviceConnection->send($encoded);
                }
            }
        }
    };

    $tcpServer->onClose = function (TcpConnection $connection) use (&$pendingCalls, &$tcpBuffers) {
        unset($tcpBuffers[$connection->id]);
        foreach ($pendingCalls as $requestId => $pendingConnection) {
            if ($pendingConnection === $connection) {
                unset($pendingCalls[$requestId]);
            }
        }
    };

    $tcpServer->listen();
    jumpLog("TCP bridge listening on 127.0.0.1:{$bridgePort}");

    // Keepalive ping
    Timer::add(15, function () use (&$deviceConnections) {
        $ping = json_encode(['type' => 'ping']);
        foreach ($deviceConnections as $connection) {
            $connection->send($ping);
        }
    });

    // File watcher for live reload. Front-end extensions (js/ts/vue/css)
    // are handled by Vite HMR when `public/hot` exists — firing our own
    // full-reload on those changes wipes HMR state (form inputs, Inertia
    // page props, scroll position). Only watch them when Vite isn't running.
    $lastModTimes = [];
    $lastReloadTime = 0;
    $watchPaths = ['app', 'resources', 'routes', 'config'];
    $serverExtensions = ['php', 'blade.php'];
    $clientExtensions = ['js', 'jsx', 'ts', 'tsx', 'vue', 'css', 'scss', 'sass', 'less'];

    Timer::add(1, function () use (&$deviceConnections, &$lastModTimes, &$lastReloadTime, $watchPaths, $serverExtensions, $clientExtensions) {
        global $basePath;
        if (empty($deviceConnections)) {
            return;
        }

        $viteRunning = file_exists($basePath.'/public/hot');
        $watchExtensions = $viteRunning ? $serverExtensions : array_merge($serverExtensions, $clientExtensions);

        $changed = false;
        foreach ($watchPaths as $dir) {
            $fullPath = $basePath.'/'.$dir;
            if (! is_dir($fullPath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $ext = $file->getExtension();
                $path = $file->getPathname();

                // Check blade.php specifically
                $isWatched = in_array($ext, $watchExtensions) || str_ends_with($path, '.blade.php');
                if (! $isWatched) {
                    continue;
                }

                $mtime = $file->getMTime();
                if (isset($lastModTimes[$path]) && $mtime - $lastModTimes[$path] >= 1) {
                    $changed = true;
                    $relativePath = str_replace($basePath.'/', '', $path);
                    jumpLog("Changed: {$relativePath}");
                }
                $lastModTimes[$path] = $mtime;
            }
        }

        if ($changed) {
            $reload = json_encode(['type' => 'reload']);
            foreach ($deviceConnections as $connection) {
                $connection->send($reload);
            }
            jumpLog('Sent reload to '.count($deviceConnections).' device(s)');
        }
    });
};

function jumpLog($message)
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

// Vite HMR proxy — lets the phone reach Vite's HMR WebSocket without the user
// editing vite.config.js. The phone connects here (to the LAN-reachable Jump
// host) and we open a WebSocket client to Vite on 127.0.0.1, then relay frames
// both directions. Vite's allowedHosts / origin-token checks pass naturally
// because we dial Vite as localhost with the genuine token from /@vite/client.
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

Worker::runAll();
