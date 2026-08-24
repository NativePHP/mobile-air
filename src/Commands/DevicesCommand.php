<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Native\Mobile\Concerns\ResolvesDeviceTargets;

class DevicesCommand extends Command
{
    use ResolvesDeviceTargets;

    protected $signature = 'native:devices {os? : Limit to a platform (ios or android)} {--json : Machine-readable output}';

    protected $description = 'List available iOS and Android devices, simulators, and emulators';

    public function handle(): int
    {
        $platform = $this->argument('os') ? strtolower($this->argument('os')) : null;

        if ($platform && ! in_array($platform, ['ios', 'android'], true)) {
            return $this->outputResult(['ok' => false, 'error' => 'invalid_platform', 'detail' => $platform]);
        }

        $devices = [];

        if ($platform !== 'android') {
            $devices = array_merge($devices, $this->listIosDevices());
        }

        if ($platform !== 'ios') {
            $devices = array_merge($devices, $this->listAndroidDevices());
        }

        if ($this->option('json')) {
            return $this->outputResult(['ok' => true, 'devices' => $devices]);
        }

        if (empty($devices)) {
            $this->line('No devices found.');

            return 0;
        }

        $this->table(
            ['Platform', 'Name', 'Version', 'Id', 'Kind', 'Booted', 'Last used'],
            array_map(fn ($d) => [
                $d['platform'],
                $d['name'] ?? '',
                $d['version'] ?? '',
                $d['udid'],
                $d['kind'] ?? '',
                ($d['booted'] ?? false) ? 'yes' : '',
                ($d['lastUsed'] ?? false) ? 'yes' : '',
            ], $devices),
        );

        return 0;
    }
}
