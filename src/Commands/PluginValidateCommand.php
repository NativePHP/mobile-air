<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Native\Mobile\Plugins\PluginManifest;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class PluginValidateCommand extends Command
{
    protected $signature = 'native:plugin:validate {path? : Path to a specific plugin directory}';

    protected $description = 'Validate installed NativePHP Mobile plugins or a specific plugin';

    protected Filesystem $files;

    protected array $errors = [];

    protected array $warnings = [];

    protected string $pluginName = '';

    protected bool $hasAnyFailures = false;

    protected bool $isFirstParty = false;

    protected array $platformRequirements = [];

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle(): int
    {
        $path = $this->argument('path');

        // If a specific path is provided, validate just that plugin
        if ($path) {
            if (! $this->files->isDirectory($path)) {
                error("Directory not found: {$path}");

                return self::FAILURE;
            }

            return $this->validatePlugin($path);
        }

        // Otherwise, scan for installed plugins in vendor/
        return $this->validateInstalledPlugins();
    }

    protected function validateInstalledPlugins(): int
    {
        $vendorPath = base_path('vendor');
        $installedPath = $vendorPath.'/composer/installed.json';

        if (! $this->files->exists($installedPath)) {
            error('No composer installed.json found. Run composer install first.');

            return self::FAILURE;
        }

        $installed = json_decode($this->files->get($installedPath), true);
        $packages = $installed['packages'] ?? $installed;

        $plugins = collect($packages)->filter(function ($package) {
            return ($package['type'] ?? null) === 'nativephp-plugin';
        });

        if ($plugins->isEmpty()) {
            info('No NativePHP plugins installed.');

            return self::SUCCESS;
        }

        info("Validating {$plugins->count()} NativePHP plugin(s)");
        $this->newLine();

        foreach ($plugins as $package) {
            $pluginPath = $vendorPath.'/'.$package['name'];
            $this->validatePlugin($pluginPath, $package['name']);
        }

        return $this->hasAnyFailures ? self::FAILURE : self::SUCCESS;
    }

    protected function validatePlugin(string $path, ?string $name = null): int
    {
        // Reset state for this plugin
        $this->errors = [];
        $this->warnings = [];
        $this->platformRequirements = [];
        $this->pluginName = $name ?? basename($path);
        $this->isFirstParty = str_starts_with($this->pluginName, 'nativephp/');

        $this->validateComposerJson($path);
        $this->validateNativephpJson($path);
        $this->validateDirectoryStructure($path);
        $this->validateBridgeFunctions($path);
        $this->validateHooks($path);
        $this->validateAssets($path);

        $this->displayPluginResult();

        if (! empty($this->errors)) {
            $this->hasAnyFailures = true;
        }

        return empty($this->errors) ? self::SUCCESS : self::FAILURE;
    }

    protected function validateComposerJson(string $path): void
    {
        $composerPath = $path.'/composer.json';

        if (! $this->files->exists($composerPath)) {
            $this->errors[] = 'Missing composer.json';

            return;
        }

        $composer = json_decode($this->files->get($composerPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = 'Invalid JSON in composer.json: '.json_last_error_msg();

            return;
        }

        // Check type
        if (($composer['type'] ?? null) !== 'nativephp-plugin') {
            $this->errors[] = 'composer.json "type" must be "nativephp-plugin"';
        }

        // Check nativephp extra
        if (empty($composer['extra']['nativephp']['manifest'])) {
            $this->warnings[] = 'composer.json missing extra.nativephp.manifest';
        }

        // Check Laravel providers
        if (empty($composer['extra']['laravel']['providers'])) {
            $this->warnings[] = 'composer.json missing extra.laravel.providers for auto-discovery';
        }

    }

    protected function validateNativephpJson(string $path): void
    {
        $manifestPath = $path.'/nativephp.json';

        if (! $this->files->exists($manifestPath)) {
            $this->errors[] = 'Missing nativephp.json manifest';

            return;
        }

        try {
            $manifest = PluginManifest::fromFile($manifestPath);

            // Store platform requirements for later display
            $this->storePlatformRequirements($manifest);

            // Additional validations
            if (empty($manifest->bridgeFunctions)) {
                $this->warnings[] = 'No bridge_functions defined in manifest';
            }

        } catch (\Exception $e) {
            $this->errors[] = 'Invalid nativephp.json: '.$e->getMessage();
        }
    }

    protected function storePlatformRequirements(PluginManifest $manifest): void
    {
        $androidMinVersion = $manifest->android['min_version'] ?? null;
        $iosMinVersion = $manifest->ios['min_version'] ?? null;

        // min_version is required for both platforms
        if (! $androidMinVersion) {
            $this->errors[] = 'Missing android.min_version in nativephp.json';
        } else {
            $this->platformRequirements['android'] = $androidMinVersion;
        }

        if (! $iosMinVersion) {
            $this->errors[] = 'Missing ios.min_version in nativephp.json';
        } else {
            $this->platformRequirements['ios'] = $iosMinVersion;
        }
    }

    protected function validateDirectoryStructure(string $path): void
    {
        $requiredDirs = [
            'src' => 'PHP source directory',
        ];

        $optionalDirs = [
            'resources/android/src' => 'Android Kotlin sources',
            'resources/ios/Sources' => 'iOS Swift sources',
        ];

        foreach ($requiredDirs as $dir => $description) {
            if (! $this->files->isDirectory($path.'/'.$dir)) {
                $this->errors[] = "Missing required directory: {$dir} ({$description})";
            }
        }

        $hasNativeCode = false;
        foreach ($optionalDirs as $dir => $description) {
            if ($this->files->isDirectory($path.'/'.$dir)) {
                $hasNativeCode = true;
            }
        }

        if (! $hasNativeCode && ! $this->isFirstParty) {
            $this->warnings[] = 'No native code directories found (resources/android or resources/ios)';
        }
    }

    protected function validateBridgeFunctions(string $path): void
    {
        // Skip implementation checks for first-party plugins (native code is in the main package)
        if ($this->isFirstParty) {
            return;
        }

        $manifestPath = $path.'/nativephp.json';

        if (! $this->files->exists($manifestPath)) {
            return;
        }

        try {
            $manifest = PluginManifest::fromFile($manifestPath);

            foreach ($manifest->bridgeFunctions as $function) {
                $name = $function['name'] ?? 'unknown';

                // Check Android implementation exists
                if (! empty($function['android'])) {
                    $androidPath = $this->findKotlinFile($path, $function['android']);
                    if (! $androidPath) {
                        $this->warnings[] = "Android implementation not found for {$name}";
                    }
                }

                // Check iOS implementation exists
                if (! empty($function['ios'])) {
                    $iosPath = $this->findSwiftFile($path, $function['ios']);
                    if (! $iosPath) {
                        $this->warnings[] = "iOS implementation not found for {$name}";
                    }
                }
            }

        } catch (\Exception $e) {
            // Already reported in validateNativephpJson
        }
    }

    protected function findKotlinFile(string $basePath, string $className): ?string
    {
        // com.vendor.plugin.example.ExampleFunctions.Execute
        // -> ExampleFunctions.kt
        $parts = explode('.', $className);
        $class = $parts[count($parts) - 2] ?? null; // Second to last is class name

        if (! $class) {
            return null;
        }

        // Search recursively for the Kotlin file
        $androidSrc = $basePath.'/resources/android/src';
        if (! is_dir($androidSrc)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($androidSrc, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'kt' && $file->getBasename('.kt') === $class) {
                return $file->getPathname();
            }
        }

        return null;
    }

    protected function findSwiftFile(string $basePath, string $className): ?string
    {
        // ExampleFunctions.Execute -> ExampleFunctions.swift
        $parts = explode('.', $className);
        $class = $parts[0];

        $swiftPath = $basePath.'/resources/ios/Sources/'.$class.'.swift';

        return $this->files->exists($swiftPath) ? $swiftPath : null;
    }

    protected function validateHooks(string $path): void
    {
        $manifestPath = $path.'/nativephp.json';

        if (! $this->files->exists($manifestPath)) {
            return;
        }

        try {
            $manifest = PluginManifest::fromFile($manifestPath);

            if (empty($manifest->hooks)) {
                return;
            }

            $validHooks = ['pre_compile', 'post_compile', 'copy_assets', 'post_build'];

            foreach ($manifest->hooks as $hookName => $command) {
                // Check hook name is valid
                if (! in_array($hookName, $validHooks)) {
                    $this->warnings[] = "Unknown hook type: {$hookName} (valid: ".implode(', ', $validHooks).')';

                    continue;
                }

                // Check command format (should be an Artisan command signature)
                if (empty($command)) {
                    $this->errors[] = "Hook '{$hookName}' has empty command";

                    continue;
                }

                // Warn if command doesn't follow expected pattern
                if (! preg_match('/^[a-z][a-z0-9:_-]+$/i', $command)) {
                    $this->warnings[] = "Hook '{$hookName}' command '{$command}' may not be a valid Artisan command signature";
                }
            }

        } catch (\Exception $e) {
            // Already reported in validateNativephpJson
        }
    }

    protected function validateAssets(string $path): void
    {
        $manifestPath = $path.'/nativephp.json';

        if (! $this->files->exists($manifestPath)) {
            return;
        }

        try {
            $manifest = PluginManifest::fromFile($manifestPath);

            // Check Android assets (assets.android at top level)
            $androidAssets = $manifest->assets['android'] ?? [];
            $this->validatePlatformAssets($path, 'android', $androidAssets);

            // Check iOS assets (assets.ios at top level)
            $iosAssets = $manifest->assets['ios'] ?? [];
            $this->validatePlatformAssets($path, 'ios', $iosAssets);

        } catch (\Exception $e) {
            // Already reported in validateNativephpJson
        }
    }

    protected function validatePlatformAssets(string $path, string $platform, array $platformAssets): void
    {
        if (empty($platformAssets)) {
            return;
        }

        if (! is_array($platformAssets)) {
            $this->errors[] = "Assets for '{$platform}' must be an array of source -> destination mappings";

            return;
        }

        foreach ($platformAssets as $source => $destination) {
            $sourcePath = $path.'/resources/'.$source;

            // Check if source file exists
            if (! $this->files->exists($sourcePath)) {
                $this->errors[] = "Asset source not found: resources/{$source} (for {$platform})";
            }

            // Validate destination format
            if ($platform === 'android') {
                // Android assets should go to assets/ or res/
                if (! str_starts_with($destination, 'assets/') && ! str_starts_with($destination, 'res/')) {
                    $this->warnings[] = "Android asset destination '{$destination}' doesn't start with 'assets/' or 'res/'";
                }
            } elseif ($platform === 'ios') {
                // iOS assets should go to Resources/
                if (! str_starts_with($destination, 'Resources/')) {
                    $this->warnings[] = "iOS asset destination '{$destination}' doesn't start with 'Resources/'";
                }
            }
        }
    }

    protected function displayPluginResult(): void
    {
        $hasErrors = ! empty($this->errors);
        $hasWarnings = ! empty($this->warnings);

        // Build status indicator
        if ($hasErrors) {
            $status = '<fg=red>FAIL</>';
        } elseif ($hasWarnings) {
            $status = '<fg=yellow>WARN</>';
        } else {
            $status = '<fg=green>OK</>';
        }

        // Build requirements string
        $reqStr = '';
        if (! empty($this->platformRequirements)) {
            $parts = [];
            foreach ($this->platformRequirements as $platform => $version) {
                $parts[] = "{$platform}: {$version}";
            }
            $reqStr = ' <fg=gray>('.implode(', ', $parts).')</>';
        }

        $this->components->twoColumnDetail($this->pluginName.$reqStr, $status);

        // Show errors indented
        foreach ($this->errors as $err) {
            $this->line("  <fg=red>✗ {$err}</>");
        }

        // Show warnings indented
        foreach ($this->warnings as $warn) {
            $this->line("  <fg=yellow>⚠ {$warn}</>");
        }
    }
}
