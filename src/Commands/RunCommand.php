<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Native\Mobile\Concerns\DisplaysMarketingBanners;
use Native\Mobile\Concerns\ManagesViteDevServer;
use Native\Mobile\Concerns\ManagesWatchman;
use Native\Mobile\Concerns\PlatformFileOperations;
use Native\Mobile\Concerns\ResolvesDeviceTargets;
use Native\Mobile\Concerns\RunsAndroid;
use Native\Mobile\Concerns\RunsIos;
use Native\Mobile\Plugins\PluginRegistry;
use Native\Mobile\Support\InstalledBinaries;
use Native\Mobile\Support\PhpBinaries;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

class RunCommand extends Command
{
    use DisplaysMarketingBanners, ManagesViteDevServer, ManagesWatchman, PlatformFileOperations, ResolvesDeviceTargets, RunsAndroid, RunsIos;

    protected $signature = 'native:run
        {os? : Platform to run (android/a or ios/i)}
        {udid?}
        {--build=debug : debug|release|bundle|profileable}
        {--W|watch : Enable hot reloading during development}
        {--vite : Start the Vite dev server (opt-in; off by default)}
        {--no-vite : Force-disable the Vite dev server (redundant — this is the default)}
        {--start-url= : Set the initial URL/path to load on app start (e.g., /dashboard)}
        {--no-tty : Disable TTY mode for non-interactive environments}
        {--fresh : Run every build step, even ones whose inputs are unchanged since the last build}
        {--json : Machine-readable result on the last line of stdout; never prompts (implies --no-tty)}';

    protected $description = 'Build, package, and run the NativePHP app';

    protected string $buildType;

