<?php

namespace Native\Mobile;

/**
 * Bridge for nativephp_call() in Jump hybrid mode.
 *
 * When running on the dev machine (not on device), this class provides
 * a pure PHP implementation of nativephp_call() that sends bridge calls
 * over a TCP socket to the Jump WebSocket server, which relays them
 * to the device and returns the result.
 */
class JumpBridge
{
    private static ?self $instance = null;

    private $socket = null;

    private int $port;

    private string $host;

    private float $timeout;

    public function __construct(string $host = '127.0.0.1', int $port = 3001, float $timeout = 600.0)
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
    }

    public function setTimeout(float $timeout): void
    {
        $this->timeout = $timeout;

        if ($this->socket !== null && ! feof($this->socket)) {
            stream_set_timeout($this->socket, (int) $timeout);
        }
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            // Read dynamic bridge port from env (set by JumpCommand)
            $port = (int) (getenv('JUMP_BRIDGE_PORT') ?: 3002);

            self::$instance = new self('127.0.0.1', $port);
        }

        return self::$instance;
    }

    /**
     * Send a bridge call to the device and wait for the response.
     */
    public function call(string $method, string $paramsJson = '{}'): ?string
    {
        $this->ensureConnected();

        if ($this->socket === null) {
            return json_encode([
                'status' => 'error',
                'code' => 'NO_DEVICE',
                'message' => 'No device connected. Make sure Jump app is running and connected.',
            ]);
        }

        $requestId = uniqid('bridge_', true);

        $message = json_encode([
            'type' => 'bridge_call',
            'id' => $requestId,
            'method' => $method,
            'params' => json_decode($paramsJson, true) ?? [],
        ]);

        // Send length-prefixed message
        $packed = pack('N', strlen($message)).$message;
        $written = @fwrite($this->socket, $packed);

        if ($written === false) {
            $this->disconnect();

            return json_encode([
                'status' => 'error',
                'code' => 'SEND_FAILED',
                'message' => 'Failed to send bridge call to device.',
            ]);
        }

        // Wait for response
        $response = $this->readResponse();

        if ($response === null) {
            return json_encode([
                'status' => 'error',
                'code' => 'TIMEOUT',
                'message' => "Bridge call '{$method}' timed out waiting for device response.",
            ]);
        }

        $decoded = json_decode($response, true);

        if (isset($decoded['error'])) {
            return json_encode([
                'status' => 'error',
                'code' => 'DEVICE_ERROR',
                'message' => $decoded['error'],
            ]);
        }

        return json_encode($decoded['result'] ?? []);
    }

    private function ensureConnected(): void
    {
        if ($this->socket !== null && ! feof($this->socket)) {
            return;
        }

        $this->socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            5.0
        );

        if ($this->socket === false) {
            $this->socket = null;

            return;
        }

        stream_set_timeout($this->socket, (int) $this->timeout);
    }

    private function readResponse(): ?string
    {
        if ($this->socket === null) {
            return null;
        }

        // Read 4-byte length prefix
        $lengthData = $this->readExact(4);
        if ($lengthData === null) {
            return null;
        }

        $unpacked = unpack('N', $lengthData);
        $length = $unpacked[1];

        if ($length <= 0 || $length > 10 * 1024 * 1024) { // Max 10MB
            return null;
        }

        return $this->readExact($length);
    }

    private function readExact(int $length): ?string
    {
        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = @fread($this->socket, $remaining);

            if ($chunk === false || $chunk === '') {
                $info = stream_get_meta_data($this->socket);
                if ($info['timed_out']) {
                    // Force reconnect next call so the stream isn't stuck
                    $this->disconnect();
                }

                return null;
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    private function disconnect(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
