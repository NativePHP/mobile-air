<?php

namespace Native\Mobile\Concerns;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Starts the devtools exception listener alongside watch mode and provisions
 * the running app with the endpoint it should report exceptions to.
 *
 * Everything here is a soft integration: without the nativephp/devtools
 * plugin (which provides native:devtools:listen) or with --no-devtools,
 * watch behaves exactly as before. The endpoint file this pushes —
 * storage/framework/devtools.json on the device — is also the debug gate:
 * only watch ever provisions it, so release builds never report.
 */
trait ManagesDevtoolsListener
{
    protected ?SymfonyProcess $devtoolsListenerProcess = null;

    protected ?array $devtoolsListener = null;

    protected int $devtoolsEventsOffset = 0;

    protected function devtoolsEnabled(): bool
    {
        if ($this->hasOption('no-devtools') && $this->option('no-devtools')) {
            return false;
        }

        return array_key_exists('native:devtools:listen', Artisan::all());
    }

    /**
     * Spawn the listener and read back its handshake (port/token/session
     * from nativephp/devtools/listener.json). Idempotent; returns the
     * handshake or null when the listener could not be started.
     */
    protected function startDevtoolsListener(bool $bindAllInterfaces = false): ?array
    {
        if ($this->devtoolsListener !== null) {
            return $this->devtoolsListener;
        }

        $port = (int) config('nativephp.devtools.port', 9210);
        $host = $bindAllInterfaces ? '0.0.0.0' : '127.0.0.1';

        $handshakePath = base_path('nativephp/devtools/listener.json');

        // A listener from a previous session may still be running. Nothing
        // stops it on Ctrl-C, so this is the common path from the second
        // watch onward — it must adopt cleanly rather than half-adopt.
        if (is_file($handshakePath)) {
            $existing = json_decode((string) file_get_contents($handshakePath), true);
            $alive = is_array($existing) && ! empty($existing['pid'])
                && function_exists('posix_kill') && posix_kill((int) $existing['pid'], 0);

            // A loopback listener cannot serve a physical device provisioned
            // with the Mac's LAN address — every POST would fail silently.
            // 0.0.0.0 covers both, so only the loopback -> LAN upgrade has to
            // replace the incumbent, and the common simulator path never does.
            if ($alive && $this->devtoolsHostCovers($existing['host'] ?? null, $host)) {
                $this->seedDevtoolsEventsOffset();

                return $this->devtoolsListener = $existing;
            }

            if ($alive) {
                $this->watchNote(sprintf(
                    '<fg=yellow>devtools: replacing the listener on %s — this device needs %s</>',
                    $existing['host'] ?? 'an unknown host',
                    $host,
                ));

                // It still holds the port; a second bind would just fail.
                @posix_kill((int) $existing['pid'], SIGTERM);
                usleep(200_000);
            }

            @unlink($handshakePath);
        }

        $process = new SymfonyProcess(
            [PHP_BINARY, 'artisan', 'native:devtools:listen', "--host={$host}", "--port={$port}"],
            base_path(),
        );
        $process->setTimeout(null);
        $process->start();

        // The listener writes its handshake file once it is accepting.
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            if (is_file($handshakePath)) {
                $handshake = json_decode((string) file_get_contents($handshakePath), true);

                if (is_array($handshake) && ! empty($handshake['token'])) {
                    $this->devtoolsListenerProcess = $process;
                    $this->seedDevtoolsEventsOffset();
                    $this->watchNote("<fg=cyan>devtools listener on {$host}:{$port} — exceptions will stream here</>");

                    return $this->devtoolsListener = $handshake;
                }
            }

            if (! $process->isRunning()) {
                break;
            }

            usleep(100_000);
        }

        $this->watchNote('<fg=yellow>devtools listener failed to start — exception streaming disabled</>');

