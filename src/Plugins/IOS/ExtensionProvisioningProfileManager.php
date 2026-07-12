<?php

namespace Native\Mobile\Plugins\IOS;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Native\Mobile\Plugins\Plugin;
use RuntimeException;
use stdClass;

final class ExtensionProvisioningProfileManager
{
    public const CONFIGURATION_ENV = 'IOS_EXTENSION_PROVISIONING_PROFILES';

    public const METADATA_ENV = 'NATIVEPHP_IOS_EXTENSION_PROVISIONING_PROFILES';

    public function __construct(
        private readonly Filesystem $files,
        private readonly ?string $profilesDirectory = null,
    ) {}

    /**
     * @param  Collection<int, Plugin>  $plugins
     * @return list<string>
     */
    public function bundleIds(Collection $plugins, string $appId): array
    {
        $bundleIds = [];
        $normalizedBundleIds = [];

        foreach ($plugins as $plugin) {
            foreach ($plugin->getIosExtensionTargets() as $index => $configuration) {
                $target = ExtensionTarget::fromArray(
                    $configuration,
                    is_string($plugin->manifest->ios['min_version'] ?? null)
                        ? $plugin->manifest->ios['min_version']
                        : null,
                    $index,
                );

                $bundleId = $appId.'.'.$target->bundleIdSuffix;
                $this->validateBundleId($bundleId);
                $normalizedBundleId = Str::lower($bundleId);

                if (isset($normalizedBundleIds[$normalizedBundleId])) {
                    throw new InvalidArgumentException("Duplicate iOS extension bundle ID: {$bundleId}");
                }

                $bundleIds[] = $bundleId;
                $normalizedBundleIds[$normalizedBundleId] = true;
            }
        }

        return $bundleIds;
    }

    /**
     * @param  Collection<int, Plugin>  $plugins
     * @return array<string, array{uuid: string, name: string}>
     */
    public function install(Collection $plugins, string $appId): array
    {
        $configuration = getenv(self::CONFIGURATION_ENV);
        $this->storeMetadata([]);

        if ($configuration === false || trim($configuration) === '') {
            return [];
        }

        $sources = $this->decodeSources($configuration);
        $bundleIds = $this->bundleIds($plugins, $appId);
        $this->validateConfiguredBundleIds($sources, $bundleIds);

        $profiles = [];
        foreach ($bundleIds as $bundleId) {
            $data = $this->sourceData($sources[$bundleId], $bundleId);
            $profiles[$bundleId] = ProvisioningProfile::fromData($this->files, $data, $bundleId);
        }

        $directory = $this->resolvedProfilesDirectory();
        $this->files->ensureDirectoryExists($directory, 0755);

        $metadata = [];
        foreach ($profiles as $bundleId => $profile) {
            $this->files->replace(
                $directory.'/'.$profile->uuid.'.mobileprovision',
                $profile->contents,
                0644,
            );
            $metadata[$bundleId] = [
                'uuid' => $profile->uuid,
                'name' => $profile->name,
            ];
        }

        return $this->storeMetadata($metadata);
    }

    /**
     * @return array<string, array{uuid: string, name: string}>
     */
    public function installedProfiles(): array
    {
        $metadata = getenv(self::METADATA_ENV);

        if ($metadata === false || trim($metadata) === '') {
            return [];
        }

        $values = $this->decodeObject($metadata, self::METADATA_ENV);
        $profiles = [];

        foreach ($values as $bundleId => $value) {
            $this->validateBundleId($bundleId);

            if (! $value instanceof stdClass) {
                throw new InvalidArgumentException(self::METADATA_ENV.' must map bundle IDs to metadata objects.');
            }

            $profile = get_object_vars($value);
            if (count($profile) !== 2
                || array_diff(array_keys($profile), ['uuid', 'name']) !== []
                || ! ProvisioningProfile::isUuid($profile['uuid'] ?? null)
                || ! is_string($profile['name'] ?? null)
                || trim($profile['name']) === '') {
                throw new InvalidArgumentException(self::METADATA_ENV.' contains invalid profile metadata.');
            }

            $profiles[$bundleId] = [
                'uuid' => $profile['uuid'],
                'name' => $profile['name'],
            ];
        }

        return $profiles;
    }

    /**
     * @return array<string, string>
     */
    private function decodeSources(string $configuration): array
    {
        $values = $this->decodeObject($configuration, self::CONFIGURATION_ENV);
        $sources = [];

        foreach ($values as $bundleId => $source) {
            $this->validateBundleId($bundleId);

            if (! is_string($source) || $source === '') {
                throw new InvalidArgumentException(self::CONFIGURATION_ENV.' values must be profile paths or base64 strings.');
            }

            $sources[$bundleId] = $source;
        }

        return $sources;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $json, string $environmentVariable): array
    {
        try {
            $decoded = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("{$environmentVariable} must contain valid JSON.", previous: $exception);
        }

        if (! $decoded instanceof stdClass) {
            throw new InvalidArgumentException("{$environmentVariable} must contain a JSON object.");
        }

        return get_object_vars($decoded);
    }

    /**
     * @param  array<string, string>  $sources
     * @param  list<string>  $bundleIds
     */
    private function validateConfiguredBundleIds(array $sources, array $bundleIds): void
    {
        $missing = array_values(array_diff($bundleIds, array_keys($sources)));
        $unknown = array_values(array_diff(array_keys($sources), $bundleIds));

        if ($missing !== []) {
            throw new InvalidArgumentException('Missing iOS extension provisioning profile for '.$missing[0].'.');
        }

        if ($unknown !== []) {
            throw new InvalidArgumentException('Provisioning profile configured for undeclared iOS extension '.$unknown[0].'.');
        }
    }

    private function sourceData(string $source, string $bundleId): string
    {
        if ($this->files->isFile($source)) {
            if (! is_readable($source)) {
                throw new InvalidArgumentException("Provisioning profile for {$bundleId} is not readable.");
            }

            return $this->files->get($source);
        }

        $pattern = '/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/D';
        $decoded = preg_match($pattern, $source) === 1 ? base64_decode($source, true) : false;

        if ($decoded === false || $decoded === '' || base64_encode($decoded) !== $source) {
            throw new InvalidArgumentException("Provisioning profile for {$bundleId} must be a readable path or strict base64.");
        }

        return $decoded;
    }

    private function validateBundleId(string $bundleId): void
    {
        $component = '[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?';
        if (! preg_match("/^{$component}(?:\\.{$component})+$/", $bundleId)) {
            throw new InvalidArgumentException("Invalid iOS extension bundle ID: {$bundleId}");
        }
    }

    private function resolvedProfilesDirectory(): string
    {
        if ($this->profilesDirectory !== null) {
            return $this->profilesDirectory;
        }

        $home = getenv('HOME');
        if ($home === false || trim($home) === '') {
            throw new RuntimeException('HOME is required to install iOS extension provisioning profiles.');
        }

        return rtrim($home, '/').'/Library/MobileDevice/Provisioning Profiles';
    }

    /**
     * @param  array<string, array{uuid: string, name: string}>  $metadata
     * @return array<string, array{uuid: string, name: string}>
     */
    private function storeMetadata(array $metadata): array
    {
        $json = json_encode((object) $metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (! putenv(self::METADATA_ENV.'='.$json)) {
            throw new RuntimeException('Unable to store installed iOS extension profile metadata.');
        }

        return $metadata;
    }
}
