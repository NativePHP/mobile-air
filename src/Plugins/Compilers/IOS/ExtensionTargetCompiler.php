<?php

namespace Native\Mobile\Plugins\Compilers\IOS;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Native\Mobile\Plugins\IOS\ExtensionProvisioningProfileManager;
use Native\Mobile\Plugins\IOS\ExtensionTarget;
use Native\Mobile\Plugins\Plugin;

final class ExtensionTargetCompiler
{
    private const OWNERSHIP_FILE = '.nativephp-extension-targets.json';

    private readonly string $extensionsPath;

    private readonly PropertyList $propertyList;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $iosProjectPath,
        private readonly string $appId,
        private readonly array $config = [],
    ) {
        $this->extensionsPath = $iosProjectPath.'/Extensions';
        $this->propertyList = new PropertyList;
    }

    /**
     * @param  Collection<int, Plugin>  $plugins
     */
    public function compile(Collection $plugins): void
    {
        $builds = $this->builds($plugins);
        $this->removePreviouslyManagedTargets($builds);

        $projectPath = $this->iosProjectPath.'/NativePHP.xcodeproj/project.pbxproj';
        if ($builds === []) {
            if ($this->files->exists($projectPath)) {
                $this->editor($projectPath)->update([]);
            }

            return;
        }

        $this->validateAppId();
        $this->files->ensureDirectoryExists($this->extensionsPath);

        $this->writeOwnershipFile($targets = array_map(
            fn (array $build): ExtensionTarget => $build['target'],
            $builds
        ));

        foreach ($builds as $build) {
            $this->writeExtension($build['plugin'], $build['target'], $build['source'], $build['entitlements']);
        }

        $this->editor($projectPath)->update($targets);
    }

    /**
     * @param  Collection<int, Plugin>  $plugins
     * @return list<array{
     *     plugin: Plugin,
     *     target: ExtensionTarget,
     *     source: string,
     *     entitlements: array<string, mixed>
     * }>
     */
    private function builds(Collection $plugins): array
    {
        $builds = [];
        $names = [];
        $bundleIds = [];

        foreach ($plugins as $plugin) {
            foreach ($plugin->getIosExtensionTargets() as $index => $configuration) {
                $target = ExtensionTarget::fromArray(
                    $configuration,
                    is_string($plugin->manifest->ios['min_version'] ?? null)
                        ? $plugin->manifest->ios['min_version']
                        : null,
                    $index
                );
                $bundleId = $this->appId.'.'.$target->bundleIdSuffix;
                $normalizedName = strtolower($target->name);
                $normalizedBundleId = strtolower($bundleId);

                if (isset($names[$normalizedName]) || isset($bundleIds[$normalizedBundleId])) {
                    throw new InvalidArgumentException(
                        "iOS extension target {$target->name} conflicts with another registered plugin."
                    );
                }

                $source = $plugin->path.'/resources/ios/'.$target->sourcesDirectory;
                if (! $this->files->isDirectory($source)) {
                    throw new InvalidArgumentException(
                        "Plugin {$plugin->name} ios.extension_targets sources_dir does not exist: {$target->sourcesDirectory}"
                    );
                }

                $names[$normalizedName] = true;
                $bundleIds[$normalizedBundleId] = true;
                $builds[] = [
                    'plugin' => $plugin,
                    'target' => $target,
                    'source' => $source,
                    'entitlements' => array_intersect_key(
                        $plugin->getIosEntitlements(),
                        ['com.apple.security.application-groups' => true]
                    ),
                ];
            }
        }

        return $builds;
    }

    /**
     * @param  array<string, mixed>  $entitlements
     */
    private function writeExtension(
        Plugin $plugin,
        ExtensionTarget $target,
        string $source,
        array $entitlements
    ): void {
        $destination = $this->extensionsPath.'/'.$target->name;
        $this->files->copyDirectory($source, $destination);

        $infoPlist = array_replace_recursive([
            'CFBundleDisplayName' => $target->name,
            'CFBundleExecutable' => '$(EXECUTABLE_NAME)',
            'CFBundleIdentifier' => '$(PRODUCT_BUNDLE_IDENTIFIER)',
            'CFBundleInfoDictionaryVersion' => '6.0',
            'CFBundleName' => '$(PRODUCT_NAME)',
            'CFBundlePackageType' => 'XPC!',
            'CFBundleShortVersionString' => '$(MARKETING_VERSION)',
            'CFBundleVersion' => '$(CURRENT_PROJECT_VERSION)',
        ], $target->infoPlist);

        $resolver = ManifestValueResolver::forPlugin($this->appId, $plugin);
        $resolvedInfoPlist = $resolver->resolve($infoPlist);
        $resolvedEntitlements = $resolver->resolve($entitlements);

        $this->files->put($destination.'/Info.plist', $this->propertyList->encode($resolvedInfoPlist));
        $this->files->put(
            $destination."/{$target->name}.entitlements",
            $this->propertyList->encode($resolvedEntitlements)
        );
    }

    private function validateAppId(): void
    {
        $component = '[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?';
        if (! preg_match("/^{$component}(?:\\.{$component})+$/", $this->appId)) {
            throw new InvalidArgumentException('A valid NativePHP app ID is required to compile iOS extension targets.');
        }
    }

    /**
     * @param  list<array{plugin: Plugin, target: ExtensionTarget, source: string, entitlements: array<string, mixed>}>  $builds
     */
    private function removePreviouslyManagedTargets(array $builds): void
    {
        $ownedTargets = $this->ownedTargetNames();

        foreach ($ownedTargets as $targetName) {
            $this->files->deleteDirectory($this->extensionsPath.'/'.$targetName);
        }

        foreach ($builds as $build) {
            $targetPath = $this->extensionsPath.'/'.$build['target']->name;
            if ($this->files->isDirectory($targetPath) && ! in_array($build['target']->name, $ownedTargets, true)) {
                throw new InvalidArgumentException(
                    "Refusing to overwrite unowned iOS extension directory: {$build['target']->name}"
                );
            }
        }

        $ownershipPath = $this->ownershipPath();
        if ($this->files->exists($ownershipPath)) {
            $this->files->delete($ownershipPath);
        }

        if ($this->files->isDirectory($this->extensionsPath)
            && iterator_count(new \FilesystemIterator($this->extensionsPath)) === 0) {
            $this->files->deleteDirectory($this->extensionsPath);
        }
    }

    /**
     * @return list<string>
     */
    private function ownedTargetNames(): array
    {
        if (! $this->files->exists($this->ownershipPath())) {
            return [];
        }

        $targets = json_decode($this->files->get($this->ownershipPath()), true);
        if (! is_array($targets) || ! array_is_list($targets)) {
            throw new InvalidArgumentException('NativePHP iOS extension ownership file is invalid.');
        }

        foreach ($targets as $target) {
            if (! is_string($target) || ! preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $target)) {
                throw new InvalidArgumentException('NativePHP iOS extension ownership file contains an invalid target.');
            }
        }

        return $targets;
    }

    /**
     * @param  list<ExtensionTarget>  $targets
     */
    private function writeOwnershipFile(array $targets): void
    {
        $this->files->put(
            $this->ownershipPath(),
            json_encode(array_map(fn (ExtensionTarget $target): string => $target->name, $targets), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n"
        );
    }

    private function ownershipPath(): string
    {
        return $this->extensionsPath.'/'.self::OWNERSHIP_FILE;
    }

    private function editor(string $projectPath): PbxprojEditor
    {
        $profiles = (new ExtensionProvisioningProfileManager($this->files))->installedProfiles();
        $renderer = new ExtensionTargetProjectRenderer(
            $this->appId,
            $this->version(),
            $this->versionCode(),
            $this->developmentTeam(),
            $profiles,
        );

        return new PbxprojEditor($this->files, $projectPath, $renderer);
    }

    private function version(): string
    {
        $version = $this->projectSetting('MARKETING_VERSION')
            ?? (string) ($this->config['version'] ?? '1.0');

        return preg_match('/^\d+(?:\.\d+){1,3}$/', $version) ? $version : '1.0';
    }

    private function versionCode(): string
    {
        $versionCode = $this->projectSetting('CURRENT_PROJECT_VERSION')
            ?? (string) ($this->config['version_code'] ?? '1');

        return ctype_digit($versionCode) ? $versionCode : '1';
    }

    private function developmentTeam(): string
    {
        $configuredTeam = $this->config['development_team'] ?? config('nativephp.development_team', '');
        $team = (string) (getenv('IOS_TEAM_ID') ?: $configuredTeam);

        return preg_match('/^[A-Z0-9]+$/', $team) ? $team : '';
    }

    private function projectSetting(string $name): ?string
    {
        $projectPath = $this->iosProjectPath.'/NativePHP.xcodeproj/project.pbxproj';
        if (! $this->files->exists($projectPath)) {
            return null;
        }

        $contents = $this->files->get($projectPath);
        if (! preg_match('/\b'.preg_quote($name, '/').' = "?([^";]+)"?;/', $contents, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }
}
