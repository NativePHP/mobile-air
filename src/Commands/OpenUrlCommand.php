<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Native\Mobile\Concerns\ResolvesDeviceTargets;

class OpenUrlCommand extends Command
{
    use ResolvesDeviceTargets;

    protected $signature = 'native:open-url
        {url : Deep link URL or app path (a bare path like /settings uses the configured deeplink scheme)}
        {os? : Platform (ios or android)}
        {--device= : Device UDID / serial}
        {--json : Machine-readable output}';

    protected $description = 'Navigate the running app to a URL via its deep link scheme';

    public function handle(): int
    {
        $target = $this->resolveDeviceTarget($this->argument('os'), $this->option('device'));

        if (! $target['ok']) {
            return $this->outputResult($target);
        }

        $url = $this->argument('url');

        // Bare app paths get expanded through the configured scheme so agents
        // can say `native:open-url /settings` without knowing the scheme.
        if (! str_contains($url, '://')) {
            $scheme = config('nativephp.deeplink_scheme');

            if (empty($scheme)) {
                return $this->outputResult([
                    'ok' => false,
                    'error' => 'no_deeplink_scheme',
                    'hint' => 'Set NATIVEPHP_DEEPLINK_SCHEME in .env (and rebuild) or pass a full scheme:// URL.',
                ]);
            }

            $url = $scheme.'://'.ltrim($url, '/');
        }

        $result = $target['platform'] === 'ios'
            ? Process::run(['xcrun', 'simctl', 'openurl', $target['udid'], $url])
            : Process::run(['adb', '-s', $target['udid'], 'shell', 'am', 'start', '-a', 'android.intent.action.VIEW', '-d', $url]);

        if (! $result->successful()) {
            return $this->outputResult([
                'ok' => false,
                'error' => 'open_url_failed',
                'detail' => trim($result->errorOutput()) ?: trim($result->output()),
                'url' => $url,
            ]);
        }

        if (! $this->option('json')) {
            $this->info("Opened {$url}");
        }

        return $this->outputResult([
            'ok' => true,
            'url' => $url,
            'platform' => $target['platform'],
            'device' => $target['udid'],
        ]);
    }
}
