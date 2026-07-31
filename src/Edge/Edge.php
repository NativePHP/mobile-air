<?php

namespace Native\Mobile\Edge;

class Edge
{
    protected static array $components = [];

    protected static array $contextStack = [];

    public static function add(string $type, array $data)
    {
        $component = [
            'type' => $type,
            'data' => $data,
        ];

        // If we have a parent context, add as child
        if (! empty(self::$contextStack)) {
            $parentContext = end(self::$contextStack);

            // Navigate to the parent component, then access its data.children
            $parent = &self::navigateToComponent($parentContext);

            // Add as child to the parent's children array
            if (! isset($parent['data']['children'])) {
                $parent['data']['children'] = [];
            }
            $parent['data']['children'][] = $component;
        } else {
            // Otherwise add as root component
            self::$components[] = $component;
        }
    }

    public static function startContext(): int
    {
        $placeholder = [
            'type' => '',
            'data' => ['children' => []],
        ];

        // If we have a parent context, add as child
        if (! empty(self::$contextStack)) {
            $parentContext = end(self::$contextStack);

            // Navigate to the parent component's children array
            $target = &self::navigateToComponent($parentContext);

            // Ensure children array exists
            if (! isset($target['data']['children'])) {
                $target['data']['children'] = [];
            }

            // Add placeholder as child
            $childIndex = count($target['data']['children']);
            $target['data']['children'][] = $placeholder;

            // Build path for this context (parent path + 'data' + 'children' + child index)
            $newContext = array_merge($parentContext, ['data', 'children', $childIndex]);
            self::$contextStack[] = $newContext;

            $returnIndex = count(self::$contextStack) - 1;

            return $returnIndex;
        } else {
            // Add as root component
            $index = count(self::$components);
            self::$components[$index] = $placeholder;
            self::$contextStack[] = [$index];

            $returnIndex = count(self::$contextStack) - 1;

            return $returnIndex;
        }
    }

    /**
     * Navigate to a component using a path array
     */
    private static function &navigateToComponent(array $path)
    {
        $target = &self::$components;

        foreach ($path as $key) {
            $target = &$target[$key];
        }

        return $target;
    }

    public static function endContext(int $stackIndex, string $type, array $data): void
    {
        if (! isset(self::$contextStack[$stackIndex])) {
            return;
        }

        $context = self::$contextStack[$stackIndex];

        $target = &self::navigateToComponent($context);

        // Update the placeholder with actual data
        $target['type'] = $type;
        $target['data'] = array_merge($data, [
            'children' => $target['data']['children'] ?? [],
        ]);

        // Pop the context stack
        array_pop(self::$contextStack);
    }

    public static function all(): array
    {
        return self::$components;
    }

    public static function reset(): void
    {
        self::$components = [];
        self::$contextStack = [];
    }

    public static function set(): void
    {
        $nativeUIData = self::all();

        if (empty($nativeUIData)) {
            return;
        }

        if (function_exists('nativephp_call')) {
            nativephp_call('Edge.Set', json_encode(['components' => $nativeUIData]));
        }

        self::reset();
    }

    public static function clear(): void
    {
        if (function_exists('nativephp_call')) {
            nativephp_call('Edge.Set', json_encode(['components' => []]));
        }
    }
}
