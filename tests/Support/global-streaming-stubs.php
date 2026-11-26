<?php

/**
 * Global stand-ins for the nphp_node_* extension functions, which aren't
 * loaded under test. Every test that streams through the global functions
 * requires this one file, so whichever test file loads first, they all get
 * the same recorder.
 *
 * Each call lands in `$GLOBALS['nphpStreamed']` as [call, id, type, props].
 */
if (! function_exists('nphp_node_open')) {
    function nphp_node_open(string $type, ?array $layout, ?array $style, ?array $props, int $onPress, int $onLongPress, int $id = 0): void
    {
        $GLOBALS['nphpStreamed'][] = ['open', $id, $type, $props ?? []];
    }

    function nphp_node_leaf(string $type, ?array $layout, ?array $style, ?array $props, int $onPress, int $onLongPress, int $id = 0): void
    {
        $GLOBALS['nphpStreamed'][] = ['leaf', $id, $type, $props ?? []];
    }

    function nphp_node_close(): void
    {
        $GLOBALS['nphpStreamed'][] = ['close', 0];
    }
}
