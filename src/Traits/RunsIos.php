<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

trait RunsIos
{
    use ValidatesAppConfig;

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
        $this->watching = $this->option('watch');

        $this->iosLogPath = base_path($this->iosLogPath);

        file_put_contents($this->iosLogPath, '');

        if (! is_dir(base_path('nativephp/ios'))) {
            error('No iOS project found at [nativephp/ios].');
            note('Run `php artisan native:install` or ensure you have the correct folder structure.');

            return;
        }

        // Start Vite dev server early if watching, so hot file is present during build
        if ($this->watching) {
            $this->startViteDevServer('ios');
        }

        // Validate version for release builds
        $this->validateAppVersion($this->buildType);

        $devices = $this->getAvailableIosDevices();

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
            error('No iOS devices found!');
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
            ]);
        }
    }

    private function runOnRealDevice(string $basePath, string $target, bool $verbose = false): void
    {
        $installFailed = false;
        $isRelease = $this->option('build') === 'release';
        $configuration = $isRelease ? 'Release' : 'Debug';

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
            warning('App installed but launch failed - tap the app icon on your device.');
        } else {
            outro('App launched!');
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
}
