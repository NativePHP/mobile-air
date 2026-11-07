<?php

/**
 * Records what the streaming collector writes to the C flat buffer.
 *
 * On a device these are extension functions. The collector calls them
 * unqualified from inside `Native\Mobile\Edge`, and PHP resolves an
 * unqualified call in the current namespace first, so defining them here
 * catches every call without touching the global functions a real
 * runtime would provide.
 *
 * Require it from a test, then read and reset `$GLOBALS['nphp_stream']`.
 */

namespace Native\Mobile\Edge;

if (! function_exists(__NAMESPACE__.'\\nphp_node_open')) {
    function nphp_node_open(string $type, ?array $layout = null, ?array $style = null, ?array $props = null, int $onPress = 0, int $onLongPress = 0, int $id = 0): void
    {
        $GLOBALS['nphp_stream'][] = ['open', $type, $layout, $style, $props, $id];
    }

    function nphp_node_leaf(string $type, ?array $layout = null, ?array $style = null, ?array $props = null, int $onPress = 0, int $onLongPress = 0, int $id = 0): void
    {
        $GLOBALS['nphp_stream'][] = ['leaf', $type, $layout, $style, $props, $id];
    }

    function nphp_node_close(): void
    {
        $GLOBALS['nphp_stream'][] = ['close'];
    }
}

if (! function_exists(__NAMESPACE__.'\\nphp_frame_begin')) {
    function nphp_frame_begin(): void
    {
        $GLOBALS['nphp_stream'][] = ['frame_begin'];
    }

    function nphp_frame_end(): void
    {
        $GLOBALS['nphp_stream'][] = ['frame_end'];
    }
}
