<?php

namespace Native\Mobile\Edge\Web\Bridge;

use Native\Mobile\Testing\FakeBridge;

/**
 * The web target's bridge: gives native API calls a sensible behavior in
 * a plain browser instead of FakeBridge's answer-nothing stand-in.
 *
 * Extends FakeBridge so the `nativephp_*` polyfills' consult path, the
 * element-region no-ops, call recording, and `respondTo()` scripting all
 * carry over — only `call()` changes. Precedence per call:
 *
 *   1. Scripted response (`respondTo()`) — the `?_platform=mobile`
 *      preview scripts Device.GetInfo this way, and tests stay in
 *      control when they script methods.
 *   2. PHP driver from WebDriverRegistry — runs server-side, returns the
 *      native envelope (SecureStorage via encrypted session, Device info
 *      from the user agent, …).
 *   3. ClientEffect driver — queues `{method, params}` into the
 *      per-request effects buffer; WebScreenRunner drains it into the
 *      response (`effects: [...]`) for edge-web.js to perform.
 *   4. Nothing registered — recorded as unhandled, answers null (and
 *      `nativephp_can()` reports false for the method on web).
 *
 * Async result contract (geolocation, dialog buttons, camera-style
 * flows): the queued effect carries the correlation `id` and the fully
 * qualified `event` class name from the Pending* builder. The client
 * performs the effect, then reports the outcome by dispatching that
 * event back to the server on a subsequent update — mirroring how native
 * async APIs answer via the event runloop rather than the call's return
 * value.
 *
 * WebScreenRunner::boot() enables one per request; the instance is also
 * aliased under FakeBridge::class so the global polyfills find it.
 */
class WebBridge extends FakeBridge
{
    /**
     * Client effects queued this request, oldest first.
     * Each entry: ['method' => string, 'params' => array|\stdClass].
     */
    protected array $effects = [];

    /**
     * Bind a WebBridge for this request (idempotent) and alias it under
     * FakeBridge::class — the nativephp_* polyfills resolve by that exact
     * class name.
     */
    public static function enable(): static
    {
        $bridge = parent::enable();

        app()->instance(FakeBridge::class, $bridge);

        return $bridge;
    }

    /**
     * The WebBridge driving this request, or null. Detected through the
     * FakeBridge alias — always a concrete instance binding — rather
     * than the WebBridge::class container key, which may hold the
     * service provider's lazy bind closure (resolving THAT from here
     * would recurse: the closure consults current()).
     */
    public static function current(): ?static
    {
        // Non-forwarding call on purpose: static:: must resolve to
        // FakeBridge inside, so the FakeBridge::class binding is checked.
        $bridge = FakeBridge::current();

        return $bridge instanceof static ? $bridge : null;
    }

    public function call(string $method, string $params = '{}'): ?string
    {
        // Scripted responses win — parent records the call and answers.
        if (array_key_exists($method, $this->responses)) {
            return parent::call($method, $params);
        }

        $driver = WebDriverRegistry::driverFor($method);

        if ($driver === null) {
            // Unhandled on web: record like FakeBridge, answer null.
            return parent::call($method, $params);
        }

        $decoded = json_decode($params, true);
        $decoded = is_array($decoded) ? $decoded : [];

        $this->calls[] = ['method' => $method, 'params' => $decoded];

        if ($driver instanceof ClientEffect) {
            $this->queueEffect($method, $driver->params($decoded));

            return null;
        }

        $result = $driver($decoded, $this);

        return is_array($result) ? json_encode($result) : $result;
    }

    /**
     * Whether a bridge method does anything on web — a scripted response,
     * a PHP driver, or a client effect. Backs nativephp_can().
     */
    public function can(string $method): bool
    {
        return array_key_exists($method, $this->responses)
            || WebDriverRegistry::knows($method);
    }

    // ── Effects buffer ──────────────────────────────

    /**
     * Queue an effect for the client runtime. Empty params are encoded
     * as an object so the wire shape is always `{method, params: {…}}`.
     */
    public function queueEffect(string $method, array $params = []): void
    {
        $this->effects[] = [
            'method' => $method,
            'params' => $params === [] ? new \stdClass : $params,
        ];
    }

    /** Return the queued effects and clear the buffer. */
    public function drainEffects(): array
    {
        $drained = $this->effects;
        $this->effects = [];

        return $drained;
    }
}