        return null;
    }

    /**
     * Whether a listener already bound to $bound can serve traffic that
     * needs $required. 0.0.0.0 accepts on every interface, so it covers
     * anything; a loopback bind only covers loopback.
     */
    protected function devtoolsHostCovers(?string $bound, string $required): bool
    {
        if ($bound === null) {
            return false;
        }

        return $bound === '0.0.0.0' || $bound === $required;
    }

    protected function devtoolsDeviceConfig(string $endpoint, array $handshake): string
    {
        return json_encode([
            'v' => 1,
            'endpoint' => $endpoint,
            'token' => $handshake['token'],
            'session' => $handshake['session'] ?? null,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * iOS simulator: the sim shares the Mac's loopback, and the app container
     * is a plain host directory — write the endpoint file straight into the
     * runtime storage path.
     */
    protected function provisionDevtoolsIosSimulator(string $container): void
    {
        if (! $this->devtoolsEnabled() || ! ($handshake = $this->startDevtoolsListener())) {
            return;
        }

        $dir = $container.'/Library/Application Support/storage/framework';

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true)) {
            return;
        }

        @file_put_contents(
            $dir.'/devtools.json',
            $this->devtoolsDeviceConfig('http://127.0.0.1:'.($handshake['port'] ?? 9210), $handshake),
        );
    }

    /**
     * Physical iOS device: it reaches the Mac over the LAN, so the listener
     * binds all interfaces and the endpoint uses the Mac's LAN address.
     */
    protected function provisionDevtoolsIosDevice(string $target, string $appId): void
    {
        if (! $this->devtoolsEnabled()) {
            return;
        }

        $ip = $this->detectLanIp();

        if ($ip === null) {
            $this->watchNote('<fg=yellow>devtools: could not detect a LAN IP — exception streaming disabled for this device</>');

            return;
        }

        if (! ($handshake = $this->startDevtoolsListener(bindAllInterfaces: true))) {
            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'devtools');
        file_put_contents($tmp, $this->devtoolsDeviceConfig('http://'.$ip.':'.($handshake['port'] ?? 9210), $handshake));

        $this->copyToIosDevice($tmp, 'Library/Application Support/storage/framework/devtools.json', $target, $appId);

        @unlink($tmp);
    }

    /**
     * Android: adb reverse makes the device's localhost:port reach the Mac,
     * exactly like the existing Vite forwarding.
     */
    protected function provisionDevtoolsAndroid(string $appId): void
    {
        if (! $this->devtoolsEnabled() || ! ($handshake = $this->startDevtoolsListener())) {
            return;
        }

        $port = (int) ($handshake['port'] ?? 9210);

        $reverse = $this->adb('reverse', "tcp:{$port}", "tcp:{$port}");
        $reverse->setTimeout(10);
        $reverse->run();

        $tmp = tempnam(sys_get_temp_dir(), 'devtools');
        file_put_contents($tmp, $this->devtoolsDeviceConfig('http://127.0.0.1:'.$port, $handshake));

        $remoteTmp = '/data/local/tmp/nativephp_devtools.json';
        $push = $this->adb('push', $tmp, $remoteTmp);
        $push->setTimeout(15);
        $push->run();

        if ($push->isSuccessful()) {
            $storage = 'app_storage/persisted_data/storage/framework';
            $this->adb('shell', 'run-as', $appId, 'mkdir', '-p', $storage)->run();
            $this->adb('shell', 'run-as', $appId, 'cp', $remoteTmp, $storage.'/devtools.json')->run();
        }

        // The staged copy carries the bearer token and /data/local/tmp is
        // world-readable, so it must not outlive the copy into the sandbox.
        $this->adb('shell', 'rm', '-f', $remoteTmp)->run();

        @unlink($tmp);
    }

    /**
     * Re-assert the devtools adb reverse; piggybacks on the existing
     * periodic adb health check.
     */
    protected function reassertDevtoolsReverse(): void
    {
        if ($this->devtoolsListener === null) {
            return;
        }

        $port = (int) ($this->devtoolsListener['port'] ?? 9210);
        $this->adb('reverse', "tcp:{$port}", "tcp:{$port}")->run();
    }

    /**
     * The Mac's LAN address, for physical devices that reach the listener
     * over the network rather than a forwarded port.
     */
    protected function detectLanIp(): ?string
    {
        foreach (['en0', 'en1'] as $interface) {
            $ip = trim((string) shell_exec('ipconfig getifaddr '.$interface.' 2>/dev/null'));

            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return null;
    }

    /**
     * Combined onTick for watchers whose tick callback was previously just
     * pumpWatchTerminal().
     */
    protected function pumpWatchTerminalAndDevtools(): void
    {
        $this->pumpWatchTerminal();
        $this->pumpDevtoolsEvents();
    }

    protected function seedDevtoolsEventsOffset(): void
    {
        $path = base_path('nativephp/devtools/events.jsonl');
        $this->devtoolsEventsOffset = is_file($path) ? (int) filesize($path) : 0;
    }

    /**
     * Surface any events appended since the last tick in the watch console.
     * Just another reader of the event log — the listener owns the file.
     */
    protected function pumpDevtoolsEvents(): void
    {
        if ($this->devtoolsListener === null) {
            return;
        }

        $path = base_path('nativephp/devtools/events.jsonl');

        clearstatcache(true, $path);

        if (! is_file($path) || ($size = (int) filesize($path)) <= $this->devtoolsEventsOffset) {
            return;
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            return;
        }

        fseek($handle, $this->devtoolsEventsOffset);
        $chunk = (string) stream_get_contents($handle);
        fclose($handle);

        $this->devtoolsEventsOffset = $size;

        foreach (explode("\n", trim($chunk)) as $line) {
            if ($line === '' || ! ($event = json_decode($line, true))) {
                continue;
            }

            $this->watchNote($this->formatDevtoolsEvent($event));
        }
    }

    protected function formatDevtoolsEvent(array $event): string
    {
        $exception = $event['exception'] ?? [];
        $class = class_basename($exception['class'] ?? ($event['kind'] ?? 'exception'));
        $origin = $exception['app_frame']
            ?? (isset($exception['file']) ? $exception['file'].':'.($exception['line'] ?? '?') : null);
        $message = trim((string) ($exception['message'] ?? ''));

        if (mb_strlen($message) > 120) {
            $message = mb_substr($message, 0, 117).'…';
        }

        return sprintf(
            '<fg=red>✖ %s%s</>%s',
            $class,
            $origin ? " in {$origin}" : '',
            $message !== '' ? " — {$message}" : '',
        );
    }
}
