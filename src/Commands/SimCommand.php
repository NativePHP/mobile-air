<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class SimCommand extends Command
{
    protected $signature = 'native:sim {target=data : What to do (data, app, or uninstall)} {--bundle-id= : Override the app bundle identifier}';

    protected $description = 'Inspect or manage the app on the booted iOS simulator';

    public function handle(): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->error('native:sim is only supported on macOS.');

            return Command::FAILURE;
        }

        $target = strtolower($this->argument('target'));

        if (! in_array($target, ['data', 'app', 'uninstall'], true)) {
            $this->error("Invalid target '{$target}'. Use 'data', 'app', or 'uninstall'.");

            return Command::FAILURE;
        }

        $bundleId = $this->option('bundle-id') ?: config('nativephp.app_id');

        if (! $bundleId) {
            $this->error('No bundle id found. Set NATIVEPHP_APP_ID or pass --bundle-id.');

            return Command::FAILURE;
        }

        if ($target === 'uninstall') {
            return $this->uninstall($bundleId);
        }

        return $this->openContainer($target, $bundleId);
    }

    private function openContainer(string $target, string $bundleId): int
    {
        $result = Process::run([
            'xcrun', 'simctl', 'get_app_container', 'booted', $bundleId, $target,
        ]);

        if (! $result->successful()) {
            $this->printSimctlError($result->errorOutput(), $bundleId);

            return Command::FAILURE;
        }

        $path = trim($result->output());

        // For `app`, simctl returns the path to the .app bundle itself.
        // We want to open the parent so the user sees app.zip alongside the .app.
        $pathToOpen = $target === 'app' ? dirname($path) : $path;

        $this->info("Opening last-used simulator's {$target} folder in Finder...");

        Process::run(['open', $pathToOpen]);

        return Command::SUCCESS;
    }

    private function uninstall(string $bundleId): int
    {
        if (! $this->confirm("Uninstall {$bundleId} from the booted simulator?", false)) {
            $this->line('Aborted.');

            return Command::SUCCESS;
        }

        $this->info("Uninstalling {$bundleId} from last-used simulator...");

        $result = Process::run([
            'xcrun', 'simctl', 'uninstall', 'booted', $bundleId,
        ]);

        if (! $result->successful()) {
            $this->printSimctlError($result->errorOutput(), $bundleId);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function printSimctlError(string $stderr, string $bundleId): void
    {
        $stderr = trim($stderr);

        if (str_contains($stderr, 'No such file or directory') || str_contains($stderr, 'code=2')) {
            $this->error("{$bundleId} is not installed on the booted simulator.");

            return;
        }

        if (str_contains($stderr, 'Booted') || str_contains($stderr, 'No devices')) {
            $this->error('No simulator is currently booted.');

            return;
        }

        $this->error($stderr ?: "simctl failed for {$bundleId}.");
    }
}
