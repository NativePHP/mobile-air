<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Native\Mobile\Traits\PromptsAndroidTarget;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\select;

class TailCommand extends Command
{
    use PromptsAndroidTarget;

    const LOG_DIR = 'app_storage/persisted_data/storage/logs';

    protected $signature = 'native:tail {udid?}';

    protected $description = 'Tail Laravel logs from the Android app';

    public function handle(): void
    {
        $appId = config('nativephp.app_id');

        if (empty($appId)) {
            $this->error('🚫 NATIVEPHP_APP_ID is not set');
            $this->line('Please add a NATIVEPHP_APP_ID to your .env file (e.g. com.example.myapp).');

            return;
        }

        $this->tailAndroid($appId);
    }

    private function tailAndroid(string $appId): void
    {
        $this->info("🤖 Tailing Android logs for app: $appId");
        $this->line("Press Ctrl+C to stop...\n");

        $target = $this->argument('udid') ?? $this->promptForAndroidTarget();

        $logFile = $this->promptForLogFile($target, $appId);

        $command = [
            'adb', '-s', $target, 'shell', 'run-as', $appId, 'tail', '-f',
            self::LOG_DIR.'/'.$logFile,
        ];

        $process = new Process($command);
        $process->setTimeout(null);

        try {
            $process->start();

            foreach ($process as $type => $data) {
                if ($process::OUT === $type) {
                    $this->line($data, null, null, false);
                } else {
                    $this->error($data, null, null, false);
                }
            }
        } catch (\Exception $e) {
            $this->error("❌ Error running tail command: {$e->getMessage()}");
            $this->line('Make sure:');
            $this->line('• ADB is installed and in your PATH');
            $this->line('• An Android device/emulator is connected');
            $this->line('• The app is installed and running');
        }
    }

    private function promptForLogFile(string $target, $appId): string
    {
        $command = [
            'adb', '-s', $target, 'shell', 'run-as', $appId, 'ls', self::LOG_DIR,
        ];

        $process = new Process($command);
        $process->run();
        $output = $process->getOutput();

        /** @var Collection<int, string> $logFiles */
        $logFiles = collect(explode(PHP_EOL, $output))
            ->filter(fn (string $line) => Str::endsWith($line, '.log'));

        if ($logFiles->count() === 1) {
            return $logFiles->first();
        }

        return select('Select a log file', $logFiles);
    }
}
