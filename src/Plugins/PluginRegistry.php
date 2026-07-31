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

        return $conflicts;
    }

    public function getIterator(): Traversable
    {
        return $this->all()->getIterator();
    }
}
