<?php

namespace Native\Mobile\Concerns;

use Symfony\Component\Process\Process;

use function Laravel\Prompts\select;

trait LaunchesIosSimulator
{
    public function startIos(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->error('iOS simulators require macOS with Xcode installed.');

            return;
        }

        $check = new Process(['xcrun', '--find', 'simctl']);
        $check->run();

        if (! $check->isSuccessful()) {
            $this->error('❌ Could not locate the iOS simulator tools (xcrun simctl). Install Xcode command line tools.');

            return;
        }

        $devices = $this->resolveIosSimulators();

        if ($devices === null) {
            return;
        }

        if (empty($devices)) {
            $this->error('❌ No iOS simulators found. Create one in Xcode > Settings > Platforms.');

            return;
        }

        $options = [];

        foreach ($devices as $udid => $device) {
            $options[$udid] = sprintf('%s (iOS %s) (%s)', $device['name'], $device['version'], $device['state']);
        }

        $selected = count($options) === 1
            ? array_key_first($options)
            : select(
                label: 'Select a simulator to launch',
                options: $options,
                hint: 'Use arrow keys to navigate'
            );

        $name = $devices[$selected]['name'] ?? $selected;
        $state = $devices[$selected]['state'] ?? 'Shutdown';

        if ($state !== 'Booted') {
            $this->info("🚀 Booting simulator: {$name}");

            $boot = new Process(['xcrun', 'simctl', 'boot', $selected]);
            $boot->setTimeout(120);
            $boot->run();

            if (! $boot->isSuccessful() && ! $this->isIosSimulatorBooted($selected)) {
                $this->error('❌ Failed to boot simulator: '.trim($boot->getErrorOutput() ?: $boot->getOutput()));

                return;
            }

            $ready = new Process(['xcrun', 'simctl', 'bootstatus', $selected, '-b']);
            $ready->setTimeout(180);
            $ready->run();
        } else {
            $this->info("🚀 Launching simulator: {$name}");
        }

        $this->openSimulatorUi($selected, $state !== 'Booted');

