<?php

namespace Native\Mobile\Plugins;

class Plugin
{
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $path,
        public readonly PluginManifest $manifest,
        public readonly string $description = '',
        public readonly ?string $serviceProvider = null,
        public readonly string $composerType = 'nativephp-plugin'
    ) {}

    public function getNamespace(): string
    {
        return $this->manifest->namespace;
    }

    /**
     * Platforms this plugin declares native code for.
     *
     * @return list<string>
     */
    public function getPlatforms(): array
    {
        return $this->manifest->platforms;
    }

    public function supportsPlatform(string $platform): bool
    {
        return in_array(strtolower($platform), $this->manifest->platforms, true);
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getBridgeFunctions(): array
    {
        return $this->manifest->bridgeFunctions;
    }

    public function getComponents(): array
    {
        return $this->manifest->components;
    }

    public function isUiPlugin(): bool
    {
        return $this->composerType === 'nativephp-ui-plugin';
    }

    public function isSystemPlugin(): bool
    {
        return $this->composerType === 'nativephp-plugin';
    }

    public function getAndroidPermissions(): array
    {
        return $this->manifest->android['permissions'] ?? [];
    }

    /**
     * Extra ProGuard/R8 rules this plugin needs in minified builds, declared
     * as `android.proguard_rules` in nativephp.json. Keep rules for the
     * plugin's own classes are generated automatically from its Kotlin
     * package declarations — this is for dependency quirks the compiler
     * can't infer (e.g. `-dontwarn` for a library's optional reflection).
     *
     * @return list<string>
     */
    public function getAndroidProguardRules(): array
    {
        return $this->manifest->android['proguard_rules'] ?? [];
    }

    public function getIosInfoPlist(): array
    {
        return $this->manifest->ios['info_plist'] ?? [];
    }

    /**
     * Per-locale overrides for Info.plist string entries.
     *
     * Shape: ['nl' => ['NSCameraUsageDescription' => 'Dutch text'], 'fr' => [...]]
     * Locale keys become {locale}.lproj/InfoPlist.strings at build time.
     *
     * @return array<string, array<string, string>>
     */
    public function getIosInfoPlistLocalizations(): array
    {
        return $this->manifest->ios['info_plist_localizations'] ?? [];
    }

    public function getAndroidDependencies(): array
    {
        return $this->manifest->android['dependencies'] ?? [];
    }

    public function getIosDependencies(): array
    {
        return $this->manifest->ios['dependencies'] ?? [];
    }

    public function getIosEntitlements(): array
    {
        return $this->manifest->ios['entitlements'] ?? [];
    }

    public function getIosCapabilities(): array
    {
        return $this->manifest->ios['capabilities'] ?? [];
    }

    public function getIosBackgroundModes(): array
    {
        return $this->manifest->ios['background_modes'] ?? [];
    }

    public function getIosInitFunction(): ?string
    {
        return $this->manifest->ios['init_function'] ?? null;
    }

    public function getAndroidMinVersion(): ?int
    {
        $value = $this->manifest->android['min_version'] ?? null;

        return $value !== null ? (int) $value : null;
    }

    public function getAndroidInitFunction(): ?string
    {
        return $this->manifest->android['init_function'] ?? null;
    }

    public function getEvents(): array
    {
        return $this->manifest->events;
    }

    public function getServiceProvider(): ?string
    {
        return $this->serviceProvider;
    }

    public function getHooks(): array
    {
        return $this->manifest->hooks;
    }

    public function getSecrets(): array
    {
        return $this->manifest->secrets;
    }

    public function getAndroidRepositories(): array
    {
        return $this->manifest->android['repositories'] ?? [];
    }

    /**
     * Gradle plugins this plugin needs declared in the root build.gradle.kts
     * plugins {} block, as `android.gradle_plugins` in nativephp.json.
     * Each entry: ['id' => string, 'version' => string, 'apply' => bool (default false)].
     * `apply => false` puts the plugin on the build classpath only, so the
     * app module can apply it conditionally (e.g. google-services when a
     * google-services.json is present).
     */
    public function getAndroidGradlePlugins(): array
    {
        return $this->manifest->android['gradle_plugins'] ?? [];
    }

    public function getAndroidFeatures(): array
    {
        return $this->manifest->android['features'] ?? [];
    }

    /**
     * Attributes this plugin sets on nodes core's own manifest declares,
     * from `android.manifest_attributes` in nativephp.json.
     *
     * @return array<string, array<string, string|bool|int>> Keyed by target
     */
    public function getAndroidManifestAttributes(): array
    {
        return $this->manifest->android['manifest_attributes'] ?? [];
    }

    public function getIosRepositories(): array
    {
        return $this->manifest->ios['repositories'] ?? [];
    }

    public function getAndroidAssets(): array
    {
        return $this->manifest->assets['android'] ?? [];
    }

    public function getIosAssets(): array
    {
        return $this->manifest->assets['ios'] ?? [];
    }

    public function getAndroidManifest(): array
    {
        // Return activities, services, receivers, providers, meta_data from android config
        return array_filter([
            'activities' => $this->manifest->android['activities'] ?? [],
            'services' => $this->manifest->android['services'] ?? [],
            'receivers' => $this->manifest->android['receivers'] ?? [],
            'providers' => $this->manifest->android['providers'] ?? [],
            'meta_data' => $this->manifest->android['meta_data'] ?? [],
        ], fn ($arr) => ! empty($arr));
    }

    public function getIosManifest(): array
    {
        // Return iOS-specific manifest entries (excluding info_plist, dependencies, assets)
        $ios = $this->manifest->ios;
        unset($ios['info_plist'], $ios['dependencies'], $ios['assets']);

        return $ios;
    }

    public function hasHook(string $hookName): bool
    {
        return ! empty($this->manifest->hooks[$hookName]);
    }

    public function getHook(string $hookName): ?string
    {
        return $this->manifest->hooks[$hookName] ?? null;
    }

    public function getAndroidSourcePath(): string
    {
        // Support both nested (resources/android/src/) and flat (resources/android/) structures
        $nestedPath = $this->path.'/resources/android/src';
        if (is_dir($nestedPath)) {
            return $nestedPath;
        }

        // Fallback to flat structure
        return $this->path.'/resources/android';
    }

    public function getIosSourcePath(): string
    {
        // Support both nested (resources/ios/Sources/) and flat (resources/ios/) structures
        $nestedPath = $this->path.'/resources/ios/Sources';
        if (is_dir($nestedPath)) {
            return $nestedPath;
        }

        // Fallback to flat structure
        return $this->path.'/resources/ios';
    }

    public function hasAndroidCode(): bool
    {
        if (! $this->supportsPlatform('android')) {
            return false;
        }

        $path = $this->getAndroidSourcePath();

        if (! is_dir($path)) {
            return false;
        }

        // Check if there are any .kt files in the directory
        $files = glob($path.'/*.kt') ?: [];
        if (! empty($files)) {
            return true;
        }

        // Check subdirectories recursively
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'kt') {
                return true;
            }
        }

        return false;
    }

    public function hasIosCode(): bool
    {
        if (! $this->supportsPlatform('ios')) {
            return false;
        }

        return SwiftSourceFilter::hasAny($this->getIosSourcePath());
    }

    /**
     * An explicit allow-list of iOS sources, from the manifest's
     * `ios.sources`. Paths are relative to the plugin's iOS source path and
     * may name a file or a directory.
     *
     * When present it is authoritative: nothing outside it is copied. It is
     * the escape hatch for a layout the automatic exclusions get wrong.
     *
     * @return list<string>
     */
    public function getIosSources(): array
    {
        $sources = $this->manifest->ios['sources'] ?? [];

        return array_values(array_filter(
            is_array($sources) ? $sources : [],
            fn ($source) => is_string($source) && $source !== ''
        ));
    }

    public function getAndroidSourceFiles(): array
    {
        if (! $this->hasAndroidCode()) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->getAndroidSourcePath(),
                \RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'kt') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function getIosSourceFiles(): array
    {
        if (! $this->hasIosCode()) {
            return [];
        }

        $root = $this->getIosSourcePath();

        return array_map(
            fn (string $relative) => $root.'/'.$relative,
            SwiftSourceFilter::collect($root)
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'path' => $this->path,
            'manifest' => $this->manifest->toArray(),
        ];
    }
}