    public function handle(): int
    {
        $json = (bool) $this->option('json');

        if ($json) {
            $this->input->setOption('no-tty', true);

            if ($this->option('watch')) {
                return $this->emitRunResult([
                    'ok' => false,
                    'stage' => 'validate',
                    'error' => 'watch_not_supported',
                    'hint' => '--json runs are discrete; use native:watch separately.',
                ]);
            }
        }

        if (! $this->ensureValidAppId()) {
            return $json
                ? $this->emitRunResult(['ok' => false, 'stage' => 'validate', 'error' => 'missing_app_id'])
                : self::FAILURE;
        }

        if (! $this->ensureHostPhpMatchesLock()) {
            if ($json) {
                return $this->emitRunResult(array_merge(
                    ['ok' => false, 'stage' => 'validate', 'error' => 'environment_check_failed'],
                    $this->runFailure ?? [],
                ));
            }

            return self::FAILURE;
        }

        // Check watchman is installed when --watch flag is used
        if ($this->option('watch') && ! $this->checkWatchmanDependencies()) {
            return self::FAILURE;
        }

        // Handle start URL if provided
        if ($startUrl = $this->option('start-url')) {
            $this->updateStartUrl($startUrl);
        }

        // Ensure the nativephp directory exists for log files
        $nativephpDir = base_path('nativephp');
        if (! is_dir($nativephpDir)) {
            mkdir($nativephpDir, 0755, true);
        }

        // Get platform from argument (android/a, ios/i)
        $os = $this->argument('os');
        if ($os && in_array(strtolower($os), ['a', 'i', 'android', 'ios'])) {
            $os = match (strtolower($os)) {
                'android', 'a' => 'android',
                'ios', 'i' => 'ios',
            };
        }

        // iOS builds depend on the Xcode toolchain (xcrun, xcodebuild), which only
        // exists on macOS — fail fast before touching logs, Vite, or devices
        if ($os === 'ios' && PHP_OS_FAMILY !== 'Darwin') {
            error('iOS builds require macOS (Xcode toolchain).');
            note('You can build and run the Android app on this machine with `php artisan native:run android`.');

            return self::FAILURE;
        }

        // Check for WSL environment - Android is not supported in WSL
        if ($this->isRunningInWSL()) {
            error('Android is not supported in WSL (Windows Subsystem for Linux).');
            note(<<<'NOTE'
                NativePHP for Android requires native Windows, Linux, or macOS.

                Please run this command from Windows CMD instead of WSL.
                NOTE);

            return self::FAILURE;
        }

        if (! $os) {
            if (PHP_OS_FAMILY === 'Darwin') {
                $hasAndroid = is_dir(base_path('nativephp/android'));
                $hasIos = is_dir(base_path('nativephp/ios'));

                if ($hasAndroid && ! $hasIos) {
                    $os = 'android';
                } elseif ($hasIos && ! $hasAndroid) {
                    $os = 'ios';
                } elseif ($json) {
                    return $this->emitRunResult([
                        'ok' => false,
                        'stage' => 'validate',
                        'error' => 'ambiguous_platform',
                        'hint' => 'Both platforms exist; pass the os argument (ios or android).',
                    ]);
                } else {
                    $os = select(
                        label: 'Which platform would you like to run?',
                        options: [
                            'android' => 'Android',
                            'ios' => 'iOS',
                        ]
                    );
                }
            } else {
                $os = 'android';
            }
        }

        if (! $this->ensureBinariesMatchRelease($os)) {
            return $json
                ? $this->emitRunResult(array_merge(
                    ['ok' => false, 'stage' => 'validate', 'error' => 'binaries_check_failed'],
                    $this->runFailure ?? [],
                ))
                : self::FAILURE;
        }

        // In --json mode a device must be deterministic: resolve it up front
        // (claimed device, last-used, or single booted target) and inject it
        // as the udid argument so the concerns never reach their prompts.
        if ($json && ! $this->argument('udid')) {
            $target = $this->resolveDeviceTarget($os, null);

            if (! $target['ok']) {
                return $this->emitRunResult(array_merge(['stage' => 'devices'], $target));
            }

            $this->input->setArgument('udid', $target['udid']);
        }

        $buildTypes = [
            'debug' => 'Debug',
            'release' => 'Release',
        ];

        if ($os === 'android') {
            $buildTypes['bundle'] = 'App Bundle (AAB)';
            $buildTypes['profileable'] = 'Profileable (release-optimized, benchmarkable)';
        }

        // --json must never reach a prompt: an unattended caller has nobody
        // to answer it, so it either hangs or silently takes the default
        // depending on whether stdin happens to be a TTY. Default explicitly.
        if ($json) {
            $this->buildType = $this->option('build') ?? 'debug';

            if (! array_key_exists($this->buildType, $buildTypes)) {
                return $this->emitRunResult([
                    'ok' => false,
                    'stage' => 'validate',
                    'error' => 'invalid_build_type',
                    'detail' => $this->buildType,
                    'hint' => 'Valid values for '.$os.': '.implode(', ', array_keys($buildTypes)).'.',
                ]);
            }
        } else {
            $this->buildType = $this->option('build') ?? select(
                label: 'Choose a build type',
                options: $buildTypes,
                default: 'debug'
            );
        }

        $osName = match ($os) {
            'android' => 'Android',
            'ios' => 'iOS',
            default => throw new \Exception('Invalid OS type.')
        };

        intro('Running NativePHP for '.$osName);

        $this->checkForUnregisteredPlugins();

        $startedAt = microtime(true);

        $succeeded = match ($os) {
            'android' => $this->runAndroid(),
            'ios' => $this->runIos(),
        };

        if ($json) {
            return $this->emitRunResult($this->buildRunResult($os, $startedAt, $succeeded));
        }

        // A build that stopped early has already said why. Carrying that out
        // as the exit code is what lets CI notice it at all, and skipping the
        // banner keeps that error from being buried under it.
        if (! $succeeded) {
            return self::FAILURE;
        }

        $this->showBifrostBanner();

        return self::SUCCESS;
    }

