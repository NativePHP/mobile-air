<?php

namespace Native\Mobile\Plugins;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

class PluginHookRunner
{
    public const HOOK_PRE_COMPILE = 'pre_compile';

    public const HOOK_POST_COMPILE = 'post_compile';

    public const HOOK_COPY_ASSETS = 'copy_assets';

    public const HOOK_POST_BUILD = 'post_build';

    protected string $platform;

    protected string $buildPath;

    protected string $appId;

    protected array $config;

    protected Collection $plugins;

    protected $output;

    public function __construct(
        string $platform,
        string $buildPath,
        string $appId,
        array $config = [],
        ?Collection $plugins = null,
        $output = null
    ) {
        $this->platform = $platform;
        $this->buildPath = $buildPath;
        $this->appId = $appId;
        $this->config = $config;
        $this->plugins = $plugins ?? collect();
        $this->output = $output;
    }

    /**
     * Run a specific hook for all plugins
     */
    public function runHook(string $hookName): void
    {
        foreach ($this->plugins as $plugin) {
            $this->runPluginHook($plugin, $hookName);
        }
    }

    /**
     * Run a specific hook for a single plugin
     */
    public function runPluginHook(Plugin $plugin, string $hookName): void
    {
        $hooks = $plugin->getHooks();

        if (empty($hooks[$hookName])) {
            return;
        }

        $command = $hooks[$hookName];

        $this->twoColumnDetail("<fg=blue>Running {$hookName} hook</>", $plugin->name);

        try {
            $exitCode = Artisan::call($command, [
                '--platform' => $this->platform,
                '--build-path' => $this->buildPath,
                '--plugin-path' => $plugin->path,
                '--app-id' => $this->appId,
                '--config' => json_encode($this->config),
                '--plugins' => json_encode($this->plugins->map->toArray()->toArray()),
            ]);

            if ($exitCode !== 0) {
                $this->warn("Hook {$hookName} for {$plugin->name} returned non-zero exit code: {$exitCode}");
            }
        } catch (\Exception $e) {
            $this->error("Hook {$hookName} for {$plugin->name} failed: {$e->getMessage()}");
        }
    }

    /**
     * Run pre-compile hooks for all plugins
     */
    public function runPreCompileHooks(): void
    {
        $this->runHook(self::HOOK_PRE_COMPILE);
    }

    /**
     * Run post-compile hooks for all plugins
     */
    public function runPostCompileHooks(): void
    {
        $this->runHook(self::HOOK_POST_COMPILE);
    }

    /**
     * Run copy-assets hooks for all plugins
     */
    public function runCopyAssetsHooks(): void
    {
        $this->runHook(self::HOOK_COPY_ASSETS);
    }

    /**
     * Run post-build hooks for all plugins
     */
    public function runPostBuildHooks(): void
    {
        $this->runHook(self::HOOK_POST_BUILD);
    }

    /**
     * Copy manifest-declared assets for all plugins
     */
    public function copyManifestAssets(): void
    {
        foreach ($this->plugins as $plugin) {
            $this->copyPluginManifestAssets($plugin);
        }
    }

    /**
     * Copy manifest-declared assets for a single plugin
     */
    protected function copyPluginManifestAssets(Plugin $plugin): void
    {
        $platformAssets = $this->platform === 'android'
            ? $plugin->getAndroidAssets()
            : $plugin->getIosAssets();

        if (empty($platformAssets)) {
            return;
        }

        foreach ($platformAssets as $source => $destination) {
            $sourcePath = $plugin->path.'/resources/'.$source;

            if (! file_exists($sourcePath)) {
                $this->warn("Asset not found for {$plugin->name}: {$source}");

                continue;
            }

            $destPath = $this->resolveAssetDestination($destination);

            if (! is_dir(dirname($destPath))) {
                mkdir(dirname($destPath), 0755, true);
            }

            // Copy the file
            copy($sourcePath, $destPath);

            // Check if file needs placeholder substitution (text-based files)
            if ($this->shouldSubstitutePlaceholders($destPath)) {
                $this->substitutePlaceholders($destPath, $plugin);
            }

            $this->twoColumnDetail('<fg=blue>Copied asset</>', "{$source} → {$destination}");
        }
    }

    /**
     * Resolve the full destination path for an asset
     */
    protected function resolveAssetDestination(string $destination): string
    {
        if ($this->platform === 'android') {
            // Android assets go to app/src/main/assets/
            if (str_starts_with($destination, 'assets/')) {
                return $this->buildPath.'/app/src/main/'.$destination;
            }
            // Android resources go to app/src/main/res/
            if (str_starts_with($destination, 'res/')) {
                return $this->buildPath.'/app/src/main/'.$destination;
            }

            // Default to assets
            return $this->buildPath.'/app/src/main/assets/'.$destination;
        }

        if ($this->platform === 'ios') {
            // iOS Resources
            if (str_starts_with($destination, 'Resources/')) {
                return $this->buildPath.'/NativePHP/'.$destination;
            }

            // Default to NativePHP bundle
            return $this->buildPath.'/NativePHP/Resources/'.$destination;
        }

        return $this->buildPath.'/'.$destination;
    }

    /**
     * Check if a file should have placeholder substitution applied
     */
    protected function shouldSubstitutePlaceholders(string $filePath): bool
    {
        // Only process text-based files
        $textExtensions = ['xml', 'json', 'txt', 'plist', 'strings', 'html', 'js', 'css', 'kt', 'swift', 'java'];
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (! in_array($extension, $textExtensions)) {
            return false;
        }

        // Check if file contains any placeholders
        $content = file_get_contents($filePath);

        return preg_match('/\$\{[A-Z_][A-Z0-9_]*\}/', $content) === 1;
    }

    /**
     * Substitute ${ENV_VAR} placeholders in a file with environment values
     */
    protected function substitutePlaceholders(string $filePath, Plugin $plugin): void
    {
        $content = file_get_contents($filePath);

        $content = preg_replace_callback('/\$\{([A-Z_][A-Z0-9_]*)\}/', function ($matches) use ($plugin) {
            $envVar = $matches[1];
            $envValue = env($envVar);

            if ($envValue === null) {
                $this->warn("Missing environment variable {$envVar} for plugin {$plugin->name}");

                return $matches[0]; // Keep placeholder if not found
            }

            return $envValue;
        }, $content);

        file_put_contents($filePath, $content);
    }

    /**
     * Output info message
     */
    protected function info(string $message): void
    {
        if ($this->output && method_exists($this->output, 'info')) {
            $this->output->info($message);
        }
    }

    /**
     * Output warning message
     */
    protected function warn(string $message): void
    {
        if ($this->output && method_exists($this->output, 'warn')) {
            $this->output->warn($message);
        }
    }

    /**
     * Output error message
     */
    protected function error(string $message): void
    {
        if ($this->output && method_exists($this->output, 'error')) {
            $this->output->error($message);
        }
    }

    /**
     * Output two-column detail (consistent with other command output)
     */
    protected function twoColumnDetail(string $label, string $value): void
    {
        if ($this->output && isset($this->output->components)) {
            $this->output->components->twoColumnDetail($label, $value);
        }
    }
}
