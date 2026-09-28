<?php

namespace Native\Mobile\Concerns;

use Illuminate\Support\Facades\File;
use Native\Mobile\Exceptions\BundleStagingFailed;
use Native\Mobile\Support\LaravelBundleStager;
use Native\Mobile\Support\PlatformBuildPool;
use Native\Mobile\Support\PlatformBuildResult;
use Symfony\Component\Console\Formatter\OutputFormatter;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

/**
 * native:run both: stage the Laravel app once, then run the iOS and
 * Android builds side by side.
 *
 * Each platform runs as its own `native:run <platform>` child process
 * pointed at the staged tree with --staged-bundle. Separate processes keep
 * the things that are global to one PHP process (Laravel Prompts' output,
 * config, putenv, the working directory) from leaking between the two
 * builds. Everything that needs a person (device pickers, the PHP version
 * check) happens here first, so the children never prompt.
 */
trait RunsBothPlatforms
{
    protected ?PlatformBuildPool $platformBuildPool = null;

    protected function runBoth(): int
    {
        if (! $this->canBuildBothHere()) {
            error('Building iOS and Android together needs macOS, because the iOS build needs Xcode.');
            note('Run `php artisan native:run android` on this machine.');

            return self::FAILURE;
        }

        if ($this->option('watch')) {
            error('--watch is not supported with both platforms yet.');
            note('Run `php artisan native:run ios --watch` or `php artisan native:run android --watch` instead.');

            return self::FAILURE;
        }

        if ($this->argument('udid')) {
            error('Pick devices with --ios-device and --android-device when running both platforms.');

            return self::FAILURE;
        }

        $this->buildType = $this->option('build') ?: 'debug';

        if (! in_array($this->buildType, ['debug', 'release'], true)) {
            error("Both platforms can only be built as debug or release. iOS has no {$this->buildType} build.");

            return self::FAILURE;
        }

        foreach (['ios' => 'iOS', 'android' => 'Android'] as $platform => $name) {
            if (! is_dir(base_path("nativephp/{$platform}"))) {
                error("No {$name} project found at [nativephp/{$platform}].");
                note('Run `php artisan native:install both` first.');

                return self::FAILURE;
            }
        }

        intro('Running NativePHP for iOS and Android');

        $this->checkForUnregisteredPlugins();

        $iosTarget = $this->resolveIosTargetForBoth();
        $simulated = array_key_exists($iosTarget, $this->simulators);

        $androidTarget = $this->buildType === 'debug'
            ? $this->resolveAndroidTargetForBoth()
            : null;

        if ($this->buildType === 'debug' && ! $androidTarget) {
            return self::FAILURE;
        }

        $staged = $this->stageForBoth();

        if ($staged === null) {
            return self::FAILURE;
        }

        try {
            $results = $this->buildBothInParallel($staged, $iosTarget, $androidTarget);
        } finally {
            File::deleteDirectory($staged);
        }

        return $this->reportBoth($results, $simulated) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The iOS half needs Xcode. Overridable so the orchestration can be
     * exercised on Linux CI.
     */
    protected function canBuildBothHere(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    protected function resolveIosTargetForBoth(): string
    {
        $devices = $this->getAvailableIosDevices();

        return $this->option('ios-device') ?: $this->promptForIosTarget($devices);
    }

    protected function resolveAndroidTargetForBoth(): ?string
    {
        if ($target = $this->option('android-device')) {
            return $target;
        }

        if (! $this->canRunCommand('adb version')) {
            error('ADB is not installed or not in your PATH.');

            return null;
        }

        return $this->promptForAndroidTarget();
    }

    /**
     * Stage the app once for both builds. Returns the staged path, or null
     * if staging failed.
     */
    protected function stageForBoth(): ?string
    {
        $root = base_path('nativephp/staging');
        $this->removeStaleStagingDirectories($root);

        $path = $root.'/run-'.getmypid();
        $log = base_path('nativephp/staging.log');
        file_put_contents($log, '');

        note('Staging the Laravel app once for both platforms');

        $stager = new LaravelBundleStager(
            source: base_path(),
            path: $path,
            includeDevDependencies: $this->buildType === 'debug',
            excludes: config('nativephp.cleanup_exclude_files', []),
            task: fn (string $title, callable $callback) => $this->components->task($title, $callback),
            log: fn (string $message) => file_put_contents($log, rtrim($message, "\n").PHP_EOL, FILE_APPEND),
        );

        try {
            return $stager->stage();
        } catch (BundleStagingFailed $e) {
            error($e->getMessage());
            note("Check the staging log for details: {$log}");
            File::deleteDirectory($path);

            return null;
        }
    }

    /**
     * A run that was killed before it could clean up leaves its tree behind.
     * Directories are named after the run's pid, so anything whose process
     * is gone can go.
     */
    protected function removeStaleStagingDirectories(string $root): void
    {
        if (! function_exists('posix_kill')) {
            return;
        }

        foreach (glob($root.'/run-*', GLOB_ONLYDIR) ?: [] as $directory) {
            $pid = (int) substr(basename($directory), 4);

            if ($pid > 0 && $pid !== getmypid() && ! posix_kill($pid, 0)) {
                File::deleteDirectory($directory);
            }
        }
    }

    /**
     * @return array<string, PlatformBuildResult>
     */
    protected function buildBothInParallel(string $staged, string $iosTarget, ?string $androidTarget): array
    {
        $labels = ['ios' => '<fg=cyan>ios    </>', 'android' => '<fg=green>android</>'];

        $pool = $this->platformBuildPool = new PlatformBuildPool(
            workingDirectory: base_path(),
            onLine: function (string $platform, string $line) use ($labels) {
                $this->output->writeln($labels[$platform].' │ '.OutputFormatter::escape($line));
            },
        );

        $pool->add('ios', $this->childRunCommand('ios', $iosTarget, $staged), base_path('nativephp/ios-run.log'));
        $pool->add('android', $this->childRunCommand('android', $androidTarget, $staged), base_path('nativephp/android-run.log'));

        // Ctrl+C reaches the children through the terminal anyway. This also
        // covers a SIGTERM sent to this process alone, so a killed run never
        // leaves an xcodebuild or Gradle build going with nobody watching.
        if (defined('SIGINT') && defined('SIGTERM')) {
            $this->trap([SIGINT, SIGTERM], fn () => $pool->stop());
        }

        note('Building iOS and Android in parallel. Logs: nativephp/ios-run.log, nativephp/android-run.log');

        try {
            return $pool->run();
        } finally {
            $this->untrap();
        }
    }

    /**
     * @return array<int, string>
     */
    protected function childRunCommand(string $platform, ?string $target, string $staged): array
    {
        return array_values(array_filter([
            PHP_BINARY,
            base_path('artisan'),
            'native:run',
            $platform,
            $target,
            '--build='.$this->buildType,
            '--staged-bundle='.$staged,
            '--no-tty',
            '--no-interaction',
            '--no-ansi',
        ], fn ($part) => $part !== null && $part !== ''));
    }

    /**
     * Print one line per platform with its result and artifact, and say
     * whether everything worked.
     *
     * @param  array<string, PlatformBuildResult>  $results
     */
    protected function reportBoth(array $results, bool $iosSimulated): bool
    {
        $this->newLine();

        $allGood = true;

        foreach ($results as $platform => $result) {
            $name = $platform === 'ios' ? 'iOS' : 'Android';
            $artifact = $platform === 'ios'
                ? $this->iosArtifactPath($iosSimulated)
                : $this->androidArtifactPath();
            $seconds = round($result->seconds).'s';

            if ($result->interrupted) {
                $allGood = false;
                $this->components->twoColumnDetail($name, "<fg=yellow>stopped</> after {$seconds}");
            } elseif (! $result->successful()) {
                $allGood = false;
                $this->components->twoColumnDetail($name, "<fg=red>failed</> (exit {$result->exitCode}) after {$seconds}");
            } elseif ($artifact === null) {
                $allGood = false;
                $this->components->twoColumnDetail($name, "<fg=red>finished but no build output was found</> after {$seconds}");
            } else {
                $this->components->twoColumnDetail($name, "<fg=green>succeeded</> in {$seconds}");
                $this->components->twoColumnDetail('  Artifact', $artifact);
            }

            if (! $result->successful() || $artifact === null) {
                $buildLog = $platform === 'ios' ? 'nativephp/ios-build.log' : 'nativephp/android-build.log';
                $this->components->twoColumnDetail('  Logs', "{$result->logPath}, ".base_path($buildLog));
            }
        }

        $this->newLine();

        if ($allGood) {
            outro('Both platforms built and launched!');
        } else {
            error('At least one platform did not build. See the logs above.');
        }

        return $allGood;
    }

    protected function iosArtifactPath(bool $simulated): ?string
    {
        $configuration = $this->buildType === 'release' ? 'Release' : 'Debug';

        $path = $simulated
            ? base_path('nativephp/ios/build/Build/Products/Debug-iphonesimulator/NativePHP-simulator.app')
            : base_path("nativephp/ios/build/Build/Products/{$configuration}-iphoneos/NativePHP.app");

        return file_exists($path) ? $path : null;
    }

    protected function androidArtifactPath(): ?string
    {
        if ($this->buildType === 'release') {
            return $this->findReleaseApk();
        }

        $path = base_path('nativephp/android/app/build/outputs/apk/debug/app-debug.apk');

        return file_exists($path) ? $path : null;
    }
}