    /**
     * Structured outcome for --json mode. A recorded failure wins; otherwise
     * the run is verified empirically — the app must actually be installed
     * and running on the target, which also catches unchecked simctl
     * install/launch failures.
     */
    protected function buildRunResult(string $os, float $startedAt, bool $succeeded): array
    {
        $base = [
            'platform' => $os,
            'device' => $this->argument('udid'),
            'appId' => config('nativephp.app_id'),
            'buildType' => $this->buildType,
            'buildLog' => base_path($os === 'ios' ? 'nativephp/ios-build.log' : 'nativephp/android-build.log'),
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ];

        // A run that stopped early without recording why still failed.
        if (! $succeeded) {
            $this->failRun('build', 'The run stopped before the app was launched.', ['buildLog' => $base['buildLog']]);
        }

        if ($this->runFailure !== null) {
            $failure = $this->runFailure;

            if (in_array($failure['stage'], ['build', 'install'], true)) {
                $failure['logTail'] = $this->tailFile($failure['buildLog'] ?? $base['buildLog']);
            }

            return array_merge(['ok' => false], $base, $failure);
        }

        // Give the app a moment to spawn before probing.
        sleep(2);

        $probe = $this->probeAppProcess($os, (string) $this->argument('udid'), (string) config('nativephp.app_id'));

        if (! $probe['installed'] || ! $probe['running']) {
            return array_merge(['ok' => false], $base, [
                'stage' => 'verify',
                'error' => $probe['installed'] ? 'app_not_running' : 'app_not_installed',
                'hint' => 'The build reported no error but the app is not running — check for a boot fatal via native:tail or the devtools event log.',
            ]);
        }

        return array_merge(['ok' => true], $base, ['pid' => $probe['pid']]);
    }

    protected function emitRunResult(array $result): int
    {
        $this->output->writeln(json_encode($result, JSON_UNESCAPED_SLASHES));

        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    protected function tailFile(string $path, int $lines = 40): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $all = file($path, FILE_IGNORE_NEW_LINES) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }

    protected function checkForUnregisteredPlugins(): void
    {
        $registry = app(PluginRegistry::class);
        $unregistered = $registry->unregistered();

        if ($unregistered->isEmpty()) {
            return;
        }

        warning('The following plugins are installed but not registered:');

        $unregistered->each(function ($plugin) {
            $this->components->twoColumnDetail($plugin->name, '<fg=yellow>not registered</>');
        });

        note('Register them in your NativeServiceProvider or run: php artisan native:plugin:register');
        $this->newLine();
    }

