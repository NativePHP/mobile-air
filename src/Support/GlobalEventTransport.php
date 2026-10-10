<?php

namespace Native\Mobile\Support;

use Illuminate\Support\Facades\Log;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * Carries an event marked BroadcastsGlobally from the lane that fired it to
 * the main lane, where the native screens live. The event crosses as it
 * is: serialized, signed with the app key, and sent as base64 in JSON.
 *
 * It travels through the native event queue, in memory, as an event of
 * the device does. On a device, the `Event.Broadcast` call posts it
 * to the loop of a native screen when one is live, and the main
 * lane checks its signature there and then fires the event.
 *
 * JavaScript in a web view can make that call as well. So the call
 * takes a payload and no name, and native posts it under the one
 * name of this transport. The main lane unserializes only what
 * was signed with the app key, which every lane of an app
 * boots with, so nothing that a page posts through this
 * call is taken there without a signature of that key.
 *
 * Under Jump the subprocess of an async task adds the same
 * payload to the spool that the loop on the dev machine
 * drains, and that loop takes it as a native event.
 *
 * {@see BroadcastsGlobally} lists when an event stays in its lane instead, and
 * tells what `SerializesModels` does for an event that holds a model. What
 * counts as delivered, also under Jump, is said on {@see self::send()}.
 *
 * @internal
 */
class GlobalEventTransport
{
    /**
     * The name of the native event that carries an event of another lane
     * to the main lane. Native keeps a name that starts with `__` for
     * a signal of its own, which only the loop of a screen takes.
     *
     * The native function `Event.Broadcast` takes no name from a call
     * and posts under this same one, which it has written out for
     * itself. The two must match, or no event of another lane
     * arrives. The spool under Jump has its name from here.
     */
    public const EVENT_NAME = '__global_event';

    /**
     * The longest payload that is sent, in bytes of its JSON. The queue has
     * a limit for one event, which is not known here. The plan for it in
     * docs/event-channel-pointer-refactor.md has about 64 KB, and this
     * stays under that. The limit has to be checked on a device.
     *
     * What is counted is the JSON that PHP makes of the payload. That
     * is close to what native writes to the queue and not the same,
     * as native encodes the payload again and in a way of its own.
     *
     * The spool under Jump has no such limit, and the guard holds
     * there all the same. So a developer sees on the dev machine
     * what the same event would do on a device, and not later.
     */
    public const MAX_PAYLOAD_BYTES = 60000;

    /**
     * What a signature of this transport is for, signed in front of the
     * bytes of the event. Laravel signs other things with the app
     * key, so no signature made for one of those passes here,
     * and none that was made here passes for one of them.
     */
    protected const SIGNATURE_PURPOSE = 'nativephp.global-event:';

    /** @var array<string, true> What this interpreter has warned about, each of which it logs once. */
    protected static array $warned = [];

    // ── Sending side (another lane) ─────────────────

    /**
     * Send an event to the main lane, and answer whether it was delivered
     * to a live native screen there. On false the event has to stay in
     * this lane, and its listeners then run as for any other event.
     *
     * Under Jump the subprocess cannot tell whether a screen is live, so
     * its event counts as delivered once it is in the spool, where it
     * waits for the loop of whichever screen drains the spool next.
     */
    public function send(object $event): bool
    {
        try {
            $serialized = serialize($event);
        } catch (\Throwable $e) {
            static::warnOnce('serialize:'.$event::class, static::stays($event, 'it cannot be serialized ('.$e->getMessage().').'));

            return false;
        }

        $jump = AsyncTaskTransport::isJumpRunner();

        if (! $jump && ! function_exists('nativephp_call')) {
            return false;
        }

        $key = static::key();

        // The main lane takes no bytes without a signature that the app
        // key made, so an event that cannot be signed never crosses.
        if ($key === null) {
            static::warnOnce('key', 'An event marked BroadcastsGlobally stays in the lane that fired it and reaches no native screen: the app has no usable app key to sign it with, and no unsigned event crosses to the main lane. This is logged once.');

            return false;
        }

        $payload = ['data' => base64_encode($serialized), 'sig' => static::sign($serialized, $key)];

        $bytes = strlen((string) json_encode($payload));

        if ($bytes > static::MAX_PAYLOAD_BYTES) {
            static::warnOnce('size:'.$event::class, static::stays($event, 'it is too large to cross ('.$bytes.' bytes, where the native event queue takes '.static::MAX_PAYLOAD_BYTES.'). The SerializesModels trait keeps an event with a model small.'));

            return false;
        }

        // Under Jump the payload goes into the spool that the dev machine's
        // loop drains, and on a device native posts it to the loop of a
        // screen. An event that was not delivered leaves nothing.
        return $jump
            ? AsyncTaskTransport::spoolJumpEvent(static::EVENT_NAME, $payload)
            : $this->broadcast($payload);
    }

