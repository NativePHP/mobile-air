<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Native\Mobile\Traits\LaunchesAndroidEmulator;

class LaunchEmulatorCommand extends Command
{
    use LaunchesAndroidEmulator;

    protected $signature = 'native:emulator {os : Platform to emulate (android/a or ios/i)}';

    protected $description = 'List and launch an emulator';

    public function handle(): void
    {
        $os = match (strtolower($this->argument('os'))) {
            'android', 'a' => 'android',
            'ios', 'i' => 'ios',
            default => throw new \Exception('Invalid OS type.')
        };

        // iOS simulators are macOS-only tooling — fail fast on Windows/Linux
        // instead of silently falling through to the Android flow.
        if ($os === 'ios' && PHP_OS_FAMILY !== 'Darwin') {
            $this->error('iOS simulators require macOS with Xcode installed. Use `php artisan native:emulator android` on this platform.');

            return;
        }

        match ($os) {
            'android' => $this->startAndroid(),
            'ios' => $this->startAndroid(),
        };
    }
}
