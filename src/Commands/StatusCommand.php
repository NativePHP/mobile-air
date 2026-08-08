<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Native\Mobile\Concerns\ResolvesDeviceTargets;

class StatusCommand extends Command
{
    use ResolvesDeviceTargets;

    protected $signature = 'native:status
        {os? : Platform (ios or android)}
        {--device= : Device UDID / serial}
        {--json : Machine-readable output}';

    protected $description = 'Report whether the app is installed and running on the target device, with log locations';

    public function handle(): int
    {
        $appId = config('nativephp.app_id');

        if (empty($appId)) {
            return $this->outputResult([
                'ok' => false,
                'error' => 'missing_app_id',
                'hint' => 'Add NATIVEPHP_APP_ID to your .env file.',
            ]);
        }

        $target = $this->resolveDeviceTarget($this->argument('os'), $this->option('device'));

        if (! $target['ok']) {
            return $this->outputResult($target);
        }

        $status = $target['platform'] === 'ios'
            ? $this->iosStatus($target['udid'], $appId)
            : $this->androidStatus($target['udid'], $appId);

        $eventsPath = base_path('nativephp/devtools/events.jsonl');
        $events = ['path' => $eventsPath, 'exists' => is_file($eventsPath)];

        if ($events['exists']) {
            $events['mtime'] = date('c', filemtime($eventsPath));
            $events['lines'] = count(file($eventsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        }

        $status['logs']['exceptions'] = $events;

        $result = array_merge([
            'ok' => true,
            'platform' => $target['platform'],
            'device' => $target['udid'],
            'appId' => $appId,
        ], $status);

        if (! $this->option('json')) {
            $this->line('Platform:  '.$result['platform']);
            $this->line('Device:    '.$result['device']);
            $this->line('Installed: '.($result['installed'] ? 'yes' : 'no'));
            $this->line('Running:   '.($result['running'] ? 'yes (pid '.($result['pid'] ?? '?').')' : 'no'));

            foreach ($result['logs'] as $name => $path) {
                $this->line(sprintf('%-10s %s', ucfirst($name).':', is_array($path) ? $path['path'] : ($path ?? '—')));
            }
        }

        return $this->outputResult($result);
    }

    private function iosStatus(string $udid, string $appId): array
    {
        $probe = $this->probeAppProcess('ios', $udid, $appId);

        $probe['logs'] = [
            'build' => base_path('nativephp/ios-build.log'),
            'laravel' => $probe['installed'] ? $this->iosStorageFile($udid, $appId, 'logs/laravel.log') : null,
        ];

        return $probe;
    }

    private function androidStatus(string $serial, string $appId): array
    {
        $probe = $this->probeAppProcess('android', $serial, $appId);

        $probe['logs'] = [
            'build' => base_path('nativephp/android-build.log'),
            'laravel' => 'device:app_storage/persisted_data/storage/logs/laravel.log',
        ];

        return $probe;
    }
}
