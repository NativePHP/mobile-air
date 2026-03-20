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
        public readonly ?string $serviceProvider = null
    ) {}

    public function getNamespace(): string
    {
        return $this->manifest->namespace;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getBridgeFunctions(): array
    {
        return $this->manifest->bridgeFunctions;
    }

    public function getAndroidPermissions(): array
    {
        return $this->manifest->android['permissions'] ?? [];
    }

    public function getIosInfoPlist(): array
    {
        return $this->manifest->ios['info_plist'] ?? [];
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

    public function getAndroidFeatures(): array
    {
        return $this->manifest->android['features'] ?? [];
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
        $path = $this->getIosSourcePath();

        if (! is_dir($path)) {
            return false;
        }

        // Check if there are any .swift files in the directory
        $files = glob($path.'/*.swift') ?: [];
        if (! empty($files)) {
            return true;
        }

        // Check subdirectories recursively
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'swift') {
                return true;
            }
        }

        return false;
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

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->getIosSourcePath(),
                \RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'swift') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
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
