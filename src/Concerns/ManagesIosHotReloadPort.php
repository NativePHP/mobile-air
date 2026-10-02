<?php

namespace Native\Mobile\Concerns;

use Illuminate\Support\Facades\Process;

/**
 * Gives each iOS simulator or device its own hot reload port.
 *
 * Every simulator app shares the Mac's network stack, and a physical device
 * is reached through iproxy listening on a Mac port, so a fixed port let one
 * app on the whole machine hot reload at a time. Instead each target gets a
 * free port when its app is launched (simulator) or watched (device), and the
 * port is recorded against the target so `native:watch` reloads the right app.
 */
trait ManagesIosHotReloadPort
{
    /**
     * Where the app listens when it hasn't been given a port: an app launched
     * outside `native:run`, one whose Xcode project predates per-target ports,
     * and every app on a physical device, where iproxy maps a free Mac port
     * onto this one.
     */
    protected const IOS_DEFAULT_HOT_RELOAD_PORT = 9999;

    /**
     * Environment variable the app reads its port from. Must match
     * `HotReloadServer.portKey` in resources/xcode/NativePHP/HotReloadServer.swift.
     */
    protected const IOS_HOT_RELOAD_PORT_KEY = 'NATIVEPHP_HOT_RELOAD_PORT';

    /**
     * One file per simulator or device UDID, holding that target's port.
     */
    protected string $iosHotReloadPortsPath = 'nativephp/ios-hot-reload-ports';

    /**
     * A free Mac port for $target: the one it had last time while nothing
     * else holds it, so a relaunch keeps the port a running watcher knows,
     * and otherwise a new one.
     */
    protected function pickIosHotReloadPort(string $target): int
    {
        $port = $this->recordedIosHotReloadPort($target);

        if ($port === null || $this->isHostPortInUse($port)) {
            return $this->freeHostPort();
        }

        return $port;
    }

    protected function recordedIosHotReloadPort(string $target): ?int
    {
        $path = $this->iosHotReloadPortFile($target);

        if (! is_file($path)) {
            return null;
        }

        $port = (int) trim((string) file_get_contents($path));

        return $port > 0 && $port <= 65535 ? $port : null;
    }

    protected function recordIosHotReloadPort(string $target, int $port): void
    {
        $path = $this->iosHotReloadPortFile($target);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, (string) $port);
    }

    /**
     * Whether the app in this Xcode project reads its port from the launch
     * environment. Projects installed before it did always listen on the
     * default port until `native:install ios` copies the new sources in.
     */
    protected function iosAppTakesHotReloadPort(): bool
    {
        $server = base_path('nativephp/ios/NativePHP/HotReloadServer.swift');

        return is_file($server) && str_contains((string) file_get_contents($server), self::IOS_HOT_RELOAD_PORT_KEY);
    }

    /**
     * Whether anything on this Mac is listening on $port: a simulator app,
     * an iproxy tunnel, or anything else.
     */
    protected function isHostPortInUse(int $port): bool
    {
        return trim(Process::run(['lsof', '-nP', "-iTCP:{$port}", '-sTCP:LISTEN', '-t'])->output()) !== '';
    }

    /**
     * A port the OS hands out as unused. The OS only checks the address it
     * binds, so the port is checked again for a listener on any address.
     * The OS picks at random from its ephemeral range, which keeps two runs
     * started at the same moment from choosing the same port.
     */
    protected function freeHostPort(): int
    {
        $port = self::IOS_DEFAULT_HOT_RELOAD_PORT;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $socket = @stream_socket_server('tcp://127.0.0.1:0');

            if ($socket === false) {
                break;
            }

            $address = (string) stream_socket_get_name($socket, false);
            fclose($socket);

            $port = (int) substr($address, strrpos($address, ':') + 1);

            if (! $this->isHostPortInUse($port)) {
                break;
            }
        }

        return $port;
    }

    private function iosHotReloadPortFile(string $target): string
    {
        return base_path($this->iosHotReloadPortsPath.'/'.basename($target));
    }
}
