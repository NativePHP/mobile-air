<?php

namespace Native\Mobile\Plugins;

use InvalidArgumentException;
use JsonSerializable;

class PluginManifest implements JsonSerializable
{
    /**
     * Every platform a plugin may declare support for.
     */
    public const PLATFORMS = ['android', 'ios'];

    public readonly string $namespace;

    /**
     * Platforms this plugin ships native code for, from the manifest's
     * `platforms` key. Absent means both, so a manifest written before this
     * key was read keeps working.
     *
     * @var list<string>
     */
    public readonly array $platforms;

    public readonly array $bridgeFunctions;

    public readonly array $android;

    public readonly array $ios;

    public readonly array $assets;

    public readonly array $events;

    public readonly array $hooks;

    public readonly array $secrets;

    public readonly array $components;

    public function __construct(array $data)
    {
        $this->validate($data);

        // Normalize the data to new format
        $data = $this->normalizeToNewFormat($data);

        $this->namespace = $data['namespace'];
        $this->platforms = $this->normalizePlatforms($data['platforms'] ?? null);
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
     * Read the manifest's `platforms` key.
     *
     * Absent, null or empty means both platforms: the key has been emitted by
     * `native:plugin:create` for a long time without being read, so treating
     * "not stated" as "android only" would break existing plugins.
     *
     * A typo is rejected rather than ignored. Silently dropping `"IOS"` would
     * turn a spelling mistake into "this plugin has no iOS code", which is
     * exactly the failure this key exists to prevent.
     *
     * @return list<string>
     */
    protected function normalizePlatforms(mixed $platforms): array
    {
        if ($platforms === null || $platforms === []) {
            return static::PLATFORMS;
        }

        if (! is_array($platforms)) {
            throw new InvalidArgumentException(
                'Plugin manifest field `platforms` must be an array of '.implode(', ', static::PLATFORMS)
            );
        }

        $normalized = [];

        foreach ($platforms as $platform) {
            if (! is_string($platform)) {
                throw new InvalidArgumentException(
                    'Plugin manifest field `platforms` must contain strings'
                );
            }

            $platform = strtolower(trim($platform));

            if (! in_array($platform, static::PLATFORMS, true)) {
                throw new InvalidArgumentException(
                    "Plugin manifest declares unknown platform: {$platform}. ".
                    'Allowed: '.implode(', ', static::PLATFORMS)
                );
            }

            $normalized[$platform] = true;
        }

        return array_keys($normalized);
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

    public static function fromFile(string $path): static
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

        return new static($data);
    }

    public function toArray(): array
    {
        return [
            'namespace' => $this->namespace,
            'platforms' => $this->platforms,
            'bridge_functions' => $this->bridgeFunctions,
            'components' => $this->components,
            'android' => $this->android,
            'ios' => $this->ios,
            'assets' => $this->assets,
            'events' => $this->events,
            'hooks' => $this->hooks,
            'secrets' => $this->secrets,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
