<?php

namespace Native\Mobile\Concerns;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

trait RunsIos
{
    use ReportsRunOutcome, ValidatesAppConfig;

    protected string $iosLogPath = 'nativephp/ios-build.log';

    protected string $iosLastDevicePath = 'nativephp/ios-last-device-id';

    protected bool $simulated = false;

    protected bool $watching = false;

    protected array $devices = [];

    protected array $simulators = [];

    private function getLastUsedIosDevice(): ?string
    {
        $path = base_path($this->iosLastDevicePath);

        if (file_exists($path)) {
            return trim(file_get_contents($path)) ?: null;
        }

        return null;
    }

    private function saveLastUsedIosDevice(string $udid): void
    {
        $path = base_path($this->iosLastDevicePath);
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $udid);
    }

    private function getLatestIosVersion(array $devices): ?string
    {
        return collect($devices)
            ->filter(fn ($d) => $d['category'] === 'Simulators')
            ->pluck('version')
            ->filter()
            ->map(fn ($v) => version_compare($v, '0.0.0', '>=') ? $v : null)
            ->filter()
            ->sort(fn ($a, $b) => version_compare($b, $a))
            ->first();
    }

    private function filterIosDevices(array $devices, ?string $lastUsedUdid = null): array
    {
        $latestVersion = $this->getLatestIosVersion($devices);

        return collect($devices)
            ->filter(function ($d) use ($latestVersion, $lastUsedUdid) {
                if ($lastUsedUdid && $d['udid'] === $lastUsedUdid) {
                    return true;
                }

                if (version_compare($d['version'], '18.0', '<')) {
                    return false;
                }

                if ($d['category'] === 'Devices') {
                    return true;
                }

                if (! str_contains($d['name'], 'iPhone')) {
                    return false;
                }

                return $d['version'] === $latestVersion;
            })
            ->values()
            ->all();
    }

    public function runIos(): void
    {
        // iOS builds require the Xcode toolchain (xcrun, simctl, devicectl),
        // so bail out early with a clear message on Windows/Linux instead of
        // failing cryptically once xcrun is invoked.
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->failRun('validate', 'iOS apps can only be built and run on macOS.');
            error('iOS apps can only be built and run on macOS.');
            note('Use `php artisan native:run android` on this machine.');

            return;
        }

        $this->watching = $this->option('watch');

        $this->iosLogPath = base_path($this->iosLogPath);

        file_put_contents($this->iosLogPath, '');

        if (! is_dir(base_path('nativephp/ios'))) {
            $this->failRun('validate', 'No iOS project found at [nativephp/ios].');
            error('No iOS project found at [nativephp/ios].');
            note('Run `php artisan native:install` or ensure you have the correct folder structure.');

            return;
        }

        // Start Vite dev server early if watching, so hot file is present during build
        if ($this->watching && $this->shouldRunVite()) {
            $this->startViteDevServer('ios');
        }

        // Validate version for release builds
        $this->validateAppVersion($this->buildType);

        $devices = $this->getAvailableIosDevices();

        if (empty($devices) && $this->runFailure !== null) {
            return;
        }

        if (! $target = $this->argument('udid')) {
            $target = $this->promptForIosTarget($devices);
        }

        if (array_key_exists($target, $this->simulators)) {
            $this->simulated = true;
        }

        $this->runTheIosBuild($target);
    }

    private function getAvailableIosDevices(): array
    {
        $output = Process::run('xcrun xctrace list devices')->output();

        if (empty($output)) {
            $this->failRun('devices', 'No iOS devices found.');
            error('No iOS devices found!');

            if ($this->hasOption('json') && $this->option('json')) {
                return [];
            }

            exit();
        }

        $category = null;
        $devices = [];

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (Str::startsWith($line, '==')) {
                $category = Str::between($line, '== ', ' ==');

                continue;
            }

            if (str_contains($category, 'Offline')) {
                continue;
            }

            preg_match('/^(.+?)(?:\s+\(([^)]+)\))?\s+\(([^)]+)\)$/', $line, $matches);

            if (count($matches) === 4) {
                [, $name, $version, $udid] = $matches;

                if (Str::isUuid($udid) && $category === 'Devices') {
                    continue;
                }

                $device = [
                    'name' => $name,
                    'version' => $version,
                    'udid' => $udid,
                    'category' => $category,
                ];

                $devices[] = $device;

                match ($category) {
                    'Devices' => $this->devices[$udid] = $device,
                    'Simulators' => $this->simulators[$udid] = $device,
                    default => null
                };
            }
        }

        return $devices;
    }

    private function runTheIosBuild($target)
    {
        $basePath = base_path('nativephp/ios');

        if ($verbose = $this->getOutput()->isVerbose()) {
            putenv('SHELL_VERBOSITY=1');
        }

        if (! $this->simulated) {
            $devicectlCheck = Process::run(['xcrun', 'devicectl', '--help']);

            if (! $devicectlCheck->successful()) {
                $this->failRun('validate', 'xcrun devicectl not found (device deployment requires Xcode 15+).');
                error('xcrun devicectl not found!');
                note('Device deployment requires Xcode 15 or later. Simulator builds will still work.');

                return;
            }
        }

        note("Build log: {$this->iosLogPath}");

        $result = $this->call('native:build', [
            '--release' => $this->option('build') === 'release',
            '--simulated' => $this->simulated,
            '--target' => $target,
            '--no-tty' => $this->option('no-tty'),
        ]);

        if ($result > 0) {
            $this->failRun('build', 'Build failed.', ['buildLog' => $this->iosLogPath]);
            error('Build failed!');
            note('Inspect the nativephp/ios-build.log file or use the -v flag to enable verbose output.');

            return;
        }

        if ($this->simulated) {
            $this->runOnSimulator($basePath, $target, $verbose);

            return;
        }

        $this->runOnRealDevice($basePath, $target, $verbose);
    }

    private function runOnSimulator(string $basePath, string $target, bool $verbose = false)
    {
        $this->components->task('Booting simulator', function () use ($basePath, $target, $verbose) {
            Process::path($basePath)
                ->tty($verbose && ! $this->option('no-tty'))
                ->run("xcrun simctl boot {$target}", function ($type, $output) use ($verbose) {
                    file_put_contents($this->iosLogPath, $output, FILE_APPEND);

                    if ($verbose) {
                        $this->output->write($output);
                    }
                });
        });

        shell_exec('open -a Simulator');

        // Free the hot-reload port before (re)launching. Two stale holders can
        // block the fresh app from binding 9999, which silently breaks hot
        // reload (triggers land on the wrong listener):
        //   1. A previous app instance still running in the simulator.
        //   2. A leftover host `iproxy` from an earlier device run — the
        //      simulator shares the host's localhost, so it collides too.
        // simctl terminate errors if the app isn't running; that's expected.
        Process::path($basePath)
            ->run('xcrun simctl terminate '.$target.' '.config('nativephp.app_id'));
        Process::path($basePath)
            ->run('lsof -ti tcp:9999 | xargs kill -9 2>/dev/null');

        $this->fixProductBundleName($basePath, 'build/Build/Products/Debug-iphonesimulator/NativePHP-simulator.app');

        $this->components->task('Installing app on simulator', function () use ($basePath, $target, $verbose) {
            Process::path($basePath)
                ->forever()
                ->tty($verbose && ! $this->option('no-tty'))
                ->run(
                    "xcrun simctl install {$target} build/Build/Products/Debug-iphonesimulator/NativePHP-simulator.app",
                    function ($type, $output) use ($verbose) {
                        file_put_contents($this->iosLogPath, $output, FILE_APPEND);

                        if ($verbose) {
                            $this->output->write($output);
                        }
                    }
                );
        });

        $appId = config('nativephp.app_id');

        $this->components->task('Launching app', function () use ($basePath, $target, $appId, $verbose) {
            Process::path($basePath)
                ->tty($verbose && ! $this->option('no-tty'))
                ->run("xcrun simctl launch {$target} {$appId}", function ($type, $output) use ($verbose) {
                    file_put_contents($this->iosLogPath, $output, FILE_APPEND);

                    if ($verbose) {
                        $this->output->write($output);
                    }
                });
        });

        outro('App launched!');

        if ($this->watching) {
            $this->call('native:watch', [
                'platform' => 'ios',
                'target' => $target,
                '--no-devtools' => $this->hasOption('no-devtools') && $this->option('no-devtools'),
            ]);
        }
    }

    private function runOnRealDevice(string $basePath, string $target, bool $verbose = false): void
    {
        $installFailed = false;
        $isRelease = $this->option('build') === 'release';
        $configuration = $isRelease ? 'Release' : 'Debug';

        $this->fixProductBundleName($basePath, "build/Build/Products/{$configuration}-iphoneos/NativePHP.app", resign: true);

        $this->components->task('Deploying app to device', function () use ($basePath, $target, $verbose, &$installFailed, $configuration) {
            $installResult = Process::path($basePath)
                ->timeout(300)
                ->tty($verbose && ! $this->option('no-tty'))
                ->run([
                    'xcrun', 'devicectl', 'device', 'install', 'app',
                    '--device', $target,
                    "build/Build/Products/{$configuration}-iphoneos/NativePHP.app",
                ], function ($type, $output) use ($verbose) {
                    file_put_contents($this->iosLogPath, $output, FILE_APPEND);

                    if ($verbose) {
                        $this->output->write($output);
                    }
                });

            if (! $installResult->successful()) {
                $installFailed = true;

                return false;
            }

            return true;
        });

        if ($installFailed) {
            $this->failRun('install', 'App installation failed.', ['buildLog' => $this->iosLogPath]);
            error('App installation failed!');
            note('Check nativephp/ios-build.log for details.');

            return;
        }

        $appId = config('nativephp.app_id');
        $launchFailed = false;

        $this->components->task('Launching app', function () use ($basePath, $target, $appId, $verbose, &$launchFailed) {
            $launchResult = Process::path($basePath)
                ->timeout(30)
                ->run([
                    'xcrun', 'devicectl', 'device', 'process', 'launch',
                    '--device', $target,
                    $appId,
                ], function ($type, $output) use ($verbose) {
                    file_put_contents($this->iosLogPath, $output, FILE_APPEND);

                    if ($verbose) {
                        $this->output->write($output);
                    }
                });

            if (! $launchResult->successful()) {
                $launchFailed = true;

                return false;
            }

            return true;
        });

        if ($launchFailed) {
            $this->failRun('launch', 'App installed but launch failed.');
            warning('App installed but launch failed - tap the app icon on your device.');
        } else {
            outro('App launched!');
        }

        if ($this->watching) {
            $this->call('native:watch', [
                'platform' => 'ios',
                'target' => $target,
                '--no-devtools' => $this->hasOption('no-devtools') && $this->option('no-devtools'),
            ]);
        }
    }

    private function promptForIosTarget(array $devices): string
    {
        $lastUsedUdid = $this->getLastUsedIosDevice();
        $filteredDevices = $this->filterIosDevices($devices, $lastUsedUdid);

        $target = $this->showDeviceSelector($filteredDevices, $lastUsedUdid, showAllOption: true);

        if ($target === '__show_all__') {
            $target = $this->showDeviceSelector($devices, $lastUsedUdid, showAllOption: false);
        }

        $this->saveLastUsedIosDevice($target);

        return $target;
    }

    private function showDeviceSelector(array $devices, ?string $lastUsedUdid, bool $showAllOption): string
    {
        $options = collect($devices)
            ->sortBy(function ($d) use ($lastUsedUdid) {
                return $d['udid'] === $lastUsedUdid ? 0 : 1;
            })
            ->mapWithKeys(function ($d) use ($lastUsedUdid) {
                $label = sprintf(
                    '%s%s (%s) [%s] (%s)',
                    $d['udid'] === $lastUsedUdid ? '(last used) ' : '',
                    $d['name'],
                    $d['version'],
                    $d['udid'],
                    $d['category']
                );

                return [$d['udid'] => $label];
            })
            ->all();

        if ($showAllOption) {
            $options['__show_all__'] = 'Show all devices...';
        }

        return select(
            label: 'Select a target device/simulator',
            options: $options
        );
    }

    /**
     * Xcode's generated Info.plist pins CFBundleName to PRODUCT_NAME
     * ("NativePHP") and, with GENERATE_INFOPLIST_FILE=YES, that generated
     * value overrides a CFBundleName entry in the source Info.plist. System
     * surfaces that read CFBundleName — most visibly the
     * ASWebAuthenticationSession consent alert ("X" Wants to Use "site" to
     * Sign In) — therefore introduce every app as "NativePHP". Patch the
     * BUILT product's plist and re-sign it with the identity that already
     * signed it, so the installed app carries the real app name.
     */
    private function fixProductBundleName(string $basePath, string $appRelativePath, bool $resign = false): void
    {
        $app = rtrim($basePath, '/').'/'.$appRelativePath;
        $name = (string) config('app.name');

        if ($name === '' || ! is_dir($app)) {
            return;
        }

        $plist = $app.'/Info.plist';

        $set = Process::run(['/usr/libexec/PlistBuddy', '-c', "Set :CFBundleName {$name}", $plist]);

        if (! $set->successful()) {
            Process::run(['/usr/libexec/PlistBuddy', '-c', "Add :CFBundleName string {$name}", $plist]);
        }

        if (! $resign) {
            return;
        }

        // Editing the bundle invalidated its signature; re-sign with the
        // same identity (first Authority of the existing signature).
        $info = Process::run(['codesign', '-dvv', $app]);
        preg_match('/Authority=([^\n]+)/', $info->errorOutput().$info->output(), $m);
        $identity = trim($m[1] ?? 'Apple Development');

        Process::run([
            'codesign', '--force', '--sign', $identity,
            '--preserve-metadata=identifier,entitlements,flags', $app,
        ]);
    }
}
