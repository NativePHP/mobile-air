<?php

use Native\Mobile\JumpBridge;
use Native\Mobile\Testing\FakeBridge;

/**
 * Fallback implementations of nativephp_call() and nativephp_can()
 * for Jump hybrid mode (dev machine execution).
 *
 * These functions are only loaded when the C extension versions
 * don't exist (i.e., when running on the developer's machine,
 * not on the mobile device).
 *
 * Each function consults \Native\Mobile\Testing\FakeBridge first: when a
 * test has bound one (via Native::test() / FakeBridge::enable()), bridge
 * traffic is captured in-process instead of hitting the Jump TCP relay.
 * Outside tests current() is null and behavior is unchanged.
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
        if ($fake = FakeBridge::current()) {
            return $fake->call($method, $params);
        }

        return JumpBridge::instance()->call($method, $params);
    }
}

if (! function_exists('nativephp_can')) {
    /**
     * Check if a native bridge function is available.
     *
     * Web target: when a WebBridge is driving (plain-browser render via
     * WebScreenRunner), only methods with a registered web driver — PHP
     * or client effect — are available, so app code can feature-gate
     * honestly. Device behavior is untouched (this file never loads
     * there), and Jump hybrid mode / plain FakeBridge tests keep
     * assuming everything is available.
     */
    function nativephp_can(string $method): bool
    {
        $bridge = FakeBridge::current();

        if ($bridge instanceof \Native\Mobile\Edge\Web\Bridge\WebBridge) {
            return $bridge->can($method);
        }

        return true;
    }
}

if (! function_exists('nativephp_element_init')) {
    function nativephp_element_init(): void
    {
        if ($fake = FakeBridge::current()) {
            $fake->elementInit();

            return;
        }

        JumpBridge::instance()->call('Element.Init');
    }
}

if (! function_exists('nativephp_element_publish')) {
    function nativephp_element_publish(array $tree): void
    {
        if ($fake = FakeBridge::current()) {
            $fake->elementPublish($tree);

            return;
        }

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
        if ($fake = FakeBridge::current()) {
            return $fake->elementWaitEvent($timeoutMs);
        }

        static $consecutiveErrors = 0;
        static $firstErrorAt = 0.0;

        $result = JumpBridge::instance()->call('Element.WaitEvent', json_encode(['timeout' => $timeoutMs]));

        if ($result === null) {
            // TCP timeout — not an error, just no interaction yet. Retry.
            return null;
        }

        $decoded = json_decode($result, true);

        // Two DIFFERENT rejection shapes reach us and both must count as errors:
        //   JumpBridge::call() itself  → {"status":"error","code":"NO_DEVICE",…}
        //   websocket-server.php       → {"id":…,"error":"No device connected"}
        // Only the first was ever matched here, so a device that vanished mid
        // session fell through to the success path below, reset the counter, and
        // got returned to the runloop as a bogus type-less "event". The give-up
        // branch could therefore never fire: the orphaned runloop spun forever,
        // pinning its artisan-serve worker. On macOS php -S forks a pool so that
        // just leaked one worker at a time; on Windows `forking is not supported`
        // means there is exactly ONE worker, so the first orphan deadlocked the
        // whole dev server — no later scan could ever be served. A real event
        // always carries `type`, so require its absence before treating an
        // `error` key as a failure.
        $isBridgeError = ! is_array($decoded)
            || (isset($decoded['status']) && $decoded['status'] === 'error')
            || (isset($decoded['error']) && ! isset($decoded['type']));

        if ($isBridgeError) {
            // Bridge error — almost always the device WebSocket briefly
            // dropped (app backgrounded, Wi-Fi blip, dev-server reconnect).
            // The iOS / Android client auto-reconnects within ~2s, so this
            // is a TRANSIENT idle tick, NOT a user "back" press.
            //
            // Emitting a system-back (type 8) here is what was ejecting the
            // user clean out of the app: at the root stack a back press pops
            // the only component and ends the session, so the device snaps
            // back to Jump's home screen the instant the socket hiccups.
            // Ride the outage out instead — keep the component alive and
            // resume publishing once the socket is back. Only give up after
            // a long *continuous* outage so a genuinely-gone device doesn't
            // pin an artisan-serve worker forever.
            $consecutiveErrors++;
            $firstErrorAt = $firstErrorAt ?: microtime(true);
            usleep(200_000); // 200ms — throttle the spin while disconnected
            // The client auto-reconnects within ~2s, so ride out short outages.
            // But the runloop holds the GET / request open on a single-threaded
            // `php -S` worker the whole time it spins here — so if the device is
            // genuinely gone we must give up and let the runloop return, freeing
            // the worker. Otherwise a dropped session pins the server and every
            // later scan / re-scan hangs (the "restart jump, same thing again"
            // loop). 8s is a comfortable margin over the 2s reconnect.
            //
            // Measured in WALL TIME, not tick count: websocket-server.php
            // deliberately delays each rejection ~1s to stop an orphaned runloop
            // saturating its event loop, so a 40-tick budget was really ~48s on
            // that path while the immediate NO_DEVICE path (no server round trip)
            // hit it in ~8s. Timing the outage makes the budget mean the same
            // thing regardless of which rejection path we're on.
            if (microtime(true) - $firstErrorAt >= 8.0) {
                $consecutiveErrors = 0;
                $firstErrorAt = 0.0;

                return ['type' => 8, 'callback_id' => 0, 'node_id' => 0];
            }

            return null;
        }

        // Reset on success
        $consecutiveErrors = 0;
        $firstErrorAt = 0.0;

        // Hot reload (EVENT_HOT_RELOAD = 15): PASS IT THROUGH to the runloop
        // instead of handling in-place. A long-running runloop can't re-render
        // a changed view in place — Laravel's CompilerEngine marks each view
        // "compiled" for the life of the request (so deleting the compiled file
        // doesn't force a recompile), and edited PHP classes are already loaded.
        // So the runloop's HOT_RELOAD handler writes `.hot_restart` (preserving
        // the nav stack) and exits via a RESTART intent; the device's relay
        // re-executes on the resulting Element.Shutdown, and the FRESH request
        // restores the stack silently — picking up Blade AND PHP changes with no
        // slide. Debounced only to swallow a burst of duplicate saves.
        if (($decoded['type'] ?? -1) === 15) { // EVENT_HOT_RELOAD
            static $lastHotReload = 0;
            $now = time();
            if ($now - $lastHotReload < 2) {
                return null; // swallow rapid-fire duplicate reloads
            }
            $lastHotReload = $now;
            // fall through → return the event so the runloop restarts.
        }

        return $decoded;
    }
}

