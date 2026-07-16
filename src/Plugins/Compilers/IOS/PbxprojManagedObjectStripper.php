<?php

namespace Native\Mobile\Plugins\Compilers\IOS;

final class PbxprojManagedObjectStripper
{
    /** @return list<string> */
    public function targetNames(string $contents): array
    {
        $pattern = '/^[ \t]*([A-F0-9]{24})(?: \/\*[^*\r\n]*\*\/)? = \{'
            .'.*?^[ \t]*isa = PBXNativeTarget;(?<body>.*?)^[ \t]*\};\R?/ms';
        preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER);

        $names = [];
        foreach ($matches as $match) {
            if (! preg_match('/^[ \t]*name = "?([^";]+)"?;/m', $match['body'], $nameMatch)) {
                continue;
            }

            $name = $nameMatch[1];
            if (hash_equals(ExtensionTargetProjectId::for($name, 'target'), $match[1])) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    public function hasManagedContent(string $contents): bool
    {
        if ($this->targetNames($contents) !== []) {
            return true;
        }

        foreach (ExtensionTargetProjectId::hostIds() as $id) {
            if (str_contains($contents, $id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $targetNames
     */
    public function strip(string $contents, array $targetNames): string
    {
        $ids = ExtensionTargetProjectId::hostIds();
        foreach (array_unique($targetNames) as $targetName) {
            array_push($ids, ...ExtensionTargetProjectId::targetIds($targetName));
        }

        $contents = $this->stripObjectDefinitions($contents, $ids);
        $contents = $this->stripReferenceLines($contents, $ids);

        return preg_replace(
            '/^[ \t]*\/\* Begin PBXCopyFilesBuildPhase section \*\/\R'
                .'[ \t\r\n]*\/\* End PBXCopyFilesBuildPhase section \*\/\R?/m',
            '',
            $contents
        ) ?? $contents;
    }

    /**
     * @param  list<string>  $configurationIds
     */
    public function stripBuildSetting(string $contents, array $configurationIds, string $setting): string
    {
        foreach ($configurationIds as $configurationId) {
            $pattern = '/(^[ \t]*'.preg_quote($configurationId, '/').'(?: \/\*[^*\r\n]*\*\/)? = \{'
                .'.*?^[ \t]*buildSettings = \{)(?<settings>.*?)(^[ \t]*\};)/ms';
            $contents = preg_replace_callback($pattern, function (array $matches) use ($setting): string {
                $settings = preg_replace(
                    '/^[ \t]*'.preg_quote($setting, '/').'[ \t]*\R?/m',
                    '',
                    $matches['settings']
                ) ?? $matches['settings'];

                return $matches[1].$settings.$matches[3];
            }, $contents, 1) ?? $contents;
        }

        return $contents;
    }

    /**
     * @param  list<string>  $ids
     */
    private function stripObjectDefinitions(string $contents, array $ids): string
    {
        $managedIds = array_fill_keys($ids, true);
        $lines = preg_split('/(?<=\n)/', $contents) ?: [$contents];
        $result = '';
        $depth = 0;

        foreach ($lines as $line) {
            if ($depth === 0
                && preg_match('/^[ \t]*([A-F0-9]{24})(?: \/\*[^*\r\n]*\*\/)? = \{/', $line, $match)
                && isset($managedIds[$match[1]])) {
                $depth = $this->braceDepth($line);

                continue;
            }

            if ($depth > 0) {
                $depth += $this->braceDepth($line);

                continue;
            }

            $result .= $line;
        }

        return $result;
    }

    /**
     * @param  list<string>  $ids
     */
    private function stripReferenceLines(string $contents, array $ids): string
    {
        $pattern = '/^.*\b(?:'.implode('|', array_map(fn (string $id): string => preg_quote($id, '/'), $ids)).')\b.*(?:\R|$)/m';

        return preg_replace($pattern, '', $contents) ?? $contents;
    }

    private function braceDepth(string $line): int
    {
        return substr_count($line, '{') - substr_count($line, '}');
    }
}
