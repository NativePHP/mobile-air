<?php

namespace Native\Mobile\Validation;

class BladeTemplateAnalyzer
{
    /**
     * Element types recognized by NativeElementCollector::createElement().
     */
    protected const KNOWN_ELEMENTS = [
        'column', 'row', 'stack', 'scroll_view',
        'text', 'button', 'text_input', 'toggle', 'checkbox',
        'image', 'spacer', 'divider', 'progress_bar',
        'radio_group', 'radio', 'activity_indicator',
        'top_bar', 'top_bar_action', 'bottom_nav', 'bottom_nav_item',
        'fab', 'horizontal_divider',
        'side_nav', 'side_nav_item', 'side_nav_group', 'side_nav_header',
    ];

    /**
     * Elements that have Blade components but are NOT in createElement().
     * These will render via NativeBladeComponent but throw RuntimeException
     * if they somehow reach the collector's createElement match statement.
     */
    protected const BLADE_ONLY_ELEMENTS = [
        'slider', 'icon', 'select', 'badge',
    ];

    /**
     * Container elements that expect children.
     */
    protected const CONTAINERS = [
        'column', 'row', 'stack', 'scroll_view', 'radio_group',
        'top_bar', 'bottom_nav', 'side_nav', 'side_nav_group',
    ];

    /**
     * Elements that support @change callback.
     */
    protected const SUPPORTS_CHANGE = ['text_input', 'toggle'];

    /**
     * Elements that support @submit callback.
     */
    protected const SUPPORTS_SUBMIT = ['text_input'];

    public function analyze(string $filePath, string $content, ValidationResult $result): void
    {
        $relPath = $this->relativePath($filePath);

        // Strip Blade comments and HTML comments before parsing
        $stripped = preg_replace('/\{\{--.*?--\}\}/s', '', $content);
        $stripped = preg_replace('/<!--.*?-->/s', '', $stripped);

        $this->validateNativeTags($relPath, $stripped, $result);
    }

    protected function validateNativeTags(string $filePath, string $content, ValidationResult $result): void
    {
        // Match <native:tag-name ...> (opening/self-closing) and </native:tag-name>
        // Also match <x-native-tag-name ...> form
        $pattern = '/<\s*(?:native\s*:\s*|x-native-)([a-zA-Z0-9\-_]+)(\s[^>]*)?\s*\/?>|<\/\s*(?:native\s*:\s*|x-native-)([a-zA-Z0-9\-_]+)\s*>/';

        if (! preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[0] as $i => $match) {
            $fullMatch = $match[0];
            $offset = $match[1];
            $line = $this->lineNumber($content, $offset);

            // Closing tag — skip validation (we handle it for container checks)
            if (str_starts_with(trim($fullMatch), '</')) {
                continue;
            }

            // Get the tag name from either capture group
            $tagName = $matches[1][$i][0] ?: $matches[3][$i][0];
            $attrs = $matches[2][$i][0] ?? '';

            // Normalize: kebab-case to snake_case
            $elementType = str_replace('-', '_', $tagName);

            // Skip navigation chrome elements (handled by EdgeComponentAnalyzer)
            if ($this->isNavigationElement($elementType)) {
                continue;
            }

            $isSelfClosing = str_ends_with(trim($fullMatch), '/>');

            // Check for unknown element type
            $allKnown = array_merge(self::KNOWN_ELEMENTS, self::BLADE_ONLY_ELEMENTS);
            if (! in_array($elementType, $allKnown)) {
                $result->error($filePath, "Unknown native element type: '{$tagName}'", $line);

                continue;
            }

            // Check @change on unsupported element
            if (preg_match('/_change\s*=/', $attrs) || preg_match('/@change\s*=/', $attrs)) {
                if (! in_array($elementType, self::SUPPORTS_CHANGE)) {
                    $result->error($filePath, "@change not supported on <native:{$tagName}>", $line);
                }
            }

            // Check @submit on unsupported element
            if (preg_match('/_submit\s*=/', $attrs) || preg_match('/@submit\s*=/', $attrs)) {
                if (! in_array($elementType, self::SUPPORTS_SUBMIT)) {
                    $result->error($filePath, "@submit not supported on <native:{$tagName}>", $line);
                }
            }

            // Warn: container element is self-closing (empty)
            if ($isSelfClosing && in_array($elementType, self::CONTAINERS)) {
                $result->warning($filePath, "Container <native:{$tagName} /> is self-closing (renders empty)", $line);
            }

            // Warn: button without label
            if ($elementType === 'button' && ! preg_match('/\blabel\s*=/', $attrs)) {
                $result->warning($filePath, "<native:button> without 'label' attribute", $line);
            }

            // Warn: image without src
            if ($elementType === 'image' && ! preg_match('/\bsrc\s*=/', $attrs)) {
                $result->warning($filePath, "<native:image> without 'src' attribute", $line);
            }
        }
    }

    /**
     * Extract callback method names from template content.
     *
     * @return array<array{method: string, type: string, line: int}>
     */
    public function extractCallbacks(string $content): array
    {
        $callbacks = [];

        // Strip comments
        $stripped = preg_replace('/\{\{--.*?--\}\}/s', '', $content);
        $stripped = preg_replace('/<!--.*?-->/s', '', $stripped);

        // Match @press="method", @longPress="method", @change="method", @submit="method"
        // Also match the precompiled _press="method" form
        $pattern = '/[_@](press|longPress|change|submit)\s*=\s*["\']([^"\']+)["\']/';

        if (preg_match_all($pattern, $stripped, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $type = $matches[1][$i][0];
                $method = $matches[2][$i][0];
                $line = $this->lineNumber($stripped, $match[1]);

                // Skip dynamic values (containing $ or {{ }})
                if (str_contains($method, '$') || str_contains($method, '{{')) {
                    continue;
                }

                $callbacks[] = [
                    'method' => $method,
                    'type' => $type,
                    'line' => $line,
                ];
            }
        }

        return $callbacks;
    }

    protected function isNavigationElement(string $type): bool
    {
        return in_array($type, [
            'top_bar', 'bottom_nav', 'bottom_nav_item',
            'side_nav', 'side_nav_item', 'side_nav_group', 'side_nav_header',
            'top_bar_action', 'fab', 'horizontal_divider',
        ]);
    }

    protected function lineNumber(string $content, int $offset): int
    {
        return substr_count($content, "\n", 0, $offset) + 1;
    }

    protected function relativePath(string $path): string
    {
        $base = base_path().'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
