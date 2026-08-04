<?php

namespace Native\Mobile\Plugins;

use InvalidArgumentException;
use JsonSerializable;

class PluginManifest implements JsonSerializable
{
    public readonly string $namespace;

    public readonly array $bridgeFunctions;

    public readonly array $android;

    public readonly array $ios;

    public readonly array $assets;

    public readonly array $events;

    public readonly array $hooks;

    public readonly array $secrets;

    public readonly array $components;

    /**
     * Names of the optional feature bundles whose env gate is enabled, in
     * manifest order. Everything they declare is already merged into the
     * sections above — this is what they were, for tooling and diagnostics.
     *
     * @var list<string>
     */
    public readonly array $enabledFeatures;

    /**
     * Every feature bundle declared by the manifest, enabled or not, keyed
     * by name. `native:plugin:list` reads this to show what an app could
     * turn on.
     *
     * @var array<string, array<string, mixed>>
     */
    public readonly array $features;

    public function __construct(array $data, ?callable $envResolver = null)
    {
        $this->validate($data);

        // Normalize the data to new format
        $data = $this->normalizeToNewFormat($data);

        // Fold enabled feature bundles into the platform sections BEFORE
        // they're assigned: every downstream consumer (both compilers,
        // plugin:list, the Plugin accessors) then sees one effective
        // manifest and needs no feature awareness of its own.
        $this->features = $data['features'] ?? [];
        [$data, $enabled] = $this->resolveFeatures($data, $envResolver);
        $this->enabledFeatures = $enabled;

        $this->namespace = $data['namespace'];
        $this->bridgeFunctions = $data['bridge_functions'] ?? [];
        $this->components = $data['components'] ?? [];
        $this->android = $data['android'] ?? [];
        $this->ios = $data['ios'] ?? [];
        $this->assets = $data['assets'] ?? [];
        $this->events = $data['events'] ?? [];
        $this->hooks = $data['hooks'] ?? [];
        $this->secrets = $data['secrets'] ?? [];
    }

