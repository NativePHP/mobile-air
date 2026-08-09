<?php

namespace Native\Mobile\Concerns;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Deterministic device/platform resolution for headless (agent-driven)
 * commands. Resolution never prompts: an ambiguous target is returned as a
 * structured error so a machine caller can pick a candidate and retry.
 *
 * Resolution order:
 *   1. explicit --device option
 *   2. nativephp/agent-device.json  (a device claimed by an agent session)
 *   3. iOS: nativephp/ios-last-device-id, else the single booted simulator
 *      Android: the single connected device/emulator
 */
trait ResolvesDeviceTargets
{
    protected function resolveDeviceTarget(?string $platform, ?string $explicit): array
    {
        $platform = $this->resolveTargetPlatform($platform);

        if (isset($platform['error'])) {
            return $platform;
        }

        $platform = $platform['platform'];

        if ($explicit) {
            return ['ok' => true, 'platform' => $platform, 'udid' => $explicit];
        }

        if ($claimed = $this->readAgentClaimedDevice()) {
            if (! $platform || $claimed['platform'] === $platform) {
                return ['ok' => true, 'platform' => $claimed['platform'], 'udid' => $claimed['udid']];
            }
        }

        return $platform === 'ios'
            ? $this->resolveIosTarget()
            : $this->resolveAndroidTarget();
    }

    protected function resolveTargetPlatform(?string $platform): array
    {
        if ($platform) {
            $platform = strtolower($platform);

            if (! in_array($platform, ['ios', 'android'], true)) {
                return ['ok' => false, 'error' => 'invalid_platform', 'detail' => $platform];
            }

            return ['platform' => $platform];
        }

        if ($claimed = $this->readAgentClaimedDevice()) {
            return ['platform' => $claimed['platform']];
        }

        $hasIos = is_dir(base_path('nativephp/ios'));
        $hasAndroid = is_dir(base_path('nativephp/android'));

        if ($hasIos xor $hasAndroid) {
            return ['platform' => $hasIos ? 'ios' : 'android'];
        }

        return [
            'ok' => false,
            'error' => 'ambiguous_platform',
            'detail' => $hasIos
                ? 'Both nativephp/ios and nativephp/android exist; pass the platform explicitly.'
                : 'Neither nativephp/ios nor nativephp/android exists; run native:install first.',
        ];
    }

    protected function readAgentClaimedDevice(): ?array
    {
        $path = base_path('nativephp/agent-device.json');

        if (! is_file($path)) {
            return null;
        }

        $claim = json_decode((string) file_get_contents($path), true);

        if (! is_array($claim) || empty($claim['platform']) || empty($claim['udid'])) {
            return null;
        }

        return ['platform' => strtolower($claim['platform']), 'udid' => $claim['udid']];
    }

    protected function resolveIosTarget(): array
    {
        $booted = $this->listBootedIosSimulators();
        $bootedUdids = array_column($booted, 'udid');

        $lastUsed = base_path('nativephp/ios-last-device-id');

        if (is_file($lastUsed) && ($udid = trim((string) file_get_contents($lastUsed))) !== '') {
            // Only honour the remembered device if it is actually usable now.
            // The file records whatever was picked last — possibly weeks ago,
            // possibly a simulator that has since been shut down or deleted.
            // Trusting it blindly meant screenshot/status/tail/run silently
            // targeting a dead device and reporting "not installed" about a
            // machine the app was never on.
            if (in_array($udid, $bootedUdids, true) || $this->isConnectedIosDevice($udid)) {
                return ['ok' => true, 'platform' => 'ios', 'udid' => $udid];
            }
        }

        if (count($booted) === 1) {
            return ['ok' => true, 'platform' => 'ios', 'udid' => $booted[0]['udid'], 'kind' => 'simulator'];
        }

        return [
            'ok' => false,
            'error' => count($booted) === 0 ? 'no_device' : 'ambiguous_device',
            'platform' => 'ios',
            'candidates' => $booted ?: $this->listIosDevices(),
            'hint' => count($booted) === 0
                ? 'Boot a simulator (xcrun simctl boot <udid>) or pass --device.'
                : 'Multiple simulators booted; pass --device.',
        ];
    }

    protected function resolveAndroidTarget(): array
    {
        $devices = $this->listAndroidDevices();

        if (count($devices) === 1) {
            return ['ok' => true, 'platform' => 'android', 'udid' => $devices[0]['udid'], 'kind' => $devices[0]['kind']];
        }

        return [
            'ok' => false,
            'error' => count($devices) === 0 ? 'no_device' : 'ambiguous_device',
            'platform' => 'android',
            'candidates' => $devices,
            'hint' => count($devices) === 0
                ? 'Start an emulator (php artisan native:emulator android) or connect a device.'
                : 'Multiple Android devices connected; pass --device.',
        ];
    }

    /**
     * Whether this udid is a physical iOS device currently attached. Keeps a
     * remembered real device usable — it will never appear in the booted
     * simulator list.
     */
    protected function isConnectedIosDevice(string $udid): bool
    {
        foreach ($this->listIosDevices() as $device) {
            if (($device['udid'] ?? null) === $udid && ($device['kind'] ?? null) === 'device') {
                return true;
            }
        }

        return false;
    }