    /**
     * Ask native to post the payload of an event to the loop of a native
     * screen. It was delivered when native answers that it is in the
     * queue of a live session, and any other answer means no.
     *
     * The payload is all that is sent. Native takes no name from a
     * call and posts under EVENT_NAME alone, which it holds for
     * itself, so that no call can post any other event here.
     *
     * @param  array{data: string, sig: string}  $payload
     */
    protected function broadcast(array $payload): bool
    {
        $params = json_encode(['payload' => $payload]);

        if ($params === false) {
            return false;
        }

        $result = nativephp_call('Event.Broadcast', $params);

        if (! is_string($result) || $result === '') {
            return false;
        }

        $decoded = json_decode($result, true);

        return is_array($decoded)
            && ($decoded['success'] ?? false) === true
            && ($decoded['delivered'] ?? false) === true;
    }

    /**
     * The app key as Laravel's encrypter holds it, so that the `base64:`
     * form is read by the framework. It is the current key alone and
     * never a previous one. Null when the app has no usable key.
     */
    protected static function key(): ?string
    {
        try {
            $key = app('encrypter')->getKey();
        } catch (\Throwable) {
            return null;
        }

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** The signature of a serialized event: the HMAC with SHA-256 of its purpose and its bytes under the app key, in hex. */
    protected static function sign(string $serialized, string $key): string
    {
        return hash_hmac('sha256', static::SIGNATURE_PURPOSE.$serialized, $key);
    }

    /** The warning that an event of this class stays in the lane that fired it, and the reason why. */
    protected static function stays(object $event, string $reason): string
    {
        return 'The event ['.$event::class.'] is marked BroadcastsGlobally, but it stays in the lane that fired it and reaches no native screen: '.$reason.' This is logged once for each event class.';
    }

    /**
     * Log a warning the first time this interpreter has it to give, and
     * never again after that. A worker that fires an event in a loop
     * would fill the log with one line for every dispatch, and so
     * would a page that keeps posting what the main lane drops.
     */
    protected static function warnOnce(string $about, string $message): void
    {
        if (isset(static::$warned[$about])) {
            return;
        }

        static::$warned[$about] = true;

        Log::warning($message);
    }

    /** Forget what this interpreter has warned about. Tests need it, as they play several lanes in one process. */
    public static function reset(): void
    {
        static::$warned = [];
    }

    // ── Receiving side (the main lane) ──────────────

    /**
     * Take the event out of the payload that carries it, once the app
     * key has proven that a lane of this app wrote its bytes. Null
     * when it has not, or when the bytes hold no marked event.
     *
     * A signed payload that holds no such event is logged every time,
     * as the lane that sent it took its event as delivered and ran
     * no listener for it. What a page can post is logged once.
     */
    public static function receive(mixed $payload): ?BroadcastsGlobally
    {
        $data = is_array($payload) ? ($payload['data'] ?? null) : null;
        $sig = is_array($payload) ? ($payload['sig'] ?? null) : null;

        if (! is_string($data) || ! is_string($sig)) {
            return null;
        }

        $serialized = base64_decode($data, true);

        if ($serialized === false) {
            return null;
        }

        $key = static::key();

        // Without a key the main lane can check no signature, so it takes
        // no event at all. That is a fault in the setup of the app and
        // not in a signature, and the log says so in its own line.
        if ($key === null) {
            static::warnOnce('unchecked', 'An event marked BroadcastsGlobally reached the main lane and was dropped unread: the app has no usable app key there to check its signature with, and the main lane takes no event that it cannot check. This is logged once.');

            return null;
        }

        // JavaScript in a web view can call the bridge with bytes of its own
        // making, and unserialize() runs the code of whatever class they
        // name. So nothing reads them before the signature matches.
        if (! hash_equals(static::sign($serialized, $key), $sig)) {
            static::warnOnce('signature', 'An event marked BroadcastsGlobally reached the main lane with a signature that the app key did not make, and was dropped unread. This is logged once.');

            return null;
        }

        try {
            $event = @unserialize($serialized);
        } catch (\Throwable $e) {
            // The lane that fired it took this event as delivered, so its
            // listeners ran nowhere. That is so when a model which was
            // serialized by its key is gone from the database by now.
            Log::warning('An event marked BroadcastsGlobally reached the main lane from another lane, but it could not be unserialized there and was dropped: '.$e->getMessage());

            return null;
        }

        if ($event instanceof BroadcastsGlobally) {
            return $event;
        }

        // The signature matched, so a lane of this app wrote these bytes and
        // took its event as delivered. No listener ran for it anywhere,
        // which is a fault between the lanes and never page noise.
        Log::warning('A payload that a lane of this app signed reached the main lane, but it holds no event marked BroadcastsGlobally and was dropped: '.static::held($event, $serialized).' The lane that sent it took its event as delivered, so no listener ran for it.');

        return null;
    }

    /**
     * Say what a signed payload held, when that is no marked event. A
     * class is named where there is one, and so is the class that
     * the main lane does not have, for which PHP makes a stub.
     */
    protected static function held(mixed $value, string $serialized): string
    {
        return match (true) {
            $value instanceof \__PHP_Incomplete_Class => 'its class ['.(((array) $value)['__PHP_Incomplete_Class_Name'] ?? 'unknown').'] does not exist on the main lane.',
            is_object($value) => 'its class ['.$value::class.'] is not marked BroadcastsGlobally.',
            $value === false && $serialized !== serialize(false) => 'its bytes are no serialized value.',
            default => 'it holds a value of type '.get_debug_type($value).' and no event.',
        };
    }
}