    /**
     * Merge every enabled feature bundle into the manifest's own sections.
     *
     * A bundle looks like a miniature manifest — it may declare
     * `bridge_functions`, `events`, and `ios` / `android` sections — and
     * is gated by one environment variable:
     *
     *   "features": {
     *       "crashlytics": {
     *           "env": "NATIVEPHP_FIREBASE_CRASHLYTICS",
     *           "bridge_functions": [ ... ],
     *           "ios": {"dependencies": {"swift_packages": [ ... ]}},
     *           "android": {"dependencies": {"implementation": [ ... ]}}
     *       }
     *   }
     *
     * A bundle with no `env` key is always on (a way to group related
     * declarations); an unset or falsy variable leaves every trace of the
     * feature out of the build — no sources, no SDK products, no bridge
     * registrations pointing at classes that were never compiled.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    protected function resolveFeatures(array $data, ?callable $envResolver): array
    {
        $features = $data['features'] ?? [];
        unset($data['features']);

        if ($features === []) {
            return [$data, []];
        }

        $envResolver ??= static fn (string $key) => env($key);
        $enabled = [];

        foreach ($features as $name => $feature) {
            if (! is_array($feature)) {
                continue;
            }

            if (! $this->featureIsEnabled($feature, $envResolver)) {
                continue;
            }

            $enabled[] = (string) $name;

            $data['bridge_functions'] = array_merge(
                $data['bridge_functions'] ?? [],
                $feature['bridge_functions'] ?? [],
            );

            $data['events'] = array_values(array_unique(array_merge(
                $data['events'] ?? [],
                $feature['events'] ?? [],
            )));

            foreach (['ios', 'android'] as $platform) {
                if (isset($feature[$platform]) && is_array($feature[$platform])) {
                    $data[$platform] = $this->mergePlatformSection(
                        $data[$platform] ?? [],
                        $feature[$platform],
                    );
                }
            }
        }

        return [$data, $enabled];
    }

    /**
     * A bundle without an `env` key is unconditional; otherwise the
     * variable is read with Laravel's truthiness ("false"/"0"/"" are off).
     *
     * @param  array<string, mixed>  $feature
     */
    protected function featureIsEnabled(array $feature, callable $envResolver): bool
    {
        if (! isset($feature['env'])) {
            return true;
        }

        return filter_var($envResolver((string) $feature['env']), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Merge one platform section of a feature bundle into the plugin's.
     *
     * List-valued keys concatenate (permissions, capabilities, gradle
     * plugins, …); maps merge key-wise (info_plist, entitlements);
     * `dependencies` recurses so a feature can add Gradle coordinates or
     * Swift package PRODUCTS without restating the package itself.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $addition
     * @return array<string, mixed>
     */
    protected function mergePlatformSection(array $base, array $addition): array
    {
        foreach ($addition as $key => $value) {
            if ($key === 'dependencies' && is_array($value)) {
                $base['dependencies'] = $this->mergeDependencies($base['dependencies'] ?? [], $value);

                continue;
            }

            if (! isset($base[$key])) {
                $base[$key] = $value;

                continue;
            }

            if (is_array($value) && is_array($base[$key])) {
                $base[$key] = array_is_list($value) && array_is_list($base[$key])
                    ? array_merge($base[$key], $value)
                    : array_replace($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Merge dependency blocks. Everything concatenates — including
     * `swift_packages`: the iOS compiler already resolves a repeated
     * package url to the existing XCRemoteSwiftPackageReference and just
     * attaches the new products to the target, so a feature declares the
     * same package url with only the products it needs.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $addition
     * @return array<string, mixed>
     */
    protected function mergeDependencies(array $base, array $addition): array
    {
        foreach ($addition as $key => $value) {
            if (! is_array($value)) {
                $base[$key] = $value;

                continue;
            }

            $base[$key] = array_values(array_unique(array_merge($base[$key] ?? [], $value), SORT_REGULAR));
        }

        return $base;
    }

    /**
     * Normalize old format (scattered platform config) to new format (grouped under android/ios keys)
     *
     * Old format:
     *   permissions: { android: [...], ios: {...} }
     *   dependencies: { android: {...}, ios: {...} }
     *   manifest: { android: { activities: [...] }, ios: {...} }
     *
     * New format:
     *   android: { permissions: [...], dependencies: {...}, activities: [...] }
     *   ios: { permissions: {...}, dependencies: {...} }
     *   assets: { android: {...}, ios: {...} }  // stays at top level
     */
    protected function normalizeToNewFormat(array $data): array
    {
        // If already in new format (has android or ios top-level keys with nested config), return as-is
        if ($this->isNewFormat($data)) {
            return $data;
        }

        // Convert old format to new format
        $android = $data['android'] ?? [];
        $ios = $data['ios'] ?? [];

        // Migrate permissions
        if (isset($data['permissions']['android'])) {
            $android['permissions'] = $data['permissions']['android'];
        }
        if (isset($data['permissions']['ios'])) {
            $ios['info_plist'] = $data['permissions']['ios'];
        }

        // Migrate dependencies
        if (isset($data['dependencies']['android'])) {
            $android['dependencies'] = $data['dependencies']['android'];
        }
        if (isset($data['dependencies']['ios'])) {
            $ios['dependencies'] = $data['dependencies']['ios'];
        }

        // Migrate manifest components (flatten manifest.android into android)
        if (isset($data['manifest']['android'])) {
            $androidManifest = $data['manifest']['android'];
            if (isset($androidManifest['activities'])) {
                $android['activities'] = $androidManifest['activities'];
            }
            if (isset($androidManifest['services'])) {
                $android['services'] = $androidManifest['services'];
            }
            if (isset($androidManifest['receivers'])) {
                $android['receivers'] = $androidManifest['receivers'];
            }
            if (isset($androidManifest['providers'])) {
                $android['providers'] = $androidManifest['providers'];
            }
        }
        if (isset($data['manifest']['ios'])) {
            // Merge any iOS manifest config
            $ios = array_merge($ios, $data['manifest']['ios']);
        }

        $data['android'] = $android;
        $data['ios'] = $ios;

        // Remove old keys (but keep assets as top-level)
        unset($data['permissions'], $data['dependencies'], $data['manifest']);

        return $data;
    }

    /**
     * Detect if data is in new format
     */
    protected function isNewFormat(array $data): bool
    {
        // New format has platform config nested under android/ios keys
        // Check if android or ios has nested platform-specific keys
        $hasNewAndroid = isset($data['android']) && (
            isset($data['android']['permissions']) ||
            isset($data['android']['dependencies']) ||
            isset($data['android']['activities']) ||
            isset($data['android']['services']) ||
            isset($data['android']['receivers']) ||
            isset($data['android']['providers']) ||
            isset($data['android']['gradle_plugins']) ||
            isset($data['android']['min_version'])
        );

        $hasNewIos = isset($data['ios']) && (
            isset($data['ios']['info_plist']) ||
            isset($data['ios']['dependencies']) ||
            isset($data['ios']['min_version'])
        );

        // If we have any new format keys, treat as new format
        if ($hasNewAndroid || $hasNewIos) {
            return true;
        }

        // If we have old format keys (excluding assets which stays top-level), it's old format
        $hasOldFormat = isset($data['permissions']) ||
            isset($data['dependencies']) ||
            isset($data['manifest']);

        return ! $hasOldFormat;
    }

    protected function validate(array $data): void
    {
        // Only namespace is required - name/version/description come from composer.json
        if (empty($data['namespace'])) {
            throw new InvalidArgumentException(
                'Plugin manifest missing required field: namespace'
            );
        }

        // Validate namespace format (valid PHP identifier)
        if (! preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $data['namespace'])) {
            throw new InvalidArgumentException(
                "Plugin manifest has invalid namespace format: {$data['namespace']}"
            );
        }

        // Validate bridge functions structure
        foreach ($data['bridge_functions'] ?? [] as $index => $function) {
            if (empty($function['name'])) {
                throw new InvalidArgumentException(
                    "Bridge function at index {$index} missing 'name'"
                );
            }

            // Validate that at least one platform implementation exists
            if (empty($function['android']) && empty($function['ios'])) {
                throw new InvalidArgumentException(
                    "Bridge function '{$function['name']}' missing platform implementation (android or ios)"
                );
            }
        }

        // Validate components structure (for UI plugins)
        foreach ($data['components'] ?? [] as $index => $component) {
            if (empty($component['type'])) {
                throw new InvalidArgumentException(
                    "Component at index {$index} missing 'type'"
                );
            }

            if (empty($component['element'])) {
                throw new InvalidArgumentException(
                    "Component '{$component['type']}' missing 'element' class"
                );
            }

            if (empty($component['blade'])) {
                throw new InvalidArgumentException(
                    "Component '{$component['type']}' missing 'blade' class"
                );
            }

            // At least one platform renderer required
            if (empty($component['android_renderer']) && empty($component['ios_renderer'])) {
                throw new InvalidArgumentException(
                    "Component '{$component['type']}' missing platform renderer (android_renderer or ios_renderer)"
                );
            }
        }
    }

    public static function fromFile(string $path, ?callable $envResolver = null): static
    {
        if (! file_exists($path)) {
            throw new InvalidArgumentException(
                "Manifest file not found: {$path}"
            );
        }

        $contents = file_get_contents($path);
        $data = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                'Invalid JSON in manifest: '.json_last_error_msg()
            );
        }

        return new static($data, $envResolver);
    }

    public function toArray(): array
    {
        return [
            'namespace' => $this->namespace,
            'bridge_functions' => $this->bridgeFunctions,
            'components' => $this->components,
            'android' => $this->android,
            'ios' => $this->ios,
            'assets' => $this->assets,
            'events' => $this->events,
            'hooks' => $this->hooks,
            'secrets' => $this->secrets,
            'features' => $this->features,
            'enabled_features' => $this->enabledFeatures,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
