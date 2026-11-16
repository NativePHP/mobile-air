<?php

namespace Native\Mobile\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Http\Controllers\DispatchEventFromAppController;

/**
 * Authenticates events arriving at `/_native/api/events` as genuinely posted by
 * the native shell, not forged by script running in the web view.
 *
 * The bridge delivers a device event by injecting a `fetch()` into the page, so
 * the request comes from the page's own origin — and because the `api` routes
 * carry no middleware, the endpoint otherwise cannot tell a native event from
 * one that a malicious script (an XSS, or a third-party script the app bundles)
 * fabricates. A forged `PaymentCompleted` or `Biometric\Completed(success: true)`
 * would fire the app's listeners and pending callbacks as if the device sent it.
 *
 * So the native side signs each event with the app key — held in the
 * Keychain/Keystore and never exposed to the page — over a per-event nonce, a
 * timestamp, and the exact request body. PHP verifies the signature with the
 * same key, rejects a stale or replayed one, and only then lets the event
 * through. The signature is bound to the body, so a script that reads a genuine
 * event's signature out of the page cannot pair it with a different payload, and
 * the one-time nonce makes replaying the exact event a no-op.
 *
 * Enforcement is gated on `NATIVEPHP_EVENTS_SIGNED`, which the native shell sets
 * at boot once it signs. An app that updates only the Composer package without
 * rebuilding keeps working unsigned until it reinstalls the native project; the
 * flag is process environment, so a page can neither set nor clear it.
 *
 * @see DispatchEventFromAppController
 */
class NativeEventSignature
{
    public const NONCE_HEADER = 'X-Native-Event-Nonce';

    public const TIMESTAMP_HEADER = 'X-Native-Event-Timestamp';

    public const SIGNATURE_HEADER = 'X-Native-Event-Signature';

    /** Seconds a signature stays valid, and how long its nonce is remembered. */
    protected const WINDOW = 30;

    /** What the signature is for, mixed in so one made for something else can't pass. */
    protected const PURPOSE = 'nativephp.native-event';

    /** Prefix for the one-time nonce record in the cache. */
    protected const NONCE_PREFIX = 'nativephp.native-event:';

    /**
     * Whether the native shell signs its events — and so PHP must verify them.
     * Until the shell is rebuilt with signing, this is off and events pass as
     * before. The flag is native-set process environment, out of a page's reach.
     */
    public static function enforced(): bool
    {
        return getenv('NATIVEPHP_EVENTS_SIGNED') === '1';
    }

    /**
     * True only when the request carries a valid, fresh, unused native
     * signature. False for anything else — missing headers, a bad timestamp, a
     * signature the app key did not make, or a nonce already used.
     */
    public static function verify(Request $request): bool
    {
        $nonce = (string) $request->header(self::NONCE_HEADER, '');
        $timestamp = (string) $request->header(self::TIMESTAMP_HEADER, '');
        $signature = (string) $request->header(self::SIGNATURE_HEADER, '');

        if ($nonce === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        // Reject a stale (or future) timestamp before any crypto, so a captured
        // signature cannot be replayed once its short window has passed.
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::WINDOW) {
            return false;
        }

        $key = static::key();

        if ($key === null) {
            // Fail closed: enforcement is on but there is no key to verify with.
            Log::warning('A native event reached the main lane but could not be verified — the app has no usable app key — and was dropped.');

            return false;
        }

        $expected = hash_hmac('sha256', static::message($nonce, $timestamp, $request->getContent()), $key);

        if (! hash_equals($expected, $signature)) {
            return false;
        }

        // One-time use within the window. This relies on a cache store that
        // persists across the per-request embedded PHP calls — on device that
        // is the file store (the same one NativeCallbacks relies on), where
        // Cache::add is atomic, so a replay loses the race and is refused here.
        // If an app runs a non-persistent store (e.g. array) this check weakens,
        // but the timestamp window above and the body-bound signature still
        // hold: a captured request can at most be replayed as the identical
        // genuine event for a few seconds, never forged into a different one.
        return Cache::add(self::NONCE_PREFIX.$nonce, 1, self::WINDOW);
    }

    /**
     * The exact bytes the signature covers. The native side signs the same
     * string; keep the two in lockstep.
     */
    protected static function message(string $nonce, string $timestamp, string $body): string
    {
        return self::PURPOSE."\n".$nonce."\n".$timestamp."\n".$body;
    }

    /** The current app key as the encrypter holds it (raw), or null when unusable. */
    protected static function key(): ?string
    {
        try {
            $key = app('encrypter')->getKey();
        } catch (\Throwable) {
            return null;
        }

        return is_string($key) && $key !== '' ? $key : null;
    }
}
