<?php

namespace Native\Mobile\Plugins\Compilers\IOS;

use Native\Mobile\Plugins\IOS\ExtensionTarget;

final readonly class ExtensionTargetProjectRenderer
{
    public function __construct(
        private string $appId,
        private string $version,
        private string $versionCode,
        private string $developmentTeam = '',
        private array $provisioningProfiles = [],
    ) {}

    /**
     * @param  list<ExtensionTarget>  $targets
     * @return array<string, string>
     */
    public function sections(array $targets, string $projectId): array
    {
        $sections = array_fill_keys([
            'PBXBuildFile',
            'PBXContainerItemProxy',
            'PBXFileReference',
            'PBXFileSystemSynchronizedBuildFileExceptionSet',
            'PBXFileSystemSynchronizedRootGroup',
            'PBXFrameworksBuildPhase',
            'PBXNativeTarget',
            'PBXResourcesBuildPhase',
            'PBXSourcesBuildPhase',
            'PBXTargetDependency',
            'XCBuildConfiguration',
            'XCConfigurationList',
        ], []);

        foreach ($targets as $target) {
            foreach ($this->targetSections($target, $projectId) as $section => $entry) {
                $sections[$section][] = $entry;
            }
        }

        return array_map(fn (array $entries): string => implode("\n", $entries), $sections);
    }

    public function copyFilesSection(array $targets): string
    {
        $deviceFiles = $this->embedFileReferences($targets, 'device');
        $simulatorFiles = $this->embedFileReferences($targets, 'simulator');

        return <<<PBX
/* Begin PBXCopyFilesBuildPhase section */
		{$this->id('hosts', 'device-copy-phase')} /* Embed App Extensions */ = {
			isa = PBXCopyFilesBuildPhase;
			buildActionMask = 2147483647;
			dstPath = "";
			dstSubfolderSpec = 13;
			files = (
{$deviceFiles}
			);
			name = "Embed App Extensions";
			runOnlyForDeploymentPostprocessing = 0;
		};
		{$this->id('hosts', 'simulator-copy-phase')} /* Embed App Extensions */ = {
			isa = PBXCopyFilesBuildPhase;
			buildActionMask = 2147483647;
			dstPath = "";
			dstSubfolderSpec = 13;
			files = (
{$simulatorFiles}
			);
			name = "Embed App Extensions";
			runOnlyForDeploymentPostprocessing = 0;
		};
/* End PBXCopyFilesBuildPhase section */
PBX;
    }

    public function mainGroupReferences(array $targets): string
    {
        return $this->references($targets, 'sync-group', fn (ExtensionTarget $target): string => "Extensions/{$target->name}");
    }

    public function productReferences(array $targets): string
    {
        return $this->references($targets, 'product', fn (ExtensionTarget $target): string => "{$target->name}.appex");
    }

    public function targetReferences(array $targets): string
    {
        return $this->references($targets, 'target', fn (ExtensionTarget $target): string => $target->name);
    }

    public function dependencyReferences(array $targets, string $host): string
    {
        return $this->references($targets, "{$host}-dependency", fn (ExtensionTarget $target): string => 'PBXTargetDependency');
    }

    public function copyPhaseReference(string $host): string
    {
        return "\t\t\t\t{$this->id('hosts', "{$host}-copy-phase")} /* Embed App Extensions */,";
    }

    private function targetSections(ExtensionTarget $target, string $projectId): array
    {
        $name = $target->name;
        $product = "{$name}.appex";
        $entitlements = "{$name}.entitlements";
        $targetId = $this->id($name, 'target');

        return [
            'PBXBuildFile' => "\t\t{$this->id($name, 'device-embed-file')} /* {$product} in Embed App Extensions */ = {isa = PBXBuildFile; fileRef = {$this->id($name, 'product')} /* {$product} */; settings = {ATTRIBUTES = (RemoveHeadersOnCopy, ); }; };\n"
                ."\t\t{$this->id($name, 'simulator-embed-file')} /* {$product} in Embed App Extensions */ = {isa = PBXBuildFile; fileRef = {$this->id($name, 'product')} /* {$product} */; settings = {ATTRIBUTES = (RemoveHeadersOnCopy, ); }; };",
            'PBXContainerItemProxy' => $this->containerProxy($target, $projectId, 'device')."\n".$this->containerProxy($target, $projectId, 'simulator'),
            'PBXFileReference' => "\t\t{$this->id($name, 'product')} /* {$product} */ = {isa = PBXFileReference; explicitFileType = \"wrapper.app-extension\"; includeInIndex = 0; path = {$product}; sourceTree = BUILT_PRODUCTS_DIR; };",
            'PBXFileSystemSynchronizedBuildFileExceptionSet' => <<<PBX
		{$this->id($name, 'exceptions')} /* Exceptions for "{$name}" folder in "{$name}" target */ = {
			isa = PBXFileSystemSynchronizedBuildFileExceptionSet;
			membershipExceptions = (
				Info.plist,
				{$entitlements},
			);
			target = {$targetId} /* {$name} */;
		};
PBX,
            'PBXFileSystemSynchronizedRootGroup' => <<<PBX
		{$this->id($name, 'sync-group')} /* Extensions/{$name} */ = {
			isa = PBXFileSystemSynchronizedRootGroup;
			exceptions = (
				{$this->id($name, 'exceptions')} /* Exceptions for "{$name}" folder in "{$name}" target */,
			);
			path = Extensions/{$name};
			sourceTree = "<group>";
		};
PBX,
            'PBXFrameworksBuildPhase' => $this->emptyBuildPhase($target, 'frameworks', 'PBXFrameworksBuildPhase', 'Frameworks'),
            'PBXNativeTarget' => $this->nativeTarget($target),
            'PBXResourcesBuildPhase' => $this->emptyBuildPhase($target, 'resources', 'PBXResourcesBuildPhase', 'Resources'),
            'PBXSourcesBuildPhase' => $this->emptyBuildPhase($target, 'sources', 'PBXSourcesBuildPhase', 'Sources'),
            'PBXTargetDependency' => $this->targetDependency($target, 'device')."\n".$this->targetDependency($target, 'simulator'),
            'XCBuildConfiguration' => $this->buildConfiguration($target, 'Debug')."\n".$this->buildConfiguration($target, 'Release'),
            'XCConfigurationList' => <<<PBX
		{$this->id($name, 'configuration-list')} /* Build configuration list for PBXNativeTarget "{$name}" */ = {
			isa = XCConfigurationList;
			buildConfigurations = (
				{$this->id($name, 'debug-configuration')} /* Debug */,
				{$this->id($name, 'release-configuration')} /* Release */,
			);
			defaultConfigurationIsVisible = 0;
			defaultConfigurationName = Release;
		};
PBX,
        ];
    }

    private function containerProxy(ExtensionTarget $target, string $projectId, string $host): string
    {
        return <<<PBX
		{$this->id($target->name, "{$host}-proxy")} /* PBXContainerItemProxy */ = {
			isa = PBXContainerItemProxy;
			containerPortal = {$projectId} /* Project object */;
			proxyType = 1;
			remoteGlobalIDString = {$this->id($target->name, 'target')};
			remoteInfo = {$target->name};
		};
PBX;
    }

    private function emptyBuildPhase(ExtensionTarget $target, string $role, string $isa, string $name): string
    {
        return <<<PBX
		{$this->id($target->name, "{$role}-phase")} /* {$name} */ = {
			isa = {$isa};
			buildActionMask = 2147483647;
			files = (
			);
			runOnlyForDeploymentPostprocessing = 0;
		};
PBX;
    }

    private function nativeTarget(ExtensionTarget $target): string
    {
        $name = $target->name;

        return <<<PBX
		{$this->id($name, 'target')} /* {$name} */ = {
			isa = PBXNativeTarget;
			buildConfigurationList = {$this->id($name, 'configuration-list')} /* Build configuration list for PBXNativeTarget "{$name}" */;
			buildPhases = (
				{$this->id($name, 'sources-phase')} /* Sources */,
				{$this->id($name, 'frameworks-phase')} /* Frameworks */,
				{$this->id($name, 'resources-phase')} /* Resources */,
			);
			buildRules = (
			);
			dependencies = (
			);
			fileSystemSynchronizedGroups = (
				{$this->id($name, 'sync-group')} /* Extensions/{$name} */,
			);
			name = {$name};
			productName = {$name};
			productReference = {$this->id($name, 'product')} /* {$name}.appex */;
			productType = "com.apple.product-type.app-extension";
		};
PBX;
    }

    private function targetDependency(ExtensionTarget $target, string $host): string
    {
        return <<<PBX
		{$this->id($target->name, "{$host}-dependency")} /* PBXTargetDependency */ = {
			isa = PBXTargetDependency;
			name = {$target->name};
			target = {$this->id($target->name, 'target')} /* {$target->name} */;
			targetProxy = {$this->id($target->name, "{$host}-proxy")} /* PBXContainerItemProxy */;
		};
PBX;
    }

    private function buildConfiguration(ExtensionTarget $target, string $configuration): string
    {
        $role = strtolower($configuration).'-configuration';
        $validateProduct = $configuration === 'Release' ? "\n\t\t\t\tVALIDATE_PRODUCT = YES;" : '';
        $team = $this->developmentTeam === '' ? '""' : $this->developmentTeam;
        $bundleId = $this->appId.'.'.$target->bundleIdSuffix;
        $profileUuid = $this->provisioningProfiles[$bundleId]['uuid'] ?? null;
        $signingStyle = is_string($profileUuid) ? 'Manual' : 'Automatic';
        $profile = is_string($profileUuid)
            ? "PROVISIONING_PROFILE_SPECIFIER = \"{$profileUuid}\";\n\t\t\t\t"
            : '';

        return <<<PBX
		{$this->id($target->name, $role)} /* {$configuration} */ = {
			isa = XCBuildConfiguration;
			buildSettings = {
				APPLICATION_EXTENSION_API_ONLY = YES;
				CODE_SIGN_ENTITLEMENTS = Extensions/{$target->name}/{$target->name}.entitlements;
				CODE_SIGN_STYLE = {$signingStyle};
				CURRENT_PROJECT_VERSION = {$this->versionCode};
				DEVELOPMENT_TEAM = {$team};
				GENERATE_INFOPLIST_FILE = NO;
				INFOPLIST_FILE = Extensions/{$target->name}/Info.plist;
				IPHONEOS_DEPLOYMENT_TARGET = {$target->deploymentTarget};
				MARKETING_VERSION = {$this->version};
				PRODUCT_BUNDLE_IDENTIFIER = {$bundleId};
				PRODUCT_NAME = "$(TARGET_NAME)";
				{$profile}SDKROOT = iphoneos;
				SKIP_INSTALL = YES;
				SWIFT_EMIT_LOC_STRINGS = YES;
				SWIFT_VERSION = 5.0;
				TARGETED_DEVICE_FAMILY = "1,2";{$validateProduct}
			};
			name = {$configuration};
		};
PBX;
    }

    /**
     * @param  list<ExtensionTarget>  $targets
     */
    private function embedFileReferences(array $targets, string $host): string
    {
        return implode("\n", array_map(
            fn (ExtensionTarget $target): string => "\t\t\t\t{$this->id($target->name, "{$host}-embed-file")} /* {$target->name}.appex in Embed App Extensions */,",
            $targets
        ));
    }

    /**
     * @param  list<ExtensionTarget>  $targets
     * @param  callable(ExtensionTarget): string  $comment
     */
    private function references(array $targets, string $role, callable $comment): string
    {
        return implode("\n", array_map(
            fn (ExtensionTarget $target): string => "\t\t\t\t{$this->id($target->name, $role)} /* {$comment($target)} */,",
            $targets
        ));
    }

    private function id(string $name, string $role): string
    {
        return strtoupper(substr(hash('sha256', "nativephp|ios-extension|{$name}|{$role}"), 0, 24));
    }
}
