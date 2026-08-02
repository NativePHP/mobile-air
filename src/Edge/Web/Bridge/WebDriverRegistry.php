<?php

namespace Native\Mobile\Edge\Web\Bridge;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Per-method drivers for native bridge calls on the WEB target.
 *
 * When a screen runs in a plain browser (WebScreenRunner), every
 * `nativephp_call()` lands on the WebBridge, which consults this registry:
 *
 *   - a Closure driver runs SERVER-SIDE and returns the same envelope the
 *     native side would (array → JSON-encoded, string passed through,
 *     null = no answer). Signature:
 *
 *         function (array $params, WebBridge $bridge): array|string|null
 *
 *     Drivers may also queue a client effect via `$bridge->queueEffect()`
 *     and still return an envelope (e.g. Browser.Open queues an open-url
 *     effect for the browser AND answers `{success: true}`).
 *
 *   - a ClientEffect driver queues `{method, params}` into the response's
 *     `effects` array for edge-web.js to perform in the browser; the PHP
 *     call answers null (all default client-effect methods are
 *     fire-and-forget or event-based on the native side too).
 *
 *   - an unregistered method records as unhandled and answers null, and
 *     `nativephp_can($method)` reports FALSE for it on web — so app code
 *     can feature-gate.
 *
 * App-level overrides — call from any service provider (register() wins
 * over the built-in defaults regardless of ordering):
 *
 *     use Native\Mobile\Edge\Web\Bridge\ClientEffect;
 *     use Native\Mobile\Edge\Web\Bridge\WebDriverRegistry;
 *
 *     // PHP driver: answer server-side with the native envelope shape.
 *     WebDriverRegistry::register('Network.Status', fn (array $params) => [
 *         'status' => 'wifi',
 *     ]);
 *
 *     // Client effect: hand the call to the browser runtime.
 *     WebDriverRegistry::register('Camera.GetPhoto', ClientEffect::make());
 */
class WebDriverRegistry
{
    /** Session key holding the encrypted SecureStorage map. */
    protected const SECURE_SESSION_KEY = '_edge_secure';

    /** @var array<string, \Closure|ClientEffect> method → driver */
    protected static array $drivers = [];

    protected static bool $defaultsLoaded = false;

    public static function register(string $method, \Closure|ClientEffect $driver): void
    {
        // Load defaults first so a late default merge can never clobber
        // an app registration — register() always wins.
        static::ensureDefaults();

        static::$drivers[$method] = $driver;
    }

    public static function driverFor(string $method): \Closure|ClientEffect|null
    {
        static::ensureDefaults();

        return static::$drivers[$method] ?? null;
    }

    public static function knows(string $method): bool
    {
        return static::driverFor($method) !== null;
    }

    /** @return string[] every method with a web driver (PHP or client). */
    public static function methods(): array
    {
        static::ensureDefaults();

        return array_keys(static::$drivers);
    }

    /** Forget everything, defaults included (tests). */
    public static function flush(): void
    {
        static::$drivers = [];
        static::$defaultsLoaded = false;
    }

    // ── Defaults ────────────────────────────────────

    protected static function ensureDefaults(): void
    {
        if (static::$defaultsLoaded) {
            return;
        }

        static::$defaultsLoaded = true;

        foreach (static::defaults() as $method => $driver) {
            static::$drivers[$method] ??= $driver;
        }
    }

    /** @return array<string, \Closure|ClientEffect> */
    protected static function defaults(): array
    {
        return [
            // ── PHP drivers (answered server-side) ──────────────

            'Device.GetInfo' => fn (array $params) => [
                'info' => json_encode([
                    'platform' => 'web',
                    'model' => static::userAgentSummary(),
                ]),
            ],

            // Stable per-session pseudo device id.
            'Device.GetId' => function (array $params) {
                try {
                    $id = session()->get('_edge_device_id');

                    if (! is_string($id) || $id === '') {
                        $id = (string) Str::uuid();
                        session()->put('_edge_device_id', $id);
                    }

                    return ['id' => $id];
                } catch (\Throwable) {
                    return ['id' => null];
                }
            },

            // Hybrid: the browser vibrates (where supported) AND the PHP
            // caller sees the native success envelope.
            'Device.Vibrate' => function (array $params, WebBridge $bridge) {
                $bridge->queueEffect('Device.Vibrate', $params);

                return ['success' => true];
            },

            // Encrypted session storage standing in for keychain/keystore.
            // Values are Crypt-encrypted at rest inside the session payload.
            'SecureStorage.Set' => function (array $params) {
                $key = (string) ($params['key'] ?? '');

                if ($key === '') {
                    return ['success' => false];
                }

                try {
                    $store = (array) session()->get(static::SECURE_SESSION_KEY, []);
                    $value = $params['value'] ?? null;

                    if ($value === null) {
                        unset($store[$key]);
                    } else {
                        $store[$key] = Crypt::encryptString((string) $value);
                    }

                    session()->put(static::SECURE_SESSION_KEY, $store);

                    return ['success' => true];
                } catch (\Throwable) {
                    return ['success' => false];
                }
            },

            'SecureStorage.Get' => function (array $params) {
                $key = (string) ($params['key'] ?? '');

                try {
                    $store = (array) session()->get(static::SECURE_SESSION_KEY, []);
                    $sealed = $store[$key] ?? null;

                    return ['value' => is_string($sealed) ? Crypt::decryptString($sealed) : null];
                } catch (\Throwable) {
                    return ['value' => null];
                }
            },

            'SecureStorage.Delete' => function (array $params) {
                $key = (string) ($params['key'] ?? '');

                if ($key === '') {
                    return ['success' => false];
                }

                try {
                    $store = (array) session()->get(static::SECURE_SESSION_KEY, []);
                    unset($store[$key]);
                    session()->put(static::SECURE_SESSION_KEY, $store);

                    return ['success' => true];
                } catch (\Throwable) {
                    return ['success' => false];
                }
            },

            // Hybrid: queue an open-url effect, answer the success envelope
            // the Browser facade checks. In-app browser and auth session
            // both degrade to a plain open on web.
            'Browser.Open' => function (array $params, WebBridge $bridge) {
                $bridge->queueEffect('Browser.Open', $params);

                return ['success' => true];
            },
            'Browser.OpenInApp' => function (array $params, WebBridge $bridge) {
                $bridge->queueEffect('Browser.OpenInApp', $params);

                return ['success' => true];
            },
            'Browser.OpenAuth' => function (array $params, WebBridge $bridge) {
                $bridge->queueEffect('Browser.OpenAuth', $params);

                return ['success' => true];
            },

            // ── Client effects (performed by edge-web.js) ───────
            // Params pass through unmapped: Dialog.Alert carries
            // {title, message, buttons, id, event}; Geolocation.* carry
            // {id, event, fineAccuracy?} — the client answers those by
            // dispatching the named event back (see WebBridge docblock).

            'Dialog.Alert' => ClientEffect::make(),
            'Dialog.Toast' => ClientEffect::make(),
            'Share.Url' => ClientEffect::make(),
            'Geolocation.GetCurrentPosition' => ClientEffect::make(),
            'Geolocation.CheckPermissions' => ClientEffect::make(),
            'Geolocation.RequestPermissions' => ClientEffect::make(),
        ];
    }

    /** Short human-readable browser/OS summary for Device.GetInfo. */
    protected static function userAgentSummary(): string
    {
        try {
            $ua = (string) request()->userAgent();
        } catch (\Throwable) {
            $ua = '';
        }

        if ($ua === '') {
            return 'Unknown Browser';
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $os = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };

        return $os === '' ? $browser : "{$browser} on {$os}";
    }
}
