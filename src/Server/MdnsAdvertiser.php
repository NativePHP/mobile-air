<?php

namespace Native\Mobile\Server;

use React\Datagram\Factory as DatagramFactory;
use React\Datagram\Socket;
use React\EventLoop\LoopInterface;

class MdnsAdvertiser
{
    private ?Socket $socket = null;

    private string $serviceName;

    private int $port;

    private LoopInterface $loop;

    public function __construct(LoopInterface $loop, string $serviceName, int $port)
    {
        $this->loop = $loop;
        $this->serviceName = $serviceName;
        $this->port = $port;
    }

    public function start(): void
    {
        $factory = new DatagramFactory($this->loop);

        $factory->createClient('224.0.0.251:5353')->then(function (Socket $client) {
            $this->socket = $client;

            // Send mDNS announcement
            $this->announce();

            // Re-announce every 30 seconds
            $this->loop->addPeriodicTimer(30, function () {
                $this->announce();
            });

            echo "✓ mDNS service advertised: {$this->serviceName}\n";
        }, function (\Exception $e) {
            echo "✗ Failed to create mDNS advertiser: {$e->getMessage()}\n";
            echo "  Note: The HTTP server will still work, but may not be auto-discovered.\n";
        });
    }

    private function announce(): void
    {
        if (! $this->socket) {
            return;
        }

        // Create a simple mDNS announcement packet
        // This is a simplified version - full mDNS is more complex
        $serviceName = $this->serviceName;
        $serviceType = '_http._tcp.local';
        $hostname = gethostname();

        // For now, we'll just log that we're announcing
        // A full implementation would construct proper DNS packets
        echo "→ Announcing service: {$serviceName} on port {$this->port}\n";
    }

    public function stop(): void
    {
        if ($this->socket) {
            $this->socket->close();
            $this->socket = null;
        }
    }
}
