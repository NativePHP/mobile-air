<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;

trait PromptsAndroidTarget
{
    private function promptForAndroidTarget(): string
    {
        $devices = $this->getConnectedAndroidDevices();

        if (empty($devices)) {
            error('No connected Android devices or emulators found.');
            exit(1);
        }

        if (count($devices) === 1) {
            return array_key_first($devices);
        }

        return select('Select a device or emulator', $devices);
    }

    private function getConnectedAndroidDevices(): array
    {
        $adbCommand = PHP_OS_FAMILY === 'Windows' ? 'adb.exe' : 'adb';
        $devices = $this->parseAdbDevices($adbCommand);

        if (! empty($devices)) {
            return $devices;
        }

        note('No devices found. Attempting to launch an emulator...');

        $emulatorBinary = $this->resolveAndroidEmulatorPath();

        if (! $emulatorBinary) {
            error('Could not locate the Android emulator binary.');
            exit(1);
        }

        $listCommand = sprintf('"%s" -list-avds', $emulatorBinary);
        $listProcess = SymfonyProcess::fromShellCommandline($listCommand);
        $listProcess->run();

        if (! $listProcess->isSuccessful()) {
            error('Failed to list Android emulators.');
            exit(1);
        }

        $avds = array_filter(explode("\n", trim($listProcess->getOutput())));

        if (empty($avds)) {
            error('No AVDs found.');
            exit(1);
        }

        $selected = select(
            label: 'Select an emulator to launch',
            options: $avds
        );

        $escapedBinary = escapeshellarg($emulatorBinary);
        $escapedAvd = escapeshellarg($selected);

        if (PHP_OS_FAMILY === 'Windows') {
            $launchCommand = "start /B \"\" \"{$emulatorBinary}\" -avd \"{$selected}\"";
        } else {
            $launchCommand = "nohup $escapedBinary -avd $escapedAvd > /tmp/emulator.log 2>&1 &";
        }

        SymfonyProcess::fromShellCommandline($launchCommand)->start();

        $this->components->task('Waiting for emulator to boot', function () use ($adbCommand) {
            for ($i = 0; $i < 100; $i++) {
                $bootCompleted = $this->adbGetProp($adbCommand, 'sys.boot_completed');
                $bootAnim = $this->adbGetProp($adbCommand, 'init.svc.bootanim');

                if ($bootCompleted === '1' && $bootAnim === 'stopped') {
                    return true;
                }

                usleep(250000);
            }

            return false;
        });

        return $this->parseAdbDevices($adbCommand);
    }

    private function adbGetProp(string $adbCommand, string $property): string
    {
        $cmd = "$adbCommand shell getprop $property";

        $descriptorSpec = [
            1 => ['pipe', 'w'], // stdout
            2 => ['file', 'NUL', 'a'], // stderr (Windows)
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);
        if (is_resource($process)) {
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($process);

            return trim($output);
        }

        return '';
    }

    private function parseAdbDevices(string $adbCommand): array
    {
        $output = shell_exec("{$adbCommand} devices") ?: '';

        return collect(explode("\n", $output))
            ->filter(fn ($line) => Str::contains($line, "\tdevice"))
            ->mapWithKeys(fn ($line) => [explode("\t", $line)[0] => explode("\t", $line)[0]])
            ->all();
    }

    private function resolveAndroidEmulatorPath(): ?string
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
