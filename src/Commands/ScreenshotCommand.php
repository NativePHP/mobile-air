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
        {--output= : File path to write the PNG to}
        {--crop= : top/bottom keep only a strip nearest that edge; both trims that fraction off each edge and keeps the middle}
        {--crop-percent=0.25 : Fraction of the full image height the crop strip uses (0-1, exclusive; below 0.5 for --crop=both), used with --crop}';

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

        $crop = $this->resolveCrop();

        if ($crop === false) {
            return self::FAILURE;
        }

        if ($crop !== null && ! extension_loaded('gd')) {
            $this->error('--crop requires the PHP gd extension, which is not loaded.');

            return self::FAILURE;
        }

        $cropPercent = (float) $this->option('crop-percent');
        $cropPercentCeiling = $crop === ScreenshotCrop::Both ? 0.5 : 1.0;

        if ($crop !== null && ($cropPercent <= 0 || $cropPercent >= $cropPercentCeiling)) {
            $this->error(sprintf(
                '--crop-percent must be between 0 and %s (exclusive) for --crop=%s, got %s.',
                $cropPercentCeiling,
                $crop->value,
                $this->option('crop-percent')
            ));

            return self::FAILURE;
        }

        $result = match ($platform) {
            ScreenshotPlatform::Ios => $this->captureIos($outputPath),
            ScreenshotPlatform::Android => $this->captureAndroid($outputPath),
        };

        if ($result !== self::SUCCESS || $crop === null) {
            return $result;
        }

        if (! $this->cropScreenshot($outputPath, $crop, $cropPercent)) {
            $this->error(sprintf('Captured %s but failed to crop it.', $outputPath));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Parses `--crop`. Returns null when the option was omitted (a valid,
     * common case — no cropping), the resolved crop, or false after
     * reporting an invalid value.
     */
    private function resolveCrop(): ScreenshotCrop|false|null
    {
        $raw = (string) $this->option('crop');

        if ($raw === '') {
            return null;
        }

        $crop = ScreenshotCrop::tryFrom($raw);

        if ($crop === null) {
            $this->error(sprintf("Invalid --crop '%s'. Use 'top', 'bottom', or 'both'.", $raw));

            return false;
        }

        return $crop;
    }

    /**
     * `top`/`bottom` keep only a `$percent` strip nearest that edge —
     * useful for a screenshot meant to show one bar (a top nav bar, a
     * bottom tab bar) without the rest of the screen. `both` trims
     * `$percent` off each edge instead and keeps the middle — useful for
     * dropping OS chrome (the status bar, the home indicator) while
     * keeping the app's own content visible.
     */
    private function cropScreenshot(string $path, ScreenshotCrop $crop, float $percent): bool
    {
        $source = @imagecreatefrompng($path);

        if ($source === false) {
            return false;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $edge = max(1, (int) round($height * $percent));

        [$y, $cropHeight] = match ($crop) {
            ScreenshotCrop::Top => [0, $edge],
            ScreenshotCrop::Bottom => [$height - $edge, $edge],
            ScreenshotCrop::Both => [$edge, $height - (2 * $edge)],
        };

        // A --crop-percent near the (0.5, exclusive) ceiling for --crop=both
        // can round two symmetric edges into consuming the entire image —
        // fail rather than silently write a near-empty screenshot.
        if ($cropHeight < 1) {
            imagedestroy($source);

            return false;
        }

        $cropped = imagecrop($source, ['x' => 0, 'y' => $y, 'width' => $width, 'height' => $cropHeight]);
        imagedestroy($source);

        if ($cropped === false) {
            return false;
        }

        $saved = imagepng($cropped, $path);
        imagedestroy($cropped);

        return $saved;
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