    protected function ensureHostPhpMatchesLock(): bool
    {
        $lockPath = base_path('nativephp.lock');

        if (! file_exists($lockPath)) {
            return true;
        }

        $lock = json_decode(file_get_contents($lockPath), true) ?? [];
        $lockedVersion = $lock['php']['version'] ?? null;

        if (! is_string($lockedVersion) || $lockedVersion === '') {
            return true;
        }

        $lockedMinor = implode('.', array_slice(explode('.', $lockedVersion), 0, 2));
        $hostMinor = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        if ($lockedMinor === $hostMinor) {
            return true;
        }

        error("Host PHP {$hostMinor} does not match nativephp.lock ({$lockedVersion}).");
        note('Composer resolves your app dependencies against the host PHP, but the bundled runtime is pinned to PHP '.$lockedMinor.'. Building now will likely fail or produce a bundle that crashes on device.');

        // Never auto-reinstall in machine mode; surface the mismatch instead.
        if ($this->option('json')) {
            $this->failRun('validate', "Host PHP {$hostMinor} does not match nativephp.lock ({$lockedVersion}).", [
                'hint' => 'Run `php artisan native:install --force` (after switching PHP if needed), then retry.',
            ]);

            return false;
        }

        $supported = ['8.5', '8.4', '8.3'];

        if (! in_array($hostMinor, $supported, true)) {
            note("Your host PHP {$hostMinor} is not supported by NativePHP. Switch your host to PHP {$lockedMinor}.x and retry.");

            return false;
        }

        if (! confirm("Re-run `native:install --force` to bundle PHP {$hostMinor} instead?", default: true)) {
            note("Switch your host to PHP {$lockedMinor}.x and retry, or re-install when ready.");

            return false;
        }

        $lock['php']['version'] = $hostMinor;
        file_put_contents($lockPath, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $exitCode = $this->call('native:install', ['--force' => true]);

        if ($exitCode !== 0) {
            error('native:install --force failed. Resolve the install error and retry.');

            return false;
        }

        return true;
    }

    /**
     * Offer to reinstall when the PHP binaries in the platform project aren't
     * the release this version of nativephp/mobile installs.
     *
     * Updating the package doesn't touch nativephp/ios or nativephp/android,
     * so after a `composer update` the app keeps building with the old
     * binaries and the old native project until someone reinstalls, and
     * nothing says so. It builds, but without the new release's changes, and
     * PHP code that relies on them can fail on the device. Reinstalling with
     * `--skip-php` is worse: the native readers move to the new wire format,
     * the binaries don't, and the app launches and renders nothing.
     *
     * A mismatch warns and asks rather than stopping the run. The record
     * only holds the release number, which can't say whether anything that
     * matters changed, and a developer running old binaries on purpose
     * should be able to say no and carry on.
     *
     * Offline on purpose: it compares two local values, so it costs nothing
     * on every run and works without a connection.
     */
    protected function ensureBinariesMatchRelease(string $os): bool
    {
        $platformPath = base_path('nativephp/'.$os);

        // Nothing installed at all is a different problem, and the build
        // reports it.
        if (! is_dir($platformPath)) {
            return true;
        }

        $installed = InstalledBinaries::read($platformPath);
        $expected = InstalledBinaries::expected();

        // Credit whoever chose the release: the package pins it, unless
        // NATIVEPHP_BIN_BRANCH asked for a branch or a prerelease.
        $source = PhpBinaries::selection() === 'main'
            ? 'this version of nativephp/mobile installs'
            : 'NATIVEPHP_BIN_BRANCH asks for';

        if ($installed === null) {
            // Every project installed before the release was recorded lands
            // here. Said on every run rather than once, because the next
            // binaries release is exactly when it matters and a one-off
            // notice would long since have scrolled away. One reinstall
            // records the release and it stops.
            warning("Can't tell which PHP binaries release is in nativephp/{$os}.");
            note('Installs by older versions of nativephp/mobile, and installs that didn\'t finish, don\'t record it. '
                .ucfirst($source).' release '.InstalledBinaries::describe($expected).'. '
                .'Run `php artisan native:install --force` to reinstall and record it.');

            return true;
        }

        if ($installed === $expected) {
            return true;
        }

        warning(sprintf(
            'nativephp/%s has PHP binaries release %s, but %s %s.',
            $os,
            InstalledBinaries::describe($installed),
            $source,
            InstalledBinaries::describe($expected),
        ));
        note('Only `native:install` replaces the binaries. Updating nativephp/mobile or changing '
            .'NATIVEPHP_BIN_BRANCH leaves the old ones in place. The app will still build, but anything '
            .'that needs release '.InstalledBinaries::describe($expected).' can fail on the device.');

        // Never auto-reinstall in machine mode; surface the mismatch instead.
        if ($this->option('json')) {
            $this->failRun('validate', sprintf(
                'nativephp/%s has PHP binaries release %s, but %s %s.',
                $os,
                InstalledBinaries::describe($installed),
                $source,
                InstalledBinaries::describe($expected),
            ), [
                'hint' => "Run `php artisan native:install {$os} --force`, then retry.",
            ]);

            return false;
        }

        if (! confirm("Run `native:install {$os} --force` now?", default: true)) {
            note('Building with release '.InstalledBinaries::describe($installed).'. '
                .'Run `php artisan native:install --force` when you\'re ready.');

            return true;
        }

        $exitCode = $this->call('native:install', ['platform' => $os, '--force' => true]);

        if ($exitCode !== 0) {
            error('native:install --force failed. Resolve the install error and retry.');

            return false;
        }

        return true;
    }

    protected function ensureValidAppId(): bool
    {
        $appId = config('nativephp.app_id');

        if (str($appId)->isEmpty()) {
            error('NATIVEPHP_APP_ID is not set.');
            note('Please add a NATIVEPHP_APP_ID to your .env file (e.g. com.example.myapp).');

            return false;
        }

        if (str($appId)->startsWith('com.nativephp.')) {
            warning('Please change your NATIVEPHP_APP_ID from the default value.');
        }

        return true;
    }

    protected function updateStartUrl(string $startUrl): void
    {
        $envFilePath = base_path('.env');

        if (! file_exists($envFilePath)) {
            error('.env file not found');

            return;
        }

        $envContent = file_get_contents($envFilePath);
        $key = 'NATIVEPHP_START_URL';
        $newLine = "{$key}={$startUrl}";

        // Check if the key already exists
        if (preg_match("/^{$key}=.*$/m", $envContent)) {
            // Update existing line
            $envContent = preg_replace("/^{$key}=.*$/m", $newLine, $envContent);
        } else {
            // Add new line
            $envContent = rtrim($envContent).PHP_EOL.$newLine.PHP_EOL;
        }

        file_put_contents($envFilePath, $envContent);
        $this->components->twoColumnDetail('Start URL', $startUrl);
    }
}
