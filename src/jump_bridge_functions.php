<?php

/**
 * Fallback implementations of nativephp_call() and nativephp_can()
 * for Jump hybrid mode (dev machine execution).
 *
 * These functions are only loaded when the C extension versions
 * don't exist (i.e., when running on the developer's machine,
 * not on the mobile device).
 */
if (! function_exists('nativephp_call')) {
    /**
     * Call a native bridge function on the connected device.
     *
     * In Jump hybrid mode, this sends the call over TCP to the
     * WebSocket bridge server, which relays it to the device.
     * The device executes the native function and returns the result.
     *
     * @param  string  $method  The bridge function name (e.g., 'Camera.GetPhoto')
     * @param  string  $params  JSON-encoded parameters
     * @return string|null JSON-encoded result from the device
     */
    function nativephp_call(string $method, string $params = '{}'): ?string
    {
        return \Native\Mobile\JumpBridge::instance()->call($method, $params);
    }
}

if (! function_exists('nativephp_can')) {
    /**
     * Check if a native bridge function is available.
     *
     * In Jump hybrid mode, we assume all functions are available
     * on the connected device.
     */
    function nativephp_can(string $method): bool
    {
        return true;
    }
}