if (! function_exists('nativephp_runtime_flags')) {
    /**
     * Runtime feature-flag bitmask exposed by the C extension on-device.
     *
     * In Jump hybrid mode there is no C extension, so we report 0:
     * every flag clear. Most importantly NPHP_FLAG_SUBTREE_MEMO (0x02)
     * stays off, so NativeComponent emits FULL frames every publish —
     * wire bytes identical to pre-Phase-2. REUSE markers depend on a
     * native reader maintaining a previousTree index keyed by node id;
     * the Jump bridge serializes the whole tree as JSON over TCP, so we
     * never want to emit REUSE here.
     */
    function nativephp_runtime_flags(): int
    {
        if ($fake = FakeBridge::current()) {
            return $fake->runtimeFlags();
        }

        return 0;
    }
}

if (! function_exists('nativephp_force_full_frame_epoch')) {
    /**
     * The region's force_full_frame epoch counter, bumped by the C
     * extension whenever the native previousTree is swapped out.
     *
     * In Jump mode subtree-memo is disabled (see nativephp_runtime_flags),
     * so this is never consulted on a hot path. Return a constant 0.
     */
    function nativephp_force_full_frame_epoch(): int
    {
        return 0;
    }
}

if (! function_exists('nativephp_element_reset')) {
    function nativephp_element_reset(): void
    {
        if ($fake = FakeBridge::current()) {
            $fake->elementReset();

            return;
        }

        JumpBridge::instance()->call('Element.Reset');
    }
}

if (! function_exists('nativephp_element_shutdown')) {
    function nativephp_element_shutdown(): void
    {
        if ($fake = FakeBridge::current()) {
            $fake->elementShutdown();

            return;
        }

        // Clean up a STALE .hot_restart so a normal next scan starts fresh —
        // but NOT a fresh one. A hot-reload exit writes .hot_restart and then
        // runs straight through this shutdown; the file must survive so the
        // re-executed request can restore the nav stack. Only a stale leftover
        // (old `ts`, e.g. from a crash or a real session that ended) is removed.
        $restartPath = storage_path('framework/.hot_restart');
        if (is_file($restartPath)) {
            $raw = @file_get_contents($restartPath);
            $data = $raw ? @json_decode($raw, true) : null;
            $age = time() - (int) ($data['ts'] ?? 0);
            if ($age > 5) {
                @unlink($restartPath);
            }
        }

        JumpBridge::instance()->call('Element.Shutdown');
    }
}
