<?php

namespace Native\Mobile\Traits;

use Symfony\Component\Process\Process;

use function Laravel\Prompts\select;

trait LaunchesAndroidEmulator
{
    public function startAndroid()
    {
        $emulatorPath = $this->resolveAndroidEmulatorPath();

        if (! $emulatorPath) {
            $this->error('❌ Could not locate the Android emulator binary.');

            return;
        }

        // Get AVD list
        $listCommand = sprintf('"%s" -list-avds', $emulatorPath);
        $listProcess = Process::fromShellCommandline($listCommand);
        $listProcess->run();

        if (! $listProcess->isSuccessful()) {
            $this->error('❌ Failed to list Android emulators: '.$listProcess->getErrorOutput());

            return;
        }

        $avds = array_filter(explode("\n", trim($listProcess->getOutput())));

        if (empty($avds)) {
            $this->error('❌ No emulators (AVDs) found.');

            return;
        }

        $selected = select(
            label: 'Select an emulator to launch',
            options: $avds,
            hint: 'Use arrow keys to navigate'
        );

        $this->info("🚀 Launching emulator: $selected");

        // Launch emulator detached in background
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: use start /B for background process
            $launchCommand = sprintf('start /B "" "%s" -avd "%s"', $emulatorPath, $selected);
        } else {
            // Unix: use nohup with output redirect
            $escapedPath = escapeshellarg($emulatorPath);
            $escapedAvd = escapeshellarg($selected);
            $launchCommand = "nohup $escapedPath -avd $escapedAvd > /tmp/emulator.log 2>&1 &";
        }
        Process::fromShellCommandline($launchCommand)->start();

        $this->info('⏳ Waiting for emulator to boot...');

        $booted = false;

        for ($i = 0; $i < 200; $i++) { // ~24s
            $bootProcess = Process::fromShellCommandline('adb shell getprop sys.boot_completed');
            $bootProcess->run();
            $bootCompleted = trim($bootProcess->getOutput());

            $readyProcess = Process::fromShellCommandline('adb shell getprop init.svc.bootanim');
            $readyProcess->run();
            $bootAnimStatus = trim($readyProcess->getOutput());

            if ($bootCompleted === '1' && $bootAnimStatus === 'stopped') {
                $booted = true;
                break;
            }

            usleep(120000);
        }

        if ($booted) {
            $this->info("✅ Emulator '$selected' booted successfully!");
        } else {
            $this->warn('⚠️ Emulator did not finish booting in time.');
        }
    }

    protected function resolveAndroidEmulatorPath(): ?string
    {
        // 1. Allow override from config or .env
        $customPath = config('nativephp.android.emulator_path') ?? env('ANDROID_EMULATOR');
        if ($customPath && file_exists($customPath)) {
            return $customPath;
        }

        // 2. Check SDK paths from env vars
        $sdk = env('ANDROID_HOME') ?: env('ANDROID_SDK_ROOT');

        $candidates = [];

        if ($sdk) {
            $candidates[] = $sdk.DIRECTORY_SEPARATOR.'emulator'.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'emulator.exe' : 'emulator');
        }

        // 3. Fallback defaults per OS
        if (PHP_OS_FAMILY === 'Windows') {
            $username = getenv('USERNAME') ?: 'user';
            $candidates[] = "C:\\Users\\{$username}\\AppData\\Local\\Android\\Sdk\\emulator\\emulator.exe";
            $candidates[] = getenv('LOCALAPPDATA').'\\Android\\Sdk\\emulator\\emulator.exe';
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $candidates[] = getenv('HOME').'/Library/Android/sdk/emulator/emulator';
        } else { // Linux
            $candidates[] = getenv('HOME').'/Android/Sdk/emulator/emulator';
        }

        // 4. Return first found
        foreach ($candidates as $path) {
            if ($path && file_exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
