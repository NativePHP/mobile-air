<?php

namespace Native\Mobile\Plugins;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;

class PluginDiscovery
{
    protected const PLUGIN_TYPES = ['nativephp-plugin', 'nativephp-ui-plugin'];

    protected ?Collection $cachedPlugins = null;

    protected ?array $allowedPlugins = null;

    public function __construct(
        protected Filesystem $files,
        protected string $basePath
    ) {}

    /**
     * Discover all installed NativePHP plugins that are explicitly allowed.
     *
     * Plugins must be registered in NativeServiceProvider to be discovered.
     * This prevents transitive dependencies from auto-registering malicious plugins.
     */
    public function discover(): Collection
    {
        if ($this->cachedPlugins !== null) {
            return $this->cachedPlugins;
        }

        $installedPath = $this->basePath.'/vendor/composer/installed.json';

        if (! $this->files->exists($installedPath)) {
            return $this->cachedPlugins = collect();
        }

        $installed = json_decode($this->files->get($installedPath), true);

        // Support both Composer 1.x (packages at root) and 2.x (packages key)
        $packages = $installed['packages'] ?? $installed;

        $allowedPlugins = $this->getAllowedPlugins();

        return $this->cachedPlugins = collect($packages)
            ->filter(fn ($package) => in_array($package['type'] ?? null, self::PLUGIN_TYPES, true))
            ->filter(fn ($package) => $this->isPluginAllowed($package, $allowedPlugins))
            ->map(fn ($package) => $this->loadPlugin($package))
            ->filter()
            ->values();
    }

    /**
     * Discover all installed NativePHP plugins without filtering.
     *
     * This is used internally to show all available plugins,
     * even those not yet registered.
     */
    public function discoverAll(): Collection
    {
        $installedPath = $this->basePath.'/vendor/composer/installed.json';

        if (! $this->files->exists($installedPath)) {
            return collect();
        }

        $installed = json_decode($this->files->get($installedPath), true);
        $packages = $installed['packages'] ?? $installed;

        return collect($packages)
            ->filter(fn ($package) => in_array($package['type'] ?? null, self::PLUGIN_TYPES, true))
            ->map(fn ($package) => $this->loadPlugin($package))
            ->filter()
            ->values();
    }

    /**
     * Get the list of allowed plugins from NativeServiceProvider.
     *
     * @return array<string> Empty array if no provider exists (blocks all plugins)
     */
    public function getAllowedPlugins(): array
    {
        if ($this->allowedPlugins !== null) {
            return $this->allowedPlugins;
        }

        $providerClass = 'App\\Providers\\NativeServiceProvider';

        if (! class_exists($providerClass)) {
            // No provider published yet - block all plugins for security
            return $this->allowedPlugins = [];
        }

        try {
            // Use new instead of app()->make() to avoid container issues
            $provider = new $providerClass(app());

            if (method_exists($provider, 'plugins')) {
                return $this->allowedPlugins = $provider->plugins();
            }
        } catch (\Throwable $e) {
            // Provider exists but failed to instantiate - safer to block all
            return $this->allowedPlugins = [];
        }

        return $this->allowedPlugins = [];
    }

    /**
     * Check if a plugin is allowed based on the allowlist.
     *
     * Matches by service provider class name (from composer.json extra.laravel.providers).
     */
    protected function isPluginAllowed(array $package, array $allowedPlugins): bool
    {
        // Empty array means no plugins registered (or no provider)
        if (empty($allowedPlugins)) {
            return false;
        }

        // Get service provider from composer.json
        $serviceProvider = $package['extra']['laravel']['providers'][0] ?? null;

        if (! $serviceProvider) {
            return false;
        }

        return in_array($serviceProvider, $allowedPlugins, true);
    }

    /**
     * Check if the NativeServiceProvider has been published.
     */
    public function hasPluginsProvider(): bool
    {
        return class_exists('App\\Providers\\NativeServiceProvider');
    }

    /**
     * Load a single plugin from its package data
     */
    protected function loadPlugin(array $package): ?Plugin
    {
        $packagePath = $this->basePath.'/vendor/'.$package['name'];

        // Check for custom manifest path in composer.json extra section
        $manifestPath = $packagePath.'/nativephp.json';
        if (isset($package['extra']['nativephp']['manifest'])) {
            $manifestPath = $packagePath.'/'.$package['extra']['nativephp']['manifest'];
        }

        if (! $this->files->exists($manifestPath)) {
            $this->warnPluginSkipped($package['name'] ?? '?', "manifest not found at {$manifestPath}");

            return null;
        }

        try {
            $manifest = PluginManifest::fromFile($manifestPath);

            // Get service provider from Laravel's auto-discovery config
            $serviceProvider = $package['extra']['laravel']['providers'][0] ?? null;

            return new Plugin(
                name: $package['name'],
                version: $package['version'] ?? '0.0.0',
                path: $packagePath,
                manifest: $manifest,
                description: $package['description'] ?? '',
                serviceProvider: $serviceProvider,
                composerType: $package['type'] ?? 'nativephp-plugin'
            );
        } catch (\Throwable $e) {
            $this->warnPluginSkipped(
                $package['name'] ?? '?',
                $e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')'
            );

            return null;
        }
    }

    /**
     * Surface a skipped plugin. A missing/malformed manifest makes the WHOLE
     * plugin vanish from discovery — which otherwise shows up only as a
     * baffling downstream failure (e.g. an iOS build error about missing
     * native symbols, because the plugin's renderers were never copied in).
     * Write to the PHP error log / stderr so it's visible during `native:run`
     * instead of being silently swallowed. We still skip the offending plugin
     * (return null) so one bad manifest doesn't take down the whole build.
     */
    protected function warnPluginSkipped(string $name, string $reason): void
    {
        error_log("[NativePHP] Skipping plugin '{$name}': {$reason}");
    }

    /**
     * Clear the cached plugins (useful after composer install/update)
     */
    public function clearCache(): void
    {
        $this->cachedPlugins = null;
        $this->allowedPlugins = null;
    }

    /**
     * Get all bridge functions from all plugins
     */
    public function getAllBridgeFunctions(): array
    {
        return $this->discover()
            ->flatMap(fn (Plugin $plugin) => $plugin->getBridgeFunctions())
            ->all();
    }

    /**
     * Get all Android permissions from all plugins
     */
    public function getAllAndroidPermissions(): array
    {
        return $this->discover()
            ->flatMap(fn (Plugin $plugin) => $plugin->getAndroidPermissions())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Get all iOS Info.plist entries from all plugins
     */
    public function getAllIosInfoPlist(): array
    {
        return $this->discover()
            ->flatMap(fn (Plugin $plugin) => collect($plugin->getIosInfoPlist()))
            ->all();
    }

    /**
     * Discover plugins that have events defined
     */
    public function discoverWithEvents(): Collection
    {
        return $this->discover()
            ->filter(fn (Plugin $plugin) => ! empty($plugin->getEvents()));
    }

    /**
     * Discover plugins that have Android native code
     */
    public function discoverWithAndroidCode(): Collection
    {
        return $this->discover()
            ->filter(fn (Plugin $plugin) => $plugin->hasAndroidCode());
    }

    /**
     * Discover plugins that have iOS native code
     */
    public function discoverWithIosCode(): Collection
    {
        return $this->discover()
            ->filter(fn (Plugin $plugin) => $plugin->hasIosCode());
    }
}
