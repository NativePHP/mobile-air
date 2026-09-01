<?php

namespace Native\Mobile\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Native\Mobile\Concerns\DisplaysMarketingBanners;
use Native\Mobile\Concerns\InstallsAndroid;
use Native\Mobile\Concerns\InstallsIos;
use Native\Mobile\Concerns\PlatformFileOperations;
use Native\Mobile\Concerns\TracksInstallFailures;
use Native\Mobile\Support\PhpBinaries;
use Native\Mobile\Support\TransferFailure;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    use DisplaysMarketingBanners, InstallsAndroid, InstallsIos, PlatformFileOperations, TracksInstallFailures;

    protected bool $forcing = true;

    protected string $phpVersion;

    protected ?array $versionsManifest = null;

    protected $signature = 'native:install
        {platform? : The platform to install (android/a, ios/i, or both)}
        {--no-force : Keep existing files instead of overwriting}
        {--with-icu : Include ICU support for Android (adds ~30MB)}
        {--skip-php : Do not download the PHP binaries}
        {--F|force : Force re-download of PHP binaries by clearing the cache}';

    protected $description = 'Install all of the NativePHP resources';

    public function handle(): int
    {
        intro('Installing NativePHP for Mobile');

        $this->ensureAppIdIsSet();

        $this->forcing = ! $this->option('no-force');

        if ($this->option('force')) {
            // Scoped to the current branch: forcing a re-download of one
            // branch's binaries is no reason to evict another's.
            $cacheDir = PhpBinaries::cacheDirectory();
            if (is_dir($cacheDir)) {
                $this->components->task('Clearing cached PHP binaries', function () use ($cacheDir) {
                    $files = glob($cacheDir.'/*.zip');
                    foreach ($files as $file) {
                        unlink($file);
                    }
                });
            }
        }

        $platform = $this->argument('platform');

        if ($platform) {
            $platform = match (strtolower($platform)) {
                'a' => 'android',
                'i' => 'ios',
                default => $platform,
            };
        }

        if ($platform && ! in_array($platform, ['android', 'ios', 'both'])) {
            error('Invalid platform. Please specify "android" (a), "ios" (i), or "both".');

            return self::FAILURE;
        }

        // Check for WSL environment - Android is not supported in WSL
        if ($this->isRunningInWSL()) {
            error('Android installation is not supported in WSL (Windows Subsystem for Linux).');
            note(<<<'NOTE'
                NativePHP for Android requires native Windows, Linux, or macOS.

                Please run this command from Windows CMD instead of WSL.
                NOTE);

            return self::FAILURE;
        }

        // Determine which platforms to install
        $installAndroid = false;
        $installIos = false;

        if (PHP_OS_FAMILY === 'Darwin') {
            $choice = $platform ?: 'both';

            $installAndroid = $choice === 'android' || $choice === 'both';
            $installIos = $choice === 'ios' || $choice === 'both';
        } else {
            if ($platform === 'ios') {
                error('iOS installation is only available on macOS.');

                return self::FAILURE;
            }
            $installAndroid = true;
        }

        // Collect all prompts first
        if ($installAndroid) {
            $this->promptAndroidOptions();
        }

        if ($installIos) {
            $this->promptIosOptions();
        }

        // Now run all tasks
        $this->newLine();

        $path = base_path('nativephp');

        // Only the platforms actually being installed. Removing the other one's
        // directory deletes a project this command was never asked to touch, and
        // then reports success — so `native:install ios` followed by
        // `native:install android` leaves you with no iOS project at all, with
        // nothing in either run's output to say so.
        $removing = array_values(array_filter([
            $installAndroid ? 'android' : null,
            $installIos ? 'ios' : null,
        ]));

        if ($this->forcing && is_dir($path) && $removing !== []) {
            $label = 'Removing existing '.implode(' and ', $removing).' '
                .(count($removing) === 1 ? 'directory' : 'directories');

            $this->components->task($label, function () use ($path, $removing) {
                foreach ($removing as $platform) {
                    $this->removeDirectory($path.DIRECTORY_SEPARATOR.$platform);
                }
            });
        }

        $this->callSilently('vendor:publish', [
            '--tag' => 'nativephp-mobile',
            ...($this->forcing ? ['--force' => true] : []),
        ]);

        $this->callSilently('vendor:publish', ['--tag' => 'nativephp-mobile-config']);

        $this->migrateLegacyNativephpJson();

        $shouldInstallPhp = ! ($this->option('skip-php') && ! $this->forcing);

        if ($shouldInstallPhp) {
            $this->phpVersion = $this->detectPhpVersion();
            $this->fetchVersionsManifest();
        }

        if ($installAndroid) {
            $this->setupAndroid();
        }

        if ($installIos) {
            $this->setupIos();
        }

        if ($shouldInstallPhp && $this->versionsManifest) {
            $includeIcu = (bool) $this->option('with-icu');
            $this->writeNativephpLock($this->phpVersion, $includeIcu);
        }

        file_put_contents($path.DIRECTORY_SEPARATOR.'.gitignore', '*'.PHP_EOL);
        @mkdir($path.DIRECTORY_SEPARATOR.'resources');

        // Copy bin/native to application base path and make it executable
        $sourceBin = __DIR__.'/../../bin/native';
        $targetBin = base_path('native');

        if (file_exists($sourceBin)) {
            $this->components->task('Copying native CLI wrapper', function () use ($sourceBin, $targetBin) {
                copy($sourceBin, $targetBin);
                if (PHP_OS_FAMILY !== 'Windows') {
                    chmod($targetBin, 0755);
                }
            });
        }

        if ($this->installFailed()) {
            // No success banner and no marketing over a broken install, and a
            // non-zero exit so a caller can tell. The messages were printed
            // where they happened, so this counts rather than repeats them.
            $this->newLine();

            $count = count($this->installFailures);
            error($count === 1
                ? '1 error was reported above — the app is not ready to build.'
                : "{$count} errors were reported above — the app is not ready to build.");
            note('Fix the cause and re-run `php artisan native:install --force`.');

            return self::FAILURE;
        }

        outro('NativePHP for Mobile installed successfully!');

        $this->showSuperNativeBanner();

        return self::SUCCESS;
    }

    protected function ensureAppIdIsSet(): void
    {
        $envPath = base_path('.env');
        $envContents = file_exists($envPath) ? file_get_contents($envPath) : '';

        // Check if NATIVEPHP_APP_ID is already set with a non-empty value
        if (preg_match('/^NATIVEPHP_APP_ID=(.+)$/m', $envContents, $matches)) {
            $existingValue = trim($matches[1]);
            if (! empty($existingValue) && $existingValue !== '""' && $existingValue !== "''") {
                return;
            }
        }

        $suggestedAppId = $this->generateSuggestedAppId();

        $appId = text(
            label: 'What should your app bundle ID be?',
            placeholder: $suggestedAppId,
            default: $suggestedAppId,
            hint: 'This uniquely identifies your app on the App Store and Google Play',
        );

        $this->setEnvValue('NATIVEPHP_APP_ID', $appId);
        $this->info("✅ Set NATIVEPHP_APP_ID={$appId} in .env");
    }

    protected function generateSuggestedAppId(): string
    {
        $username = $this->normalizeForBundleId(get_current_user() ?: 'developer');
        $words = $this->getRandomWords(3);

        return "com.{$username}.{$words}";
    }

    protected function normalizeForBundleId(string $value): string
    {
        // Convert to lowercase and remove any characters that aren't alphanumeric or hyphens
        $normalized = strtolower($value);
        $normalized = preg_replace('/[^a-z0-9]/', '', $normalized);

        // Ensure it doesn't start with a number
        if (preg_match('/^[0-9]/', $normalized)) {
            $normalized = 'app'.$normalized;
        }

        return $normalized ?: 'app';
    }

    protected function getRandomWords(int $count): string
    {
        $words = [
            'swift', 'pixel', 'cloud', 'spark', 'bloom', 'river', 'stone', 'flame',
            'frost', 'storm', 'light', 'dream', 'ocean', 'forest', 'meadow', 'summit',
            'aurora', 'comet', 'nebula', 'quasar', 'breeze', 'thunder', 'crystal', 'ember',
            'jade', 'coral', 'amber', 'silver', 'golden', 'velvet', 'lunar', 'solar',
            'nova', 'pulse', 'wave', 'flow', 'drift', 'glow', 'shine', 'gleam',
            'bold', 'brave', 'keen', 'vivid', 'rapid', 'agile', 'nimble', 'sleek',
        ];

        $selected = array_rand(array_flip($words), $count);

        return implode('', $selected);
    }

    protected function setEnvValue(string $key, string $value): void
    {
        $envPath = base_path('.env');
        $envContents = file_exists($envPath) ? file_get_contents($envPath) : '';

        // [^\r\n]* instead of .*$ so a CRLF file's trailing \r isn't consumed
        // by the replacement (which would leave mixed line endings on Windows)
        $pattern = "/^{$key}=[^\\r\\n]*/m";

        if (preg_match($pattern, $envContents)) {
            // Update existing value
            $envContents = preg_replace($pattern, "{$key}={$value}", $envContents);
        } else {
            // Append new value, matching the file's existing line endings
            $eol = str_contains($envContents, "\r\n") ? "\r\n" : "\n";
            $envContents = rtrim($envContents).$eol.$eol."{$key}={$value}".$eol;
        }

        file_put_contents($envPath, $envContents);
    }

    protected function getBinaryBranch(): string
    {
        return PhpBinaries::branch();
    }

    protected function fetchVersionsManifest(): void
    {
        $branch = $this->getBinaryBranch();
        $versionsUrl = PhpBinaries::manifestUrl($branch);

        try {
            $this->versionsManifest = json_decode(
                (new Client)->get($versionsUrl)->getBody()->getContents(),
                true
            );
        } catch (GuzzleException $e) {
            // GuzzleException rather than RequestException, because
            // ConnectException extends TransferException directly: an
            // unresolvable host, a refused connection or a TLS error is not a
            // RequestException, and used to escape here as a stack trace.
            //
            // A 404 is still worth separating out. The manifest is named for the
            // binary release this package pins, so a missing one means that
            // release was withdrawn — not that the CDN is down. Say which,
            // because the fixes are completely different. Only a RequestException
            // carries a response to ask about.
            if ($e instanceof RequestException && $e->getResponse()?->getStatusCode() === 404) {
                $this->failInstall(sprintf(
                    'PHP binaries release %s is no longer published.'
                    ."\n".'Update nativephp/mobile to a version that pins a current release:'
                    ."\n".'    composer update nativephp/mobile',
                    PhpBinaries::VERSION
                ));

                return;
            }

            $this->failInstall("Failed to fetch the PHP binaries manifest from: {$versionsUrl}"
                ."\n".TransferFailure::describe($e));
        }
    }

    protected function detectPhpVersion(): string
    {
        $supported = ['8.5', '8.4', '8.3'];

        // Check nativephp.lock first (committed by the user or written by a previous install)
        $lockPath = base_path('nativephp.lock');
        if (file_exists($lockPath)) {
            $lock = json_decode(file_get_contents($lockPath), true) ?? [];
            $lockVersion = $lock['php']['version'] ?? null;

            if (is_string($lockVersion)) {
                $minor = implode('.', array_slice(explode('.', $lockVersion), 0, 2));
                if (in_array($minor, $supported, true)) {
                    return $minor;
                }
            }
        }

        // Fall back to the host PHP — Composer resolves against it.
        $minor = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        if (in_array($minor, $supported, true)) {
            return $minor;
        }

        foreach ($supported as $version) {
            if (version_compare($minor, $version, '>=')) {
                return $version;
            }
        }

        return '8.3';
    }

    protected function writeNativephpLock(string $minor, bool $icu): void
    {
        $lockPath = base_path('nativephp.lock');
        $fullVersion = $this->versionsManifest['versions'][$minor]['php_version'] ?? $minor;

        $data = file_exists($lockPath)
            ? json_decode(file_get_contents($lockPath), true) ?? []
            : [];

        $data['php'] = [
            'version' => $fullVersion,
            'icu' => $icu,
        ];

        file_put_contents($lockPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    protected function migrateLegacyNativephpJson(): void
    {
        $legacyPath = base_path('nativephp.json');

        if (! file_exists($legacyPath)) {
            return;
        }

        @unlink($legacyPath);
    }
}
