<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Native\Mobile\Concerns\ResolvesDeviceTargets;

class ScreenshotCommand extends Command
{
    use ResolvesDeviceTargets;

    protected $signature = 'native:screenshot
        {os? : Platform (ios or android)}
        {--device= : Device UDID / serial (defaults to the resolved target)}
        {--out= : Output path for the PNG}
        {--json : Machine-readable output}';

    protected $description = 'Capture a screenshot of the running app on a simulator, emulator, or Android device';

    public function handle(): int
    {
        $target = $this->resolveDeviceTarget($this->argument('os'), $this->option('device'));

        if (! $target['ok']) {
            return $this->outputResult($target);
        }

        $out = $this->option('out') ?: base_path(sprintf(
            'nativephp/screenshots/%s-%s.png',
            $target['platform'],
            date('Ymd-His'),
        ));

        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0755, true);
        }

        $result = $target['platform'] === 'ios'
            ? $this->captureIos($target['udid'], $out)
            : $this->captureAndroid($target['udid'], $out);

        if (! $result['ok']) {
            return $this->outputResult($result + ['platform' => $target['platform'], 'device' => $target['udid']]);
        }

        if (! $this->option('json')) {
            $this->info("Screenshot saved to {$out}");
        }

        return $this->outputResult([
            'ok' => true,
            'path' => $out,
            'platform' => $target['platform'],
            'device' => $target['udid'],
        ]);
    }

    private function captureIos(string $udid, string $out): array
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return ['ok' => false, 'error' => 'unsupported_host', 'detail' => 'iOS screenshots require macOS.'];
        }

        $result = Process::run(['xcrun', 'simctl', 'io', $udid, 'screenshot', $out]);

        if (! $result->successful()) {
            return [
                'ok' => false,
                'error' => 'screenshot_failed',
                'detail' => trim($result->errorOutput()) ?: 'simctl io screenshot failed (simulators only).',
            ];
        }

        return ['ok' => true];
    }

    private function captureAndroid(string $serial, string $out): array
    {
        $result = Process::run(['adb', '-s', $serial, 'exec-out', 'screencap', '-p']);

        if (! $result->successful() || $result->output() === '') {
            return [
                'ok' => false,
                'error' => 'screenshot_failed',
                'detail' => trim($result->errorOutput()) ?: 'adb screencap produced no output.',
            ];
        }

        file_put_contents($out, $result->output());

        return ['ok' => true];
    }
}
