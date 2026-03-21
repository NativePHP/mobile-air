<?php

/**
 * Jump WebSocket Bridge Server
 *
 * Single-process server that handles both:
 * - WebSocket connections from the mobile device
 * - TCP connections from PHP (nativephp_call bridge)
 *
 * Usage:
 *   php websocket-server.php <base_path> [ws_port] [bridge_port] start [-d]
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

if (! $basePath || ! file_exists($basePath.'/vendor/autoload.php')) {
    fwrite(STDERR, "[Jump] Error: base_path not provided or vendor/autoload.php not found\n");
    exit(1);
}

require_once $basePath.'/vendor/autoload.php';

use Workerman\Connection\TcpConnection;
use Workerman\Worker;

// Make basePath available globally for file watcher
$GLOBALS['basePath'] = $basePath;

// Single WebSocket worker — the TCP server is created inside onWorkerStart
// so both run in the SAME process and can share $deviceConnection
$wsWorker = new Worker("websocket://0.0.0.0:{$wsPort}");
$wsWorker->count = 1;
$wsWorker->name = 'JumpBridge';

// Shared state (same process)
$deviceConnection = null;
$pendingCalls = [];

$wsWorker->onConnect = function (TcpConnection $connection) use (&$deviceConnection) {
    $deviceConnection = $connection;
    jumpLog('Device connected via WebSocket');
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

$wsWorker->onClose = function (TcpConnection $connection) use (&$deviceConnection, &$pendingCalls) {
    if ($connection === $deviceConnection) {
        $deviceConnection = null;
        jumpLog('Device disconnected');

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
$wsWorker->onWorkerStart = function () use (&$deviceConnection, &$pendingCalls, $bridgePort) {
    $tcpBuffers = [];

    // Internal TCP server for PHP bridge calls
    $tcpServer = new Worker("tcp://127.0.0.1:{$bridgePort}");

    $tcpServer->onConnect = function (TcpConnection $connection) use (&$tcpBuffers) {
        $tcpBuffers[$connection->id] = '';
    };

    $tcpServer->onMessage = function (TcpConnection $connection, $data) use (&$deviceConnection, &$pendingCalls, &$tcpBuffers) {
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
                if ($deviceConnection === null) {
                    $error = json_encode([
                        'id' => $message['id'] ?? 'unknown',
                        'error' => 'No device connected',
                    ]);
                    $packed = pack('N', strlen($error)).$error;
                    $connection->send($packed);

                    continue;
                }

                $pendingCalls[$message['id']] = $connection;
                $deviceConnection->send(json_encode($message));
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
    \Workerman\Timer::add(15, function () use (&$deviceConnection) {
        if ($deviceConnection) {
            $deviceConnection->send(json_encode(['type' => 'ping']));
        }
    });

    // File watcher for live reload
    $lastModTimes = [];
    $watchPaths = ['app', 'resources', 'routes', 'config'];
    $watchExtensions = ['php', 'blade.php', 'js', 'css', 'ts', 'vue'];

    \Workerman\Timer::add(1, function () use (&$deviceConnection, &$lastModTimes, $watchPaths, $watchExtensions) {
        global $basePath;
        if (! $deviceConnection) {
            return;
        }

        $changed = false;
        foreach ($watchPaths as $dir) {
            $fullPath = $basePath.'/'.$dir;
            if (! is_dir($fullPath)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS)
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
                if (isset($lastModTimes[$path]) && $lastModTimes[$path] < $mtime) {
                    $changed = true;
                    $relativePath = str_replace($basePath.'/', '', $path);
                    jumpLog("Changed: {$relativePath}");
                }
                $lastModTimes[$path] = $mtime;
            }
        }

        if ($changed) {
            $deviceConnection->send(json_encode(['type' => 'reload']));
            jumpLog('Sent reload to device');
        }
    });
};

function jumpLog($message)
{
    fwrite(STDERR, "[Jump] {$message}\n");
}

Worker::runAll();
