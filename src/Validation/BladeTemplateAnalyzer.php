<?php

namespace Native\Mobile\Validation;

use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Plugins\PluginRegistry;

class BladeTemplateAnalyzer
{
    /**
     * Types NativeElementCollector builds itself, without looking them up
     * in the ElementRegistry (see NativeElementCollector::makeElement()).
     */
    protected const BUILTIN_TYPES = [
        'column', 'row', 'stack', 'scroll_view', 'spacer', 'divider',
        'pressable', 'canvas', 'bottom_bar',
    ];

    /**
     * Container elements that expect children.
     */
    protected const CONTAINERS = [
        'column', 'row', 'stack', 'scroll_view', 'radio_group',
        'top_bar', 'bottom_nav', 'side_nav', 'side_nav_group',
    ];

    /**
     * Element type => Element class, as registered by core and by every
     * registered plugin. Null until first needed, so the registry is read
     * after the app has booted.
     *
     * @var array<string, string>|null
     */
    protected ?array $elements;

    /**
     * Element type => package name, for components declared by plugins that
     * are installed but not listed in NativeServiceProvider::plugins().
     *
     * @var array<string, string>|null
     */
    protected ?array $unregisteredPluginTypes;

    /**
     * @param  array<string, string>|null  $elements  Defaults to ElementRegistry::all().
     * @param  array<string, string>|null  $unregisteredPluginTypes  Defaults to the plugin registry.
     */
    public function __construct(?array $elements = null, ?array $unregisteredPluginTypes = null)
    {
        $this->elements = $elements;
        $this->unregisteredPluginTypes = $unregisteredPluginTypes;
    }

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

            // Skip navigation chrome elements (attribute surface differs
            // per bar; no per-element validation rules for them here)
            if ($this->isNavigationElement($elementType)) {
                continue;
            }

            $isSelfClosing = str_ends_with(trim($fullMatch), '/>');

            // Check for unknown element type. Child components are
            // resolved at runtime and take arbitrary attributes, so they
            // skip the element checks below.
            if (ComponentRegistry::has($elementType)) {
                continue;
            }

            if (! $this->isKnownElement($elementType)) {
                $result->error($filePath, $this->unknownElementMessage($tagName, $elementType), $line);

                continue;
            }

            // Check @change on unsupported element
            if (preg_match('/_change\s*=/', $attrs) || preg_match('/@change\s*=/', $attrs)) {
                if (! $this->elementHasMethod($elementType, 'onChange')) {
                    $result->error($filePath, "@change not supported on <native:{$tagName}>", $line);
                }
            }

            // Check @submit on unsupported element
            if (preg_match('/_submit\s*=/', $attrs) || preg_match('/@submit\s*=/', $attrs)) {
                if (! $this->elementHasMethod($elementType, 'onSubmit')) {
                    $result->error($filePath, "@submit not supported on <native:{$tagName}>", $line);
                }
            }

            // Warn: container element is self-closing (empty)
            if ($isSelfClosing && in_array($elementType, self::CONTAINERS)) {
                $result->warning($filePath, "Container <native:{$tagName} /> is self-closing (renders empty)", $line);
            }

            // Warn: self-closing button with nothing to show. A paired tag
            // takes its label from the slot, and an icon-only button is fine.
            if ($elementType === 'button' && $isSelfClosing && ! preg_match('/(?<![\w-])(label|icon)\s*=/', $attrs)) {
                $result->warning($filePath, "<native:button> without 'label' attribute", $line);
            }

            // Warn: image without src
            if ($elementType === 'image' && ! preg_match('/\bsrc\s*=/', $attrs)) {
                $result->warning($filePath, "<native:image> without 'src' attribute", $line);
            }
        }
    }

    /**
     * Whether the collector can build this type: one of its builtins, or a
     * type registered in the ElementRegistry by core or a registered plugin.
     */
    protected function isKnownElement(string $type): bool
    {
        return in_array($type, self::BUILTIN_TYPES, true)
            || array_key_exists($type, $this->elements());
    }

    protected function elementHasMethod(string $type, string $method): bool
    {
        $class = $this->elements()[$type] ?? null;

        return $class !== null && method_exists($class, $method);
    }

    protected function unknownElementMessage(string $tagName, string $type): string
    {
        $message = "Unknown native element type: '{$tagName}'";

        $package = $this->unregisteredPluginTypes()[$type] ?? null;

        if ($package !== null) {
            $message .= ". It comes from {$package}, which is installed but not registered."
                ." Run `php artisan native:plugin:register {$package}`";
        }

        return $message;
    }

    /** @return array<string, string> */
    protected function elements(): array
    {
        return $this->elements ??= ElementRegistry::all();
    }

    /** @return array<string, string> */
    protected function unregisteredPluginTypes(): array
    {
        if ($this->unregisteredPluginTypes !== null) {
            return $this->unregisteredPluginTypes;
        }

        $types = [];

        try {
            foreach (app(PluginRegistry::class)->unregistered() as $plugin) {
                foreach ($plugin->getComponents() as $component) {
                    if (isset($component['type'])) {
                        $types[$component['type']] ??= $plugin->name;
                    }
                }
            }
        } catch (\Throwable) {
            // Plugin discovery is a nicety here; never fail validation on it.
        }

        return $this->unregisteredPluginTypes = $types;
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

        // Match @tap="method", @longPress="method", @doubleTap="method", @change="method", @submit="method"
        // Also match the precompiled _press="method" form, and the @tap family
        // aliases (see NativeTagPrecompiler::TAP_ALIASES) — templates are
        // analyzed before precompilation, so the alias spellings must be
        // recognized here or their handlers go unvalidated.
        // Longer spellings precede their prefix (`pressDown`/`pressUp` before
        // `press`, `tapDown`/`tapUp` before `tap`) so they win the longer match.
        $pattern = '/[_@](pressDown|pressUp|press|longPress|doubleTap|change|submit|tapDown|tapUp|tap|longTap)\s*=\s*["\']([^"\']+)["\']/';

        if (preg_match_all($pattern, $stripped, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $type = $matches[1][$i][0];
                $method = $matches[2][$i][0];
                $line = $this->lineNumber($stripped, $match[1]);

                // Skip dynamic values (containing $ or {{ }})
                if (str_contains($method, '$') || str_contains($method, '{{')) {
                    continue;
                }

                // `delete(1)` calls delete() with a literal argument.
                $method = trim(preg_replace('/\(.*\)$/s', '', $method));

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