    /**
     * All iOS devices and simulators, exit-free (unlike RunsIos::getAvailableIosDevices).
     */
    protected function listIosDevices(): array
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return [];
        }

        $output = Process::run('xcrun xctrace list devices')->output();
        $booted = collect($this->listBootedIosSimulators())->pluck('udid')->all();
        $lastUsedPath = base_path('nativephp/ios-last-device-id');
        $lastUsed = is_file($lastUsedPath) ? trim((string) file_get_contents($lastUsedPath)) : null;

        $category = null;
        $devices = [];

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (Str::startsWith($line, '==')) {
                $category = Str::between($line, '== ', ' ==');

                continue;
            }

            if ($category === null || str_contains($category, 'Offline')) {
                continue;
            }

            preg_match('/^(.+?)(?:\s+\(([^)]+)\))?\s+\(([^)]+)\)$/', $line, $matches);

            if (count($matches) !== 4) {
                continue;
            }

            [, $name, $version, $udid] = $matches;

            // xctrace lists the host Mac under Devices with a UUID-style id; skip it.
            if (Str::isUuid($udid) && $category === 'Devices') {
                continue;
            }

            if (! in_array($category, ['Devices', 'Simulators'], true)) {
                continue;
            }

            $devices[] = [
                'platform' => 'ios',
                'name' => $name,
                'version' => $version,
                'udid' => $udid,
                'kind' => $category === 'Simulators' ? 'simulator' : 'device',
                'booted' => in_array($udid, $booted, true),
                'lastUsed' => $udid === $lastUsed,
            ];
        }

        return $devices;
    }

    protected function listBootedIosSimulators(): array
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return [];
        }

        $result = Process::run(['xcrun', 'simctl', 'list', 'devices', 'booted', '-j']);

        if (! $result->successful()) {
            return [];
        }

        $parsed = json_decode($result->output(), true);
        $booted = [];

        foreach ($parsed['devices'] ?? [] as $runtime => $devices) {
            foreach ($devices as $device) {
                if (($device['state'] ?? null) === 'Booted') {
                    $booted[] = [
                        'platform' => 'ios',
                        'name' => $device['name'] ?? '',
                        'udid' => $device['udid'] ?? '',
                        'kind' => 'simulator',
                        'booted' => true,
                        'runtime' => $runtime,
                    ];
                }
            }
        }

        return $booted;
    }

    protected function listAndroidDevices(): array
    {
        $output = Process::run('adb devices')->output();
        $devices = [];

        foreach (explode("\n", $output) as $line) {
            if (! str_contains($line, "\tdevice")) {
                continue;
            }

            $serial = explode("\t", trim($line))[0];

            $devices[] = [
                'platform' => 'android',
                'udid' => $serial,
                'kind' => str_starts_with($serial, 'emulator-') ? 'emulator' : 'device',
                'booted' => true,
            ];
        }

        return $devices;
    }

    /**
     * The app's data container on an iOS simulator, or null when the app
     * isn't installed / the target isn't a simulator on this Mac.
     */
    protected function iosAppDataContainer(string $udid, string $appId): ?string
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return null;
        }

        $result = Process::run(['xcrun', 'simctl', 'get_app_container', $udid, $appId, 'data']);

        if (! $result->successful()) {
            return null;
        }

        $path = trim($result->output());

        return $path !== '' && is_dir($path) ? $path : null;
    }

    /**
     * Locate a file under the app's on-device storage dir. On iOS, Laravel's
     * storage_path() maps to <container>/Library/Application Support/storage.
     */
    protected function iosStorageFile(string $udid, string $appId, string $relative): ?string
    {
        if (! $container = $this->iosAppDataContainer($udid, $appId)) {
            return null;
        }

        $candidates = [
            $container.'/Library/Application Support/storage/'.$relative,
            $container.'/Documents/storage/'.$relative,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $matches = glob($container.'/*/storage/'.$relative) ?: [];

        return $matches[0] ?? null;
    }

    /**
     * Probe whether the app is installed and currently running on the target.
     * Returns ['installed' => bool, 'running' => bool, 'pid' => ?int].
     */
    protected function probeAppProcess(string $platform, string $udid, string $appId): array
    {
        if ($platform === 'ios') {
            $installed = $this->iosAppDataContainer($udid, $appId) !== null;
            $pid = null;

            if ($installed) {
                $launchctl = Process::run(['xcrun', 'simctl', 'spawn', $udid, 'launchctl', 'list']);

                if ($launchctl->successful()) {
                    foreach (explode("\n", $launchctl->output()) as $line) {
                        if (str_contains($line, $appId)) {
                            $columns = preg_split('/\s+/', trim($line));

                            if (isset($columns[0]) && ctype_digit($columns[0])) {
                                $pid = (int) $columns[0];
                            }

                            break;
                        }
                    }
                }
            }

            return ['installed' => $installed, 'running' => $pid !== null, 'pid' => $pid];
        }

        $pmPath = Process::run(['adb', '-s', $udid, 'shell', 'pm', 'path', $appId]);
        $installed = $pmPath->successful() && str_contains($pmPath->output(), 'package:');
        $pid = null;

        if ($installed) {
            $pidof = Process::run(['adb', '-s', $udid, 'shell', 'pidof', $appId]);
            $raw = trim($pidof->output());

            if ($raw !== '' && ctype_digit(explode(' ', $raw)[0])) {
                $pid = (int) explode(' ', $raw)[0];
            }
        }

        return ['installed' => $installed, 'running' => $pid !== null, 'pid' => $pid];
    }

    /**
     * Emit a structured result in --json mode (single object, last line of
     * stdout) or a human-readable rendering otherwise. Returns the exit code.
     */
    protected function outputResult(array $result): int
    {
        if ($this->option('json')) {
            $this->output->writeln(json_encode($result, JSON_UNESCAPED_SLASHES));
        } elseif (($result['ok'] ?? false) === false) {
            $this->error($result['error'] ?? 'failed');

            if (! empty($result['hint'])) {
                $this->line($result['hint']);
            }

            foreach ($result['candidates'] ?? [] as $candidate) {
                $this->line('  - '.($candidate['name'] ?? '').' '.($candidate['udid'] ?? ''));
            }
        }

        return ($result['ok'] ?? false) ? 0 : 1;
    }
}
