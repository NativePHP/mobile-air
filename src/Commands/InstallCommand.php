<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Native\Mobile\Traits\DisplaysMarketingBanners;
use Native\Mobile\Traits\InstallsAndroid;
use Native\Mobile\Traits\InstallsIos;
use Native\Mobile\Traits\PlatformFileOperations;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    use DisplaysMarketingBanners, InstallsAndroid, InstallsIos, PlatformFileOperations;

    protected bool $forcing = false;

    protected $signature = 'native:install
        {platform? : The platform to install (android, ios, or both)}
        {--force : Overwrite existing files}
        {--F|fresh : Overwrite existing files (alias for --force)}
        {--with-icu : Include ICU support for Android (adds ~30MB)}
        {--without-icu : Exclude ICU support for Android}
        {--skip-php : Do not download the PHP binaries}';

    protected $description = 'Install all of the NativePHP resources';

    public function handle(): void
    {
        intro('Installing NativePHP for Mobile');

        $this->ensureAppIdIsSet();

        $this->forcing = $this->option('force') || $this->option('fresh');

        $platform = $this->argument('platform');

        if ($platform && ! in_array($platform, ['android', 'ios', 'both'])) {
            error('Invalid platform. Please specify "android", "ios", or "both".');

            return;
        }

        // Check for WSL environment - Android is not supported in WSL
        if ($this->isRunningInWSL()) {
            error('Android installation is not supported in WSL (Windows Subsystem for Linux).');
            note(<<<'NOTE'
                NativePHP for Android requires native Windows, Linux, or macOS.

                Please run this command from Windows CMD instead of WSL.
                NOTE);

            return;
        }

        // Determine which platforms to install
        $installAndroid = false;
        $installIos = false;

        if (PHP_OS_FAMILY === 'Darwin') {
            $choice = $platform ?: select(
                label: 'Which platforms do you want to install?',
                options: [
                    'android' => 'Android',
                    'ios' => 'iOS',
                    'both' => 'Both',
                ],
                default: 'both'
            );

            $installAndroid = $choice === 'android' || $choice === 'both';
            $installIos = $choice === 'ios' || $choice === 'both';
        } else {
            if ($platform === 'ios') {
                error('iOS installation is only available on macOS.');

                return;
            }
            $installAndroid = true;
        }

        // Collect all prompts first
        if ($installAndroid) {
            $this->promptAndroidOptions();
        }

        // Now run all tasks
        $this->newLine();

        $path = base_path('nativephp');

        if ($this->forcing && is_dir($path)) {
            $this->components->task('Removing existing native app directories', function () use ($path) {
                $this->removeDirectory($path.DIRECTORY_SEPARATOR.'ios');
                $this->removeDirectory($path.DIRECTORY_SEPARATOR.'android');
            });
        }

        $this->callSilently('vendor:publish', [
            '--tag' => 'nativephp-mobile',
            ...($this->forcing ? ['--force' => true] : []),
        ]);

        $this->callSilently('vendor:publish', ['--tag' => 'nativephp-mobile-config']);

        if ($installAndroid) {
            $this->setupAndroid();
        }

        if ($installIos) {
            $this->setupIos();
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

        outro('NativePHP for Mobile installed successfully!');

        if (confirm('Would you mind starring us on GitHub? It really helps!', default: false)) {
            $url = 'https://github.com/NativePHP/mobile-air';

            match (PHP_OS_FAMILY) {
                'Darwin' => exec("open {$url}"),
                'Windows' => exec("start {$url}"),
                default => exec("xdg-open {$url}"),
            };
        }

        $this->showProBanner();
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

        $pattern = "/^{$key}=.*$/m";

        if (preg_match($pattern, $envContents)) {
            // Update existing value
            $envContents = preg_replace($pattern, "{$key}={$value}", $envContents);
        } else {
            // Append new value
            $envContents = rtrim($envContents)."\n\n{$key}={$value}\n";
        }

        file_put_contents($envPath, $envContents);
    }
}
