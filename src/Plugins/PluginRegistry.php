<?php

namespace Native\Mobile\Plugins;

use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;

class PluginRegistry implements IteratorAggregate
{
    protected static ?PluginRegistry $instance = null;

    public function __construct(
        protected PluginDiscovery $discovery
    ) {}

    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = app(static::class);
        }

        return static::$instance;
    }

    /**
     * Get all registered (allowed) plugins.
     */
    public function all(): Collection
    {
        return $this->discovery->discover();
    }

    /**
     * Get all installed plugins, including those not yet registered.
     *
     * This is useful for showing users what plugins are available
     * but not yet added to NativeServiceProvider.
     */
    public function allInstalled(): Collection
    {
        return $this->discovery->discoverAll();
    }

    /**
     * Get plugins that are installed but not registered.
     */
    public function unregistered(): Collection
    {
        $registered = $this->all()->pluck('name')->all();

        return $this->allInstalled()->filter(
            fn (Plugin $plugin) => ! in_array($plugin->name, $registered, true)
        );
    }

    /**
     * Check if a plugin is registered (allowed).
     */
    public function isRegistered(string $name): bool
    {
        return $this->all()->contains(fn (Plugin $p) => $p->name === $name);
    }

    /**
     * Check if the NativeServiceProvider has been published.
     */
    public function hasPluginsProvider(): bool
    {
        return $this->discovery->hasPluginsProvider();
    }

    public function find(string $name): ?Plugin
    {
        return $this->all()->first(fn (Plugin $p) => $p->name === $name);
    }

    public function has(string $name): bool
    {
        return $this->find($name) !== null;
    }

    public function count(): int
    {
        return $this->all()->count();
    }

    public function bridgeFunctions(): array
    {
        return $this->discovery->getAllBridgeFunctions();
    }

    public function androidPermissions(): array
    {
        return $this->discovery->getAllAndroidPermissions();
    }

    public function iosInfoPlist(): array
    {
        return $this->discovery->getAllIosInfoPlist();
    }

    public function androidDependencies(): array
    {
        $allDeps = [];

        foreach ($this->all() as $plugin) {
            $deps = $plugin->getAndroidDependencies();

            foreach ($deps as $type => $libraries) {
                if (! isset($allDeps[$type])) {
                    $allDeps[$type] = [];
                }

                $allDeps[$type] = array_merge($allDeps[$type], $libraries);
            }
        }

        return $allDeps;
    }

    public function iosDependencies(): array
    {
        $allDeps = [];

        foreach ($this->all() as $plugin) {
            $deps = $plugin->getIosDependencies();

            foreach ($deps as $type => $packages) {
                if (! isset($allDeps[$type])) {
                    $allDeps[$type] = [];
                }

                $allDeps[$type] = array_merge($allDeps[$type], $packages);
            }
        }

        return $allDeps;
    }

    public function withAndroidCode(): Collection
    {
        return $this->discovery->discoverWithAndroidCode();
    }

    public function withIosCode(): Collection
    {
        return $this->discovery->discoverWithIosCode();
    }

    public function withEvents(): Collection
    {
        return $this->discovery->discoverWithEvents();
    }

    public function events(): array
    {
        return $this->all()
            ->flatMap(fn (Plugin $plugin) => $plugin->getEvents())
            ->all();
    }

    public function serviceProviders(): array
    {
        return $this->all()
            ->map(fn (Plugin $plugin) => $plugin->getServiceProvider())
            ->filter()
            ->values()
            ->all();
    }

    public function refresh(): void
    {
        $this->discovery->clearCache();
    }

    /**
     * Detect conflicts between registered plugins.
     *
     * @return array<array{type: string, value: string, plugins: array<string>}>
     */
    public function detectConflicts(): array
    {
        $conflicts = [];
        $plugins = $this->all();

        $namespaces = [];
        $functionNames = [];

        foreach ($plugins as $plugin) {
            $ns = $plugin->getNamespace();

            // Check namespace collision
            if (isset($namespaces[$ns])) {
                $conflicts[] = [
                    'type' => 'namespace',
                    'value' => $ns,
                    'plugins' => [$namespaces[$ns], $plugin->name],
                ];
            }
            $namespaces[$ns] = $plugin->name;

            // Check bridge function name collision
            foreach ($plugin->getBridgeFunctions() as $func) {
                $name = $func['name'];
                if (isset($functionNames[$name])) {
                    $conflicts[] = [
                        'type' => 'function',
                        'value' => $name,
                        'plugins' => [$functionNames[$name], $plugin->name],
                    ];
                }
                $functionNames[$name] = $plugin->name;
            }
        }

        return array_merge($conflicts, $this->detectDependencyConflicts($plugins));
    }

    /**
     * Detect native dependency version conflicts between plugins.
     *
     * @return array<array{type: string, value: string, plugins: array<string>}>
     */
    protected function detectDependencyConflicts(Collection $plugins): array
    {
        $conflicts = [];

        // Collect all dependencies grouped by artifact identifier
        $androidDeps = [];  // keyed by "group:artifact"
        $podDeps = [];      // keyed by pod name
        $swiftPkgDeps = []; // keyed by normalized URL

        foreach ($plugins as $plugin) {
            // Android Gradle dependencies
            foreach ($plugin->getAndroidDependencies() as $type => $libraries) {
                foreach ($libraries as $library) {
                    $parts = explode(':', $library);
                    if (count($parts) >= 3) {
                        $artifact = $parts[0].':'.$parts[1];
                        $version = $parts[2];
                        $androidDeps[$artifact][] = [
                            'version' => $version,
                            'plugin' => $plugin->name,
                        ];
                    }
                }
            }

            // iOS dependencies
            $iosDeps = $plugin->getIosDependencies();

            // CocoaPods
            foreach ($iosDeps['pods'] ?? [] as $pod) {
                if (! isset($pod['name'])) {
                    continue;
                }
                $podDeps[$pod['name']][] = [
                    'version' => $pod['version'] ?? null,
                    'plugin' => $plugin->name,
                ];
            }

            // Swift Packages
            foreach ($iosDeps['swift_packages'] ?? [] as $pkg) {
                if (! isset($pkg['url'])) {
                    continue;
                }
                $normalizedUrl = rtrim(preg_replace('/\.git$/', '', $pkg['url']), '/');
                $swiftPkgDeps[$normalizedUrl][] = [
                    'version' => $pkg['version'] ?? null,
                    'plugin' => $plugin->name,
                ];
            }
        }

        // Check for Android dependency version conflicts
        foreach ($androidDeps as $artifact => $entries) {
            $versions = array_unique(array_column($entries, 'version'));
            if (count($versions) > 1) {
                $pluginNames = array_unique(array_column($entries, 'plugin'));
                $versionList = implode(' vs ', $versions);
                $conflicts[] = [
                    'type' => 'android_dependency',
                    'value' => "{$artifact} ({$versionList})",
                    'plugins' => array_values($pluginNames),
                ];
            }
        }

        // Check for CocoaPods version conflicts
        foreach ($podDeps as $podName => $entries) {
            $versioned = array_filter($entries, fn ($e) => $e['version'] !== null);
            $versions = array_unique(array_column($versioned, 'version'));
            if (count($versions) > 1) {
                $pluginNames = array_unique(array_column($entries, 'plugin'));
                $versionList = implode(' vs ', $versions);
                $conflicts[] = [
                    'type' => 'ios_pod_dependency',
                    'value' => "{$podName} ({$versionList})",
                    'plugins' => array_values($pluginNames),
                ];
            }
        }

        // Check for Swift Package version conflicts
        foreach ($swiftPkgDeps as $url => $entries) {
            $versioned = array_filter($entries, fn ($e) => $e['version'] !== null);
            $versions = array_unique(array_column($versioned, 'version'));
            if (count($versions) > 1) {
                $pluginNames = array_unique(array_column($entries, 'plugin'));
                $versionList = implode(' vs ', $versions);
                $conflicts[] = [
                    'type' => 'ios_swift_package_dependency',
                    'value' => "{$url} ({$versionList})",
                    'plugins' => array_values($pluginNames),
                ];
            }
        }

        return $conflicts;
    }

    public function getIterator(): Traversable
    {
        return $this->all()->getIterator();
    }
}
