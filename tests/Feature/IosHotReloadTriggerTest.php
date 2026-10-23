<?php

use Native\Mobile\Concerns\WatchesIos;

/**
 * The iOS app's hot reload server only reloads on an explicit command, so dev
 * tools probing its port (SimDeck looking for DevTools targets, port scanners)
 * can't reboot the app. What `native:watch` sends has to match
 * `HotReloadServer.reloadCommand` in HotReloadServer.swift.
 */
function iosReloadWatcher(int $port): object
{
    return new class($port)
    {
        use WatchesIos;

        /** @var list<string> */
        public array $lines = [];

        public function __construct(private int $hotReloadPort) {}

        public function reload(): void
        {
            $this->triggerIosReload();
        }

        public function iproxy(string $iproxyPath, string $target, int $port, string $logFile): string
        {
            return $this->iproxyCommand($iproxyPath, $target, $port, $logFile);
        }

        public function line(string $string): void
        {
            $this->lines[] = $string;
        }

        protected function iosHotReloadPort(): int
        {
            return $this->hotReloadPort;
        }
    };
}

/**
 * A real listener on a free port. Never 9999: a simulator app on this machine
 * may be holding it, and the trigger would reload that app.
 *
 * @return array{0: resource, 1: int}
 */
function hotReloadListener(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    expect($server)->not->toBeFalse($errstr);

    $address = stream_socket_get_name($server, false);

    return [$server, (int) substr($address, strrpos($address, ':') + 1)];
}

it('sends exactly the reload command the app listens for', function () {
    [$server, $port] = hotReloadListener();

    try {
        $watcher = iosReloadWatcher($port);
        $watcher->reload();

        // The trigger has written and hung up by now. Its connection waited in
        // the listen backlog, so everything it sent is ready to read.
        $connection = stream_socket_accept($server, 1);
        expect($connection)->not->toBeFalse();

        stream_set_timeout($connection, 2);

        expect(stream_get_contents($connection))->toBe("nativephp:hot-reload\n");
        expect($watcher->lines)->toBe([]);

        fclose($connection);
    } finally {
        fclose($server);
    }
});

it('forwards reloads to a physical device over USB or Wi-Fi', function () {
    // Without -n, iproxy only finds USB devices and a phone on Wi-Fi silently
    // misses every reload. The Mac end is the port picked for this device;
    // the app on the device always listens on 9999.
    $command = iosReloadWatcher(9999)->iproxy('/opt/homebrew/bin/iproxy', '00008140-00092D393431801C', 51234, '/tmp/iproxy.log');

    expect($command)->toBe("/opt/homebrew/bin/iproxy -l -n -u '00008140-00092D393431801C' 51234:9999 > /tmp/iproxy.log 2>&1 & echo \$!");
});

it('reports a failed reload when nothing is listening', function () {
    [$server, $port] = hotReloadListener();
    fclose($server);

    $watcher = iosReloadWatcher($port);
    $watcher->reload();

    expect($watcher->lines)->toHaveCount(1);
    expect($watcher->lines[0])->toContain('reload failed')->toContain("port {$port}");
});
