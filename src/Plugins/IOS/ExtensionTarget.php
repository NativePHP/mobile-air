<?php

namespace Native\Mobile\Plugins\IOS;

use InvalidArgumentException;

final readonly class ExtensionTarget
{
    private const TYPE = 'widget-extension';

    private const MANAGED_INFO_PLIST_KEYS = [
        'CFBundleExecutable',
        'CFBundleIdentifier',
        'CFBundleInfoDictionaryVersion',
        'CFBundleName',
        'CFBundlePackageType',
        'CFBundleShortVersionString',
        'CFBundleVersion',
    ];

    /**
     * @param  array<string, mixed>  $infoPlist
     */
    private function __construct(
        public string $name,
        public string $bundleIdSuffix,
        public string $deploymentTarget,
        public string $sourcesDirectory,
        public array $infoPlist,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?string $defaultDeploymentTarget = null, int $index = 0): self
    {
        $context = "iOS extension target at index {$index}";
        $type = $data['type'] ?? null;

        if ($type !== self::TYPE) {
            throw new InvalidArgumentException("{$context} type must be '".self::TYPE."'.");
        }

        $name = self::requiredString($data, 'name', $context);
        if (! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("{$context} has an invalid name.");
        }

        $bundleIdSuffix = self::requiredString($data, 'bundle_id_suffix', $context);
        $bundleComponent = '[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?';
        if (! preg_match("/^{$bundleComponent}(?:\\.{$bundleComponent})*$/", $bundleIdSuffix)) {
            throw new InvalidArgumentException("{$context} has an invalid bundle_id_suffix.");
        }

        $sourcesDirectory = self::requiredString($data, 'sources_dir', $context);
        self::validateSourcesDirectory($sourcesDirectory, $context);

        $deploymentTarget = $data['deployment_target'] ?? $defaultDeploymentTarget ?? '17.0';
        if (! is_string($deploymentTarget)
            || ! preg_match('/^\d+(?:\.\d+){1,2}$/', $deploymentTarget)
            || version_compare($deploymentTarget, '14.0', '<')) {
            throw new InvalidArgumentException("{$context} has an invalid deployment_target; WidgetKit requires iOS 14.0 or newer.");
        }

        $infoPlist = $data['info_plist'] ?? [];
        if (! is_array($infoPlist) || ($infoPlist !== [] && array_is_list($infoPlist))) {
            throw new InvalidArgumentException("{$context} info_plist must be an object.");
        }

        foreach (self::MANAGED_INFO_PLIST_KEYS as $managedKey) {
            if (array_key_exists($managedKey, $infoPlist)) {
                throw new InvalidArgumentException("{$context} info_plist cannot override compiler-managed key {$managedKey}.");
            }
        }

        if (array_key_exists('NSExtension', $infoPlist)) {
            $extensionInfo = $infoPlist['NSExtension'];
            if (! is_array($extensionInfo) || ($extensionInfo !== [] && array_is_list($extensionInfo))) {
                throw new InvalidArgumentException("{$context} info_plist.NSExtension must be an object.");
            }
        }

        $hasExtensionPoint = array_key_exists(
            'NSExtensionPointIdentifier',
            $infoPlist['NSExtension'] ?? []
        );
        $extensionPoint = $infoPlist['NSExtension']['NSExtensionPointIdentifier'] ?? null;
        if ($hasExtensionPoint && $extensionPoint !== 'com.apple.widgetkit-extension') {
            throw new InvalidArgumentException("{$context} must use the WidgetKit extension point.");
        }

        $infoPlist['NSExtension'] = array_replace(
            ['NSExtensionPointIdentifier' => 'com.apple.widgetkit-extension'],
            is_array($infoPlist['NSExtension'] ?? null) ? $infoPlist['NSExtension'] : []
        );

        return new self($name, $bundleIdSuffix, $deploymentTarget, $sourcesDirectory, $infoPlist);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requiredString(array $data, string $key, string $context): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$context} is missing a valid {$key}.");
        }

        if ($value !== trim($value)) {
            throw new InvalidArgumentException("{$context} {$key} cannot contain surrounding whitespace.");
        }

        return $value;
    }

    private static function validateSourcesDirectory(string $directory, string $context): void
    {
        $segments = explode('/', $directory);
        $isUnsafe = str_starts_with($directory, '/')
            || str_contains($directory, '\\')
            || str_contains($directory, "\0")
            || in_array('..', $segments, true)
            || in_array('.', $segments, true)
            || in_array('', $segments, true);

        if ($isUnsafe || ! preg_match('/^[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)*$/', $directory)) {
            throw new InvalidArgumentException("{$context} has an unsafe sources_dir.");
        }
    }
}
