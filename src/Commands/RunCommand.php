<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Native\Mobile\Traits\DisplaysMarketingBanners;
use Native\Mobile\Traits\ManagesViteDevServer;
use Native\Mobile\Traits\ManagesWatchman;
use Native\Mobile\Traits\PlatformFileOperations;
use Native\Mobile\Traits\RunsAndroid;
use Native\Mobile\Traits\RunsIos;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

class RunCommand extends Command
{
    use DisplaysMarketingBanners, ManagesViteDevServer, ManagesWatchman, PlatformFileOperations, RunsAndroid, RunsIos;

    protected $signature = 'native:run
        {os? : Platform to run (android/a or ios/i)}
        {udid?}
        {--ios : Target iOS platform (shorthand for os=ios|i)}
        {--android : Target Android platform (shorthand for os=android|a)}
        {--build=debug : debug|release|bundle}
        {--W|watch : Enable hot reloading during development}
        {--start-url= : Set the initial URL/path to load on app start (e.g., /dashboard)}
        {--no-tty : Disable TTY mode for non-interactive environments}';

    protected $description = 'Build, package, and run the NativePHP app';

    protected string $buildType;

    public function handle(): int
    {
        $this->ensureValidAppId();

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

        // Get platform (flags take priority over argument)
        if ($this->option('ios')) {
            $os = 'ios';
        } elseif ($this->option('android')) {
            $os = 'android';
        } else {
            $os = $this->argument('os');
            // Support shorthands: 'a' for android, 'i' for ios
            if ($os && in_array(strtolower($os), ['a', 'i', 'android', 'ios'])) {
                $os = match(strtolower($os)) {
                    'android', 'a' => 'android',
                    'ios', 'i' => 'ios',
                };
            }
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
                $os = select(
                    label: 'Which platform would you like to run?',
                    options: [
                        'android' => 'Android',
                        'ios' => 'iOS',
                    ]
                );
            } else {
                $os = 'android';
            }
        }

        $buildTypes = [
            'debug' => 'Debug',
            'release' => 'Release',
        ];

        if ($os === 'android') {
            $buildTypes['bundle'] = 'App Bundle (AAB)';
        }

        $this->buildType = $this->option('build') ?? select(
            label: 'Choose a build type',
            options: $buildTypes,
            default: 'debug'
        );

        $osName = match ($os) {
            'android' => 'Android',
            'ios' => 'iOS',
            default => throw new \Exception('Invalid OS type.')
        };

        intro('Running NativePHP for '.$osName);

        match ($os) {
            'android' => $this->runAndroid(),
            'ios' => $this->runIos(),
        };

        $this->showBifrostBanner();

        return self::SUCCESS;
    }

    protected function ensureValidAppId(): void
    {
        $appId = config('nativephp.app_id');

        if (str($appId)->isEmpty()) {
            error('NATIVEPHP_APP_ID is not set.');
            note('Please add a NATIVEPHP_APP_ID to your .env file (e.g. com.example.myapp).');
            exit(1);
        }

        if (str($appId)->startsWith('com.nativephp.')) {
            warning('Please change your NATIVEPHP_APP_ID from the default value.');
        }
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
