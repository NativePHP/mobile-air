<?php

namespace Native\Mobile\Support;

use Native\Mobile\Edge\NativeComponent;

/**
 * The one shared walk-the-backtrace-for-a-component loop. Used wherever
 * an API needs to know which component invoked it without receiving a
 * reference (fluent builders, callback registration).
 */
class CallStack
{
    public static function component(int $limit = 25): ?NativeComponent
    {
        if (! class_exists(NativeComponent::class)) {
            return null;
        }

        // +1: this helper adds a frame of its own — callers' limits mean
        // "frames of YOUR stack", not "minus one for the messenger".
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, $limit + 1) as $frame) {
            if (($frame['object'] ?? null) instanceof NativeComponent) {
                return $frame['object'];
            }
        }

        return null;
    }
}
