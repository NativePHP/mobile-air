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

        // Split on \r?\n and trim each entry: emulator.exe emits CRLF on Windows,
        // and a stray "\r" in an AVD name breaks the -avd launch argument.
        $avds = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', trim($listProcess->getOutput())))));

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
        // Hold the Process reference until this method returns: a discarded temporary
        // is destructed immediately, and on Windows Process::__destruct() tree-kills
        // the cmd.exe wrapper (taskkill /F /T), taking the emulator down with it.
        $launchProcess = Process::fromShellCommandline($launchCommand);

        if (PHP_OS_FAMILY === 'Windows') {
            // Detach the wrapper into its own console so the destructor closes
            // pipes instead of tree-killing the spawned emulator.
            $launchProcess->setOptions(['create_new_console' => true]);
        }

        $launchProcess->start();

        $this->info('⏳ Waiting for emulator to boot...');

        // Resolve adb from the SDK: platform-tools is frequently not on PATH,
        // especially on stock Android Studio installs (Windows/Linux).
        $adb = $this->resolveAdbPath($emulatorPath);

        $booted = false;

        for ($i = 0; $i < 200; $i++) { // ~24s
            $bootProcess = Process::fromShellCommandline(sprintf('"%s" shell getprop sys.boot_completed', $adb));
            $bootProcess->run();
            $bootCompleted = trim($bootProcess->getOutput());

            $readyProcess = Process::fromShellCommandline(sprintf('"%s" shell getprop init.svc.bootanim', $adb));
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

    protected function resolveAdbPath(string $emulatorPath): string
    {
        $adbName = PHP_OS_FAMILY === 'Windows' ? 'adb.exe' : 'adb';

        $candidates = [];

        // Every emulator candidate lives at <sdk>/emulator/<bin>, so the SDK root
        // is two levels up — this covers stock installs where ANDROID_HOME is unset.
        $candidates[] = dirname($emulatorPath, 2).DIRECTORY_SEPARATOR.'platform-tools'.DIRECTORY_SEPARATOR.$adbName;

        if ($sdk = env('ANDROID_HOME') ?: env('ANDROID_SDK_ROOT')) {
            $candidates[] = $sdk.DIRECTORY_SEPARATOR.'platform-tools'.DIRECTORY_SEPARATOR.$adbName;
        }

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return $adbName; // fall back to PATH
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
