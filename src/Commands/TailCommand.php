<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Native\Mobile\Concerns\ResolvesDeviceTargets;
use Symfony\Component\Process\Process as SymfonyProcess;

class TailCommand extends Command
{
    use ResolvesDeviceTargets;

    protected $signature = 'native:tail
        {os? : Platform (ios or android)}
        {--device= : Device UDID / serial}
        {--lines=50 : Number of trailing lines to print}
        {--follow : Stream the log continuously (previous default)}
        {--fatals : Show crashes recorded before Laravel could report them, instead of laravel.log}
        {--json : Machine-readable output (implies no --follow)}';

    protected $description = 'Show the app\'s Laravel log, or the crashes it recorded, from a device or simulator';

    /** Under the app's storage directory. Written by bootstrap/shared/devtools-boot-report.php. */
    public const FATALS_SPOOL = 'framework/devtools/spool.jsonl';

    public const LARAVEL_LOG = 'logs/laravel.log';

    public function handle(): int
    {
        $appId = config('nativephp.app_id');

        if (empty($appId)) {
            return $this->outputResult([
                'ok' => false,
                'error' => 'missing_app_id',
                'hint' => 'Add NATIVEPHP_APP_ID to your .env file (e.g. com.example.myapp).',
            ]);
        }

        $target = $this->resolveDeviceTarget($this->argument('os'), $this->option('device'));

        if (! $target['ok']) {
            return $this->outputResult($target);
        }

        $lines = max(1, (int) $this->option('lines'));
        $follow = $this->option('follow') && ! $this->option('json');

        return $target['platform'] === 'ios'
            ? $this->tailIos($target['udid'], $appId, $lines, $follow)
            : $this->tailAndroid($target['udid'], $appId, $lines, $follow);
    }

    private function tailIos(string $udid, string $appId, int $lines, bool $follow): int
    {
        $logPath = $this->iosStorageFile($udid, $appId, $this->storageFile());

        if ($logPath === null) {
            // No spool only means nothing has crashed yet.
            if ($this->option('fatals') && $this->iosAppDataContainer($udid, $appId) !== null) {
                return $this->emitLines(self::FATALS_SPOOL, '');
            }

            return $this->outputResult([
                'ok' => false,
                'error' => 'log_not_found',
                'hint' => 'No '.basename($this->storageFile())." found for {$appId} — is the app installed on this simulator and has it booted at least once?",
            ]);
        }

        if ($follow) {
            $this->info("🍏 Tailing {$logPath}");
            $this->line("Press Ctrl+C to stop...\n");
            $this->streamProcess(['tail', '-n', (string) $lines, '-f', $logPath]);

            return 0;
        }

        $result = Process::run(['tail', '-n', (string) $lines, $logPath]);

        return $this->emitLines($logPath, $result->output());
    }

    private function tailAndroid(string $serial, string $appId, int $lines, bool $follow): int
    {
        $logPath = 'app_storage/persisted_data/storage/'.$this->storageFile();

        if ($follow) {
            $this->info("🤖 Tailing Android logs for app: {$appId}");
            $this->line("Press Ctrl+C to stop...\n");
            $this->streamProcess([
                'adb', '-s', $serial, 'shell', 'run-as', $appId,
                'tail', '-n', (string) $lines, '-f', $logPath,
            ]);

            return 0;
        }

        $result = Process::run([
            'adb', '-s', $serial, 'shell', 'run-as', $appId,
            'tail', '-n', (string) $lines, $logPath,
        ]);

        if (! $result->successful()) {
            // No spool only means nothing has crashed yet.
            if ($this->option('fatals') && str_contains($result->errorOutput().$result->output(), 'No such file')) {
                return $this->emitLines($logPath, '');
            }

            return $this->outputResult([
                'ok' => false,
                'error' => 'log_not_found',
                'detail' => trim($result->errorOutput()),
                'hint' => "Could not read {$logPath} — is the app installed as a debug build?",
            ]);
        }

        return $this->emitLines($logPath, $result->output());
    }

    private function storageFile(): string
    {
        return $this->option('fatals') ? self::FATALS_SPOOL : self::LARAVEL_LOG;
    }

    private function emitLines(string $path, string $output): int
    {
        $lines = array_values(array_filter(explode("\n", rtrim($output, "\n")), fn ($l) => $l !== ''));

        if ($this->option('fatals')) {
            return $this->emitFatals($path, $lines);
        }

        if ($this->option('json')) {
            return $this->outputResult(['ok' => true, 'path' => $path, 'lines' => $lines]);
        }

        foreach ($lines as $line) {
            $this->line($line);
        }

        return 0;
    }

    /**
     * One event per spool line. A line that doesn't decode is the tail of a
     * write the crash cut short, and is skipped.
     *
     * @param  list<string>  $lines
     */
    private function emitFatals(string $path, array $lines): int
    {
        $events = array_values(array_filter(
            array_map(fn (string $line) => json_decode($line, true), $lines),
            'is_array',
        ));

        if ($this->option('json')) {
            return $this->outputResult(['ok' => true, 'path' => $path, 'events' => $events]);
        }

        if ($events === []) {
            $this->line('No crashes recorded.');

            return 0;
        }

        foreach ($events as $event) {
            $exception = $event['exception'] ?? [];
            $where = $exception['app_frame'] ?? (($exception['file'] ?? '?').':'.($exception['line'] ?? '?'));

            $this->line(sprintf(
                '%s  %s  %s: %s',
                $event['ts'] ?? '?',
                $event['kind'] ?? '?',
                $exception['class'] ?? '?',
                $exception['message'] ?? '',
            ));
            $this->line('    at '.$where);
        }

        return 0;
    }

    private function streamProcess(array $command): void
    {
        $process = new SymfonyProcess($command);
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
        }
    }
}
