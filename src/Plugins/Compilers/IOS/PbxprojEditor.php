<?php

namespace Native\Mobile\Plugins\Compilers\IOS;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Native\Mobile\Plugins\IOS\ExtensionTarget;
use RuntimeException;

final class PbxprojEditor
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $projectPath,
        private readonly ExtensionTargetProjectRenderer $renderer,
    ) {}

    /**
     * @param  list<ExtensionTarget>  $targets
     */
    public function update(array $targets): void
    {
        if (! $this->files->exists($this->projectPath)) {
            throw new RuntimeException("Xcode project file not found at {$this->projectPath}.");
        }

        $original = $this->files->get($this->projectPath);
        $stripper = new PbxprojManagedObjectStripper;
        $managedTargetNames = $stripper->targetNames($original);
        $hasManagedMarkers = str_contains($original, 'NATIVEPHP_EXTENSIONS_BEGIN');
        $hasManagedObjects = $stripper->hasManagedContent($original);

        if ($targets === [] && ! $hasManagedMarkers && ! $hasManagedObjects) {
            return;
        }

        $simulatorTargetId = $this->targetId($original, 'NativePHP-simulator');
        $simulatorConfigurationIds = $this->targetConfigurationIds($original, $simulatorTargetId);
        $targetNames = array_values(array_unique([
            ...$managedTargetNames,
            ...array_map(fn (ExtensionTarget $target): string => $target->name, $targets),
        ]));
        $contents = $stripper->strip($this->stripManagedBlocks($original), $targetNames);

        if ($hasManagedObjects) {
            $contents = $stripper->stripBuildSetting(
                $contents,
                $simulatorConfigurationIds,
                'CODE_SIGN_ENTITLEMENTS = NativePHP/NativePHP.entitlements;'
            );
        }

        if ($targets === []) {
            if ($contents !== $original) {
                $this->files->put($this->projectPath, $contents);
            }

            return;
        }

        $projectId = $this->objectId($contents, 'PBXProject');
        $deviceTargetId = $this->targetId($contents, 'NativePHP');
        $simulatorTargetId = $this->targetId($contents, 'NativePHP-simulator');
        $mainGroupId = $this->settingId($contents, 'mainGroup');
        $productsGroupId = $this->settingId($contents, 'productRefGroup');

        foreach ($targets as $target) {
            $this->assertTargetIsAvailable($contents, $target);
        }

        foreach ($this->renderer->sections($targets, $projectId) as $section => $entries) {
            $contents = $this->insertIntoSection($contents, $section, $entries);
        }

        $copyFiles = $this->managedBlock(
            'PBXCopyFilesBuildPhaseSection',
            $this->renderer->copyFilesSection($targets)
        );
        $contents = $this->insertBefore($contents, '/* Begin PBXFileReference section */', $copyFiles);

        $contents = $this->insertIntoObjectList(
            $contents,
            $mainGroupId,
            'children',
            'MainGroupChildren',
            $this->renderer->mainGroupReferences($targets)
        );
        $contents = $this->insertIntoObjectList(
            $contents,
            $productsGroupId,
            'children',
            'ProductsGroupChildren',
            $this->renderer->productReferences($targets)
        );
        $contents = $this->insertIntoObjectList(
            $contents,
            $projectId,
            'targets',
            'ProjectTargets',
            $this->renderer->targetReferences($targets)
        );

        $contents = $this->insertHostReferences($contents, $deviceTargetId, $targets, 'device');
        $contents = $this->insertHostReferences($contents, $simulatorTargetId, $targets, 'simulator');

        foreach ($this->targetConfigurationIds($contents, $simulatorTargetId) as $configurationId) {
            $contents = $this->insertIntoBuildSettings(
                $contents,
                $configurationId,
                "SimulatorEntitlements{$configurationId}",
                "\t\t\t\tCODE_SIGN_ENTITLEMENTS = NativePHP/NativePHP.entitlements;"
            );
        }

        $this->files->put($this->projectPath, $contents);
    }

    /**
     * @param  list<ExtensionTarget>  $targets
     */
    private function insertHostReferences(string $contents, string $targetId, array $targets, string $host): string
    {
        $contents = $this->insertIntoObjectList(
            $contents,
            $targetId,
            'buildPhases',
            ucfirst($host).'BuildPhases',
            $this->renderer->copyPhaseReference($host)
        );

        return $this->insertIntoObjectList(
            $contents,
            $targetId,
            'dependencies',
            ucfirst($host).'Dependencies',
            $this->renderer->dependencyReferences($targets, $host)
        );
    }

    private function stripManagedBlocks(string $contents): string
    {
        $pattern = '/^[ \t]*\/\* NATIVEPHP_EXTENSIONS_BEGIN ([A-Za-z0-9_-]+) \*\/\R'
            .'.*?^[ \t]*\/\* NATIVEPHP_EXTENSIONS_END \1 \*\/\R?/ms';

        return preg_replace($pattern, '', $contents) ?? $contents;
    }

    private function insertIntoSection(string $contents, string $section, string $entries): string
    {
        $anchor = "/* End {$section} section */";

        return $this->insertBefore(
            $contents,
            $anchor,
            $this->managedBlock($section, $entries, "\t\t")
        );
    }

    private function insertBefore(string $contents, string $anchor, string $insertion): string
    {
        if (! str_contains($contents, $anchor)) {
            throw new RuntimeException("Unable to find required Xcode project anchor: {$anchor}");
        }

        return str_replace($anchor, $insertion."\n".$anchor, $contents);
    }

    private function insertIntoObjectList(
        string $contents,
        string $objectId,
        string $list,
        string $key,
        string $entries
    ): string {
        $pattern = '/('.preg_quote($objectId, '/').'(?: \/\*[^*\r\n]*\*\/)? = \{.*?'
            .'\n\t\t\t'.preg_quote($list, '/').' = \()(.*?)(\n\t\t\t\);)/s';

        $updated = preg_replace_callback($pattern, function (array $matches) use ($key, $entries): string {
            $existing = rtrim($matches[2]);
            $separator = $existing === '' ? '' : "\n";

            return $matches[1].$existing.$separator."\n"
                .$this->managedBlock($key, $entries, "\t\t\t\t")
                .$matches[3];
        }, $contents, 1, $count);

        if ($updated === null || $count !== 1) {
            throw new RuntimeException("Unable to update Xcode project list {$list} on object {$objectId}.");
        }

        return $updated;
    }

    private function insertIntoBuildSettings(
        string $contents,
        string $configurationId,
        string $key,
        string $setting
    ): string {
        $pattern = '/('.preg_quote($configurationId, '/').'(?: \/\*[^*\r\n]*\*\/)? = \{.*?'
            .'\n\t\t\tbuildSettings = \{)(.*?)(\n\t\t\t\};.*?\n\t\t\};)/s';

        $updated = preg_replace_callback($pattern, function (array $matches) use ($key, $setting): string {
            return $matches[1].rtrim($matches[2])."\n"
                .$this->managedBlock($key, $setting, "\t\t\t\t")
                .$matches[3];
        }, $contents, 1, $count);

        if ($updated === null || $count !== 1) {
            throw new RuntimeException("Unable to update Xcode build configuration {$configurationId}.");
        }

        return $updated;
    }

    private function managedBlock(string $key, string $contents, string $indent = ''): string
    {
        return "{$indent}/* NATIVEPHP_EXTENSIONS_BEGIN {$key} */\n"
            .$contents."\n"
            ."{$indent}/* NATIVEPHP_EXTENSIONS_END {$key} */";
    }

    private function objectId(string $contents, string $isa): string
    {
        $pattern = '/([A-F0-9]{24})(?: \/\*[^*\r\n]*\*\/)? = \{\s+isa = '.preg_quote($isa, '/').';/';

        if (! preg_match($pattern, $contents, $matches)) {
            throw new RuntimeException("Unable to locate {$isa} in the Xcode project.");
        }

        return $matches[1];
    }

    private function targetId(string $contents, string $name): string
    {
        $pattern = '/([A-F0-9]{24}) \/\* '.preg_quote($name, '/').' \*\/ = \{\s+isa = PBXNativeTarget;/';

        if (! preg_match($pattern, $contents, $matches)) {
            throw new RuntimeException("Unable to locate Xcode target {$name}.");
        }

        return $matches[1];
    }

    private function settingId(string $contents, string $setting): string
    {
        if (! preg_match('/'.preg_quote($setting, '/').' = ([A-F0-9]{24})/', $contents, $matches)) {
            throw new RuntimeException("Unable to locate Xcode project setting {$setting}.");
        }

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private function targetConfigurationIds(string $contents, string $targetId): array
    {
        $targetPattern = '/'.preg_quote($targetId, '/').'(?: \/\*[^*\r\n]*\*\/)? = \{\s+'
            .'isa = PBXNativeTarget;.*?buildConfigurationList = ([A-F0-9]{24})/s';
        if (! preg_match($targetPattern, $contents, $targetMatch)) {
            throw new RuntimeException('Unable to locate the simulator configuration list.');
        }

        $listPattern = '/'.preg_quote($targetMatch[1], '/').'(?: \/\*[^*\r\n]*\*\/)? = \{\s+'
            .'isa = XCConfigurationList;.*?buildConfigurations = \((.*?)\);/s';
        if (! preg_match($listPattern, $contents, $listMatch)) {
            throw new RuntimeException('Unable to read the simulator build configurations.');
        }

        preg_match_all('/([A-F0-9]{24}) \/\* (?:Debug|Release) \*\//', $listMatch[1], $matches);

        return array_values(array_unique($matches[1]));
    }

    private function assertTargetIsAvailable(string $contents, ExtensionTarget $target): void
    {
        $pattern = '/[A-F0-9]{24} \/\* '.preg_quote($target->name, '/').' \*\/ = \{\s+isa = PBXNativeTarget;/';

        if (preg_match($pattern, $contents)) {
            throw new InvalidArgumentException("The Xcode project already contains a target named {$target->name}.");
        }
    }
}