        if ($this->isIosSimulatorBooted($selected)) {
            $this->info("✅ Simulator '{$name}' booted successfully! [{$selected}]");
        } else {
            $this->warn('⚠️ Simulator did not finish booting in time.');
        }
    }

    /**
     * Open the simulator UI for the given device, handling both Xcode
     * layouts: Simulator.app (Xcode <= 26) and DeviceHub.app (Xcode 27+,
     * which removed Simulator.app and moved bundles from
     * Contents/Developer/Applications to Contents/Applications).
     */
    protected function openSimulatorUi(string $udid, bool $selectDevice): void
    {
        $ui = $this->resolveSimulatorUi();

        if ($ui === null) {
            $this->warn('⚠️ No simulator UI found (Simulator.app or DeviceHub.app) — install it via Xcode > Settings > Platforms.');

            return;
        }

        if ($ui['kind'] === 'devicehub') {
            $open = new Process(['open', '-a', $ui['path'], "devices://manage/select?id={$udid}"]);
            $open->run();

            if (! $open->isSuccessful()) {
                $this->warn('⚠️ Could not open DeviceHub: '.trim($open->getErrorOutput() ?: $open->getOutput()));
            }

            return;
        }

        $command = ['open', '-a', $ui['path']];

        // Only for a freshly booted device: passing -CurrentDeviceUDID for
        // an already-booted device makes Simulator.app show an
        // "Unable to boot device in current state: Booted" alert.
        if ($selectDevice) {
            array_push($command, '--args', '-CurrentDeviceUDID', $udid);
        }

        $open = new Process($command);
        $open->run();

        if (! $open->isSuccessful()) {
            $this->warn('⚠️ Could not open Simulator.app: '.trim($open->getErrorOutput() ?: $open->getOutput()));

            return;
        }

        // Bring Simulator.app to the foreground (best effort — never fatal).
        $activate = new Process(['osascript', '-e', 'tell application "Simulator" to activate']);
        $activate->run();
    }

    /**
     * @return array{kind: 'simulator'|'devicehub', path: string}|null
     */
    protected function resolveSimulatorUi(): ?array
    {
        // Path check, not a version check: renamed Xcode installs
        // (Xcode-beta.app) break `open -a <name>` LaunchServices lookups,
        // and Apple may move the bundle again. Simulator.app first preserves
        // Xcode <= 26 behaviour; DeviceHub.app covers Xcode 27+.
        $devDir = new Process(['xcode-select', '-p']);
        $devDir->run();

        $xcodeApps = [];

        if ($devDir->isSuccessful() && ($developer = rtrim(trim($devDir->getOutput()), '/')) !== '' && $developer !== '/') {
            $xcodeApps[] = dirname($developer, 2);
        }

        foreach ((array) (glob('/Applications/Xcode*.app') ?: []) as $xcodeApp) {
            $xcodeApps[] = $xcodeApp;
        }

        foreach (array_unique($xcodeApps) as $xcodeApp) {
            if (is_dir($xcodeApp.'/Contents/Developer/Applications/Simulator.app')) {
                return ['kind' => 'simulator', 'path' => $xcodeApp.'/Contents/Developer/Applications/Simulator.app'];
            }
        }

        foreach (array_unique($xcodeApps) as $xcodeApp) {
            if (is_dir($xcodeApp.'/Contents/Applications/DeviceHub.app')) {
                return ['kind' => 'devicehub', 'path' => $xcodeApp.'/Contents/Applications/DeviceHub.app'];
            }
        }

        $home = getenv('HOME');

        foreach (['/Applications/Simulator.app', is_string($home) && $home !== '' ? $home.'/Applications/Simulator.app' : ''] as $fallback) {
            if ($fallback !== '' && is_dir($fallback)) {
                return ['kind' => 'simulator', 'path' => $fallback];
            }
        }

        return null;
    }

    /**
     * @return array<string, array{udid: string, name: string, version: string, state: string}>|null
     */
    protected function resolveIosSimulators(): ?array
    {
        $list = new Process(['xcrun', 'simctl', 'list', 'devices', '-j']);
        $list->run();

        if (! $list->isSuccessful()) {
            $this->error('❌ Failed to list iOS simulators: '.trim($list->getErrorOutput()));

            return null;
        }

        $decoded = json_decode($list->getOutput(), true);

        if (! is_array($decoded) || ! isset($decoded['devices']) || ! is_array($decoded['devices'])) {
            $this->error('❌ Failed to parse iOS simulator list.');

            return null;
        }

        $devices = [];

        foreach ($decoded['devices'] as $runtime => $entries) {
            $version = $this->iosVersionFromRuntime((string) $runtime);

            foreach ((array) $entries as $entry) {
                if (! is_array($entry) || ($entry['isAvailable'] ?? true) !== true) {
                    continue;
                }

                $udid = $entry['udid'] ?? null;

                if (! $udid) {
                    continue;
                }

                $devices[$udid] = [
                    'udid' => $udid,
                    'name' => $entry['name'] ?? $udid,
                    'version' => $version,
                    'state' => $entry['state'] ?? 'Shutdown',
                ];
            }
        }

        return $devices;
    }

    protected function isIosSimulatorBooted(string $udid): bool
    {
        $devices = $this->resolveIosSimulatorsQuietly();

        return ($devices[$udid]['state'] ?? null) === 'Booted';
    }

    /**
     * @return array<string, array{state: string}>
     */
    protected function resolveIosSimulatorsQuietly(): array
    {
        $list = new Process(['xcrun', 'simctl', 'list', 'devices', '-j']);
        $list->run();

        if (! $list->isSuccessful()) {
            return [];
        }

        $decoded = json_decode($list->getOutput(), true);

        if (! is_array($decoded) || ! isset($decoded['devices']) || ! is_array($decoded['devices'])) {
            return [];
        }

        $devices = [];

        foreach ($decoded['devices'] as $entries) {
            foreach ((array) $entries as $entry) {
                if (! is_array($entry) || ! isset($entry['udid'])) {
                    continue;
                }

                $devices[$entry['udid']] = ['state' => $entry['state'] ?? 'Shutdown'];
            }
        }

        return $devices;
    }

    protected function iosVersionFromRuntime(string $runtime): string
    {
        if (preg_match('/iOS-(.+)$/', $runtime, $matches)) {
            return str_replace(['-', '_'], '.', $matches[1]);
        }

        return '?';
    }
}
