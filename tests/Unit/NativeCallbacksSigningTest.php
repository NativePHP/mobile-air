<?php

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Support\NativeCallbacks;

/**
 * What anything able to write to the callback cache could leave there: an
 * object whose magic method runs the moment unserialize() reads it. The
 * durable copy is read back in the result event, which JavaScript in a web
 * view can reach, so the signature check has to come before unserialize().
 */
class PlantedCallbackGadget
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        static::$woken = true;
    }
}

beforeEach(function () {
    // Every lane of an app boots with the same app key; the durable copy of a
    // callback is signed with it and verified before it is read back.
    config(['app.key' => 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC'))]);
    app()->forgetInstance('encrypter');

    // The in-memory tier and the warn-once state are process statics.
    NativeCallbacks::flush();
    PlantedCallbackGadget::$woken = false;
});

afterEach(function () {
    NativeCallbacks::flush();
});

/** The cache key NativeCallbacks keeps the durable copy of a callback under. */
function nativeCallbackKey(string $id = 'capture-1', string $event = 'photo-taken'): string
{
    return 'native_cb:'.$id.':'.$event;
}

/** Boot the application with a given app key (or none), as a lane does when it starts. */
function bootCallbacksWithAppKey(?string $key): void
{
    config(['app.key' => $key]);
    app()->forgetInstance('encrypter');
}

it('signs the durable copy and resolves it after the in-memory copy is gone', function () {
    NativeCallbacks::register('capture-1', 'photo-taken', static fn (string $value) => 'resolved:'.$value);

    // The durable copy is a signed envelope — base64 bytes and an HMAC — not
    // the bare serialized string it used to be.
    $envelope = Cache::get(nativeCallbackKey());

    expect($envelope)->toBeArray()
        ->and($envelope)->toHaveKeys(['data', 'sig'])
        ->and($envelope['data'])->toMatch('/^[A-Za-z0-9+\/]+={0,2}$/')
        ->and($envelope['sig'])->toBeString()->not->toBeEmpty();

    // Drop tier 1 so resolve() has to read and verify the durable copy.
    NativeCallbacks::flush();

    $callback = NativeCallbacks::resolve('capture-1', 'photo-taken');

    expect($callback)->toBeInstanceOf(Closure::class)
        ->and($callback('x'))->toBe('resolved:x')
        // resolve() consumed the durable copy, so a second read finds nothing.
        ->and(NativeCallbacks::resolve('capture-1', 'photo-taken'))->toBeNull();
});

it('drops a durable copy whose bytes were changed, and never unserializes them', function () {
    Log::spy();

    // A genuine signed envelope, then its data swapped for a gadget whose
    // __wakeup fires the instant unserialize() reads it.
    NativeCallbacks::register('capture-1', 'photo-taken', static fn () => 'ok');

    $envelope = Cache::get(nativeCallbackKey());
    $envelope['data'] = base64_encode(serialize(new PlantedCallbackGadget));
    Cache::put(nativeCallbackKey(), $envelope, now()->addMinutes(5));

    NativeCallbacks::flush();

    expect(NativeCallbacks::resolve('capture-1', 'photo-taken'))->toBeNull()
        ->and(PlantedCallbackGadget::$woken)->toBeFalse();

    Log::shouldHaveReceived('warning');
});

it('drops a durable copy whose signature was changed', function () {
    NativeCallbacks::register('capture-1', 'photo-taken', static fn () => 'ok');

    $envelope = Cache::get(nativeCallbackKey());
    $envelope['sig'] = substr_replace($envelope['sig'], $envelope['sig'][0] === 'f' ? '0' : 'f', 0, 1);
    Cache::put(nativeCallbackKey(), $envelope, now()->addMinutes(5));

    NativeCallbacks::flush();

    expect(NativeCallbacks::resolve('capture-1', 'photo-taken'))->toBeNull();
});

it('drops a legacy unsigned copy, and never unserializes it', function () {
    Log::spy();

    // The shape before durable copies were signed: a bare serialized string.
    Cache::put(nativeCallbackKey(), serialize(new PlantedCallbackGadget), now()->addMinutes(5));

    expect(NativeCallbacks::resolve('capture-1', 'photo-taken'))->toBeNull()
        ->and(PlantedCallbackGadget::$woken)->toBeFalse();

    Log::shouldHaveReceived('warning');
});

it('drops a durable copy that a lane with another app key signed', function () {
    // An app with a different key writes the durable copy...
    bootCallbacksWithAppKey('base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC')));
    NativeCallbacks::register('capture-1', 'photo-taken', static fn () => 'ok');

    // ...and this app, booted with its own key, finds a signature it did not make.
    bootCallbacksWithAppKey('base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC')));
    NativeCallbacks::flush();

    expect(NativeCallbacks::resolve('capture-1', 'photo-taken'))->toBeNull();
});

it('does not unserialize the durable copy when the app has no key to check its signature', function () {
    Log::spy();

    // A well-formed envelope carrying a gadget. Without a key there is no
    // signature to check it against, so it is dropped before unserialize().
    Cache::put(nativeCallbackKey(), [
        'data' => base64_encode(serialize(new PlantedCallbackGadget)),
        'sig' => 'whatever',
    ], now()->addMinutes(5));

    bootCallbacksWithAppKey(null);

    expect(NativeCallbacks::resolve('capture-1', 'photo-taken'))->toBeNull()
        ->and(PlantedCallbackGadget::$woken)->toBeFalse();

    Log::shouldHaveReceived('warning');
});

it('keeps only the in-memory copy, and warns once, when there is no key to sign the durable copy', function () {
    bootCallbacksWithAppKey(null);

    Log::spy();

    NativeCallbacks::register('capture-1', 'photo-taken', static fn () => 'in-memory');

    // Nothing durable was written without a key to sign it...
    expect(Cache::has(nativeCallbackKey()))->toBeFalse()
        // ...but the in-memory copy still resolves for this process.
        ->and(NativeCallbacks::resolve('capture-1', 'photo-taken'))->toBeInstanceOf(Closure::class);

    Log::shouldHaveReceived('warning');
});
