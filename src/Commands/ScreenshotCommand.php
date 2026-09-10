<?php

declare(strict_types=1);

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Native\Mobile\Support\HostOperatingSystem;

final class ScreenshotCommand extends Command
{
    /** `xcrun simctl`'s own alias for "whichever simulator is currently booted". */
    private const string DEFAULT_IOS_TARGET = 'booted';

    protected $signature = 'native:screenshot
        {os : Platform (android/a or ios/i)}
        {udid? : Specific simulator/emulator UDID (iOS defaults to the booted simulator; Android requires exactly one connected device when omitted)}
        {--output= : File path to write the PNG to}';

    protected $description = 'Capture a screenshot of the app on a running simulator, emulator, or device';

    public function handle(): int
    {
        $rawOs = (string) $this->argument('os');
        $platform = ScreenshotPlatform::fromInput($rawOs);

        if ($platform === null) {
            $this->error(sprintf("Invalid platform '%s'. Use 'ios'/'i' or 'android'/'a'.", $rawOs));

            return self::FAILURE;
        }

        $outputPath = (string) $this->option('output');

        if ($outputPath === '') {
            $this->error('--output is required.');

            return self::FAILURE;
        }

        $outputDirectory = dirname($outputPath);

        if (! is_dir($outputDirectory)) {
            $this->error(sprintf('Output directory does not exist: %s', $outputDirectory));

            return self::FAILURE;
        }

        return match ($platform) {
            ScreenshotPlatform::Ios => $this->captureIos($outputPath),
            ScreenshotPlatform::Android => $this->captureAndroid($outputPath),
        };
    }

    private function captureIos(string $outputPath): int
    {
        if (HostOperatingSystem::current() !== HostOperatingSystem::Darwin) {
            $this->error('iOS screenshots require macOS (Xcode toolchain).');

            return self::FAILURE;
        }

        $target = (string) $this->argument('udid') ?: self::DEFAULT_IOS_TARGET;

        // Array-form Process::run() has Symfony escape each argument itself
        // (no shell is invoked), so $target/$outputPath need no manual
        // escaping here — unlike the Android string-form command below.
        $result = Process::run(['xcrun', 'simctl', 'io', $target, 'screenshot', $outputPath]);

        if (! $result->successful()) {
            $this->reportSimctlFailure($result->errorOutput(), $target);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function captureAndroid(string $outputPath): int
    {
        $adbCommand = HostOperatingSystem::current() === HostOperatingSystem::Windows ? 'adb.exe' : 'adb';

        if (! Process::run(sprintf('%s version', $adbCommand))->successful()) {
            $this->error('ADB is not installed or not in your PATH.');

            return self::FAILURE;
        }

        $target = (string) $this->argument('udid');

        if ($target === '') {
            $target = $this->resolveAndroidTarget($adbCommand);

            if ($target === null) {
                return self::FAILURE;
            }
        }

        // exec-out writes binary PNG data to stdout — needs shell redirection,
        // so this has to run as a shell string rather than array-form argv,
        // which means every interpolated value must be escaped by hand.
        $command = sprintf(
            '%s -s %s exec-out screencap -p > %s',
            escapeshellarg($adbCommand),
            escapeshellarg($target),
            escapeshellarg($outputPath),
        );

        $result = Process::run($command);

        if (! $result->successful()) {
            $errorOutput = $result->errorOutput();
            $this->error($errorOutput !== '' ? $errorOutput : sprintf('adb screencap failed for device %s.', $target));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Resolves the only sensible implicit target: exactly one connected
     * device. Zero and "more than one" are both genuine ambiguity, but
     * reported distinctly rather than as one generic "not found" — a
     * second booted emulator is a common local setup, not a missing one.
     */
    private function resolveAndroidTarget(string $adbCommand): ?string
    {
        $devices = $this->parseAdbDevices($adbCommand);

        if (count($devices) === 1) {
            return array_key_first($devices);
        }

        $this->error($devices === []
            ? 'No connected Android device or emulator found. Connect one, or pass a udid.'
            : sprintf('Multiple Android devices found (%s). Pass a udid to pick one.', implode(', ', array_keys($devices))));

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function parseAdbDevices(string $adbCommand): array
    {
        $result = Process::run(sprintf('%s devices', $adbCommand));

        if (! $result->successful()) {
            return [];
        }

        return collect(explode("\n", $result->output()))
            ->filter(fn (string $line): bool => str_contains($line, "\tdevice"))
            ->mapWithKeys(function (string $line): array {
                $serial = explode("\t", $line)[0];

                return [$serial => $serial];
            })
            ->all();
    }

    /**
     * `xcrun simctl io <target> screenshot` reports two distinct failures
     * for an unusable target — verified directly against a real `xcrun
     * simctl` invocation, since neither matches the wording `SimCommand`
     * checks for (that one covers a different simctl subcommand):
     * "No devices are booted." for the bare `booted` alias, and
     * "Invalid device: <udid>" for a udid that doesn't exist at all.
     */
    private function reportSimctlFailure(string $stderr, string $target): void
    {
        $stderr = trim($stderr);

        if (str_contains($stderr, 'No devices')) {
            $this->error('No booted simulator found.');

            return;
        }

        if (str_contains($stderr, 'Invalid device')) {
            $this->error(sprintf("No simulator found for target '%s'.", $target));

            return;
        }

        $this->error($stderr !== '' ? $stderr : sprintf("simctl screenshot failed for target '%s'.", $target));
    }
}
