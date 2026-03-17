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

        match ($os) {
            'android' => $this->startAndroid(),
            'ios' => $this->startAndroid(),
        };
    }
}
