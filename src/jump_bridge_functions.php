<?php

use Native\Mobile\JumpBridge;

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
        return JumpBridge::instance()->call($method, $params);
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

if (! function_exists('nativephp_element_init')) {
    function nativephp_element_init(): void
    {
        JumpBridge::instance()->call('Element.Init');
    }
}

if (! function_exists('nativephp_element_publish')) {
    function nativephp_element_publish(array $tree): void
    {
        $json = json_encode($tree);
        $hash = substr(md5($json), 0, 8);
        @file_put_contents(
            storage_path('logs/jump-publish.log'),
            date('[H:i:s] ')."Publish: hash={$hash} size=".strlen($json)."\n",
            FILE_APPEND
        );
        JumpBridge::instance()->call('Element.Publish', $json);
    }
}

if (! function_exists('nativephp_element_wait_event')) {
    function nativephp_element_wait_event(int $timeoutMs): ?array
    {
        static $consecutiveErrors = 0;

        $result = JumpBridge::instance()->call('Element.WaitEvent', json_encode(['timeout' => $timeoutMs]));

        if ($result === null) {
            // TCP timeout — not an error, just no interaction yet. Retry.
            return null;
        }

        $decoded = json_decode($result, true);

        if (! is_array($decoded) || isset($decoded['status']) && $decoded['status'] === 'error') {
            // Actual error (device disconnected, bridge failed)
            $consecutiveErrors++;
            if ($consecutiveErrors >= 2) {
                return ['type' => 8, 'callback_id' => 0, 'node_id' => 0];
            }

            return null;
        }

        // Reset on success
        $consecutiveErrors = 0;

        // In Jump mode, handle hot reload in-place: flush compiled views
        // and return null so the component re-renders without stopping.
        if (($decoded['type'] ?? -1) === 15) { // EVENT_HOT_RELOAD
            static $lastHotReload = 0;
            $now = time();

            // Debounce: ignore rapid-fire hot reload events (within 2 seconds)
            if ($now - $lastHotReload < 2) {
                return null;
            }
            $lastHotReload = $now;

            $compiledDir = storage_path('framework/views');
            if (is_dir($compiledDir)) {
                foreach (glob("{$compiledDir}/*.php") as $file) {
                    @unlink($file);
                }
            }
            clearstatcache(true);

            return null; // loop continues → render() picks up fresh views
        }

        return $decoded;
    }
}

if (! function_exists('nativephp_element_reset')) {
    function nativephp_element_reset(): void
    {
        JumpBridge::instance()->call('Element.Reset');
    }
}

if (! function_exists('nativephp_element_shutdown')) {
    function nativephp_element_shutdown(): void
    {
        // In Jump mode, clean up .hot_restart so the next WebView request
        // starts a fresh component instead of returning 204.
        // On-device, Kotlin/Swift handles this cleanup — in Jump, we do it here.
        @unlink(storage_path('framework/.hot_restart'));

        JumpBridge::instance()->call('Element.Shutdown');
    }
}
