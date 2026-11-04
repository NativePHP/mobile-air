<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Native\Mobile\Events\Wallet\PaymentCompleted;

/**
 * /_native/api/events has no middleware and is reachable by web view script, so
 * a page could forge a device event. Once the native shell signs its events
 * (NATIVEPHP_EVENTS_SIGNED), the controller must verify an app-key HMAC over a
 * nonce, a timestamp and the exact body, and refuse anything else. These drive
 * the controller over HTTP exactly as the web view would.
 */
beforeEach(function () {
    // Every lane boots with the app key the device shell hands it.
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    app()->forgetInstance('encrypter');
});

afterEach(function () {
    putenv('NATIVEPHP_EVENTS_SIGNED');
});

/** The exact body bytes, the signed headers, and the raw POST — as native sends them. */
function postSignedEvent(string $event, array $payload, array $override = []): TestResponse
{
    $body = json_encode(['event' => $event, 'payload' => $payload]);
    $nonce = $override['nonce'] ?? bin2hex(random_bytes(16));
    $timestamp = (string) ($override['timestamp'] ?? time());
    $signedBody = $override['signedBody'] ?? $body;

    $signature = $override['signature'] ?? hash_hmac(
        'sha256',
        "nativephp.native-event\n".$nonce."\n".$timestamp."\n".$signedBody,
        app('encrypter')->getKey(),
    );

    return test()->call('POST', '/_native/api/events', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_NATIVE_EVENT_NONCE' => $nonce,
        'HTTP_X_NATIVE_EVENT_TIMESTAMP' => $timestamp,
        'HTTP_X_NATIVE_EVENT_SIGNATURE' => $signature,
    ], $body);
}

$paid = fn () => ['paymentIntentId' => 'FORGED-9', 'amount' => 1, 'currency' => 'USD', 'status' => 'succeeded'];

it('lets an unsigned event through while the shell does not sign (back-compat)', function () use ($paid) {
    Event::fake([PaymentCompleted::class]);
    // NATIVEPHP_EVENTS_SIGNED unset → enforcement off.

    test()->postJson('/_native/api/events', ['event' => PaymentCompleted::class, 'payload' => $paid()])
        ->assertOk()->assertJson(['success' => true]);

    Event::assertDispatched(PaymentCompleted::class);
});

it('rejects an unsigned event once the shell signs', function () use ($paid) {
    Event::fake([PaymentCompleted::class]);
    putenv('NATIVEPHP_EVENTS_SIGNED=1');

    test()->postJson('/_native/api/events', ['event' => PaymentCompleted::class, 'payload' => $paid()])
        ->assertStatus(403)->assertJson(['success' => false]);

    Event::assertNotDispatched(PaymentCompleted::class);
});

it('accepts a correctly signed event', function () use ($paid) {
    Event::fake([PaymentCompleted::class]);
    putenv('NATIVEPHP_EVENTS_SIGNED=1');

    postSignedEvent(PaymentCompleted::class, $paid())
        ->assertOk()->assertJson(['success' => true]);

    Event::assertDispatched(PaymentCompleted::class);
});

it('rejects a signature made over a different body (tampered payload)', function () use ($paid) {
    Event::fake([PaymentCompleted::class]);
    putenv('NATIVEPHP_EVENTS_SIGNED=1');

    // Signature computed over a $1 charge, but the body posted says $1,000,000.
    $honest = json_encode(['event' => PaymentCompleted::class, 'payload' => $paid()]);
    postSignedEvent(PaymentCompleted::class, ['paymentIntentId' => 'FORGED-9', 'amount' => 1000000, 'currency' => 'USD', 'status' => 'succeeded'], [
        'signedBody' => $honest,
    ])->assertStatus(403);

    Event::assertNotDispatched(PaymentCompleted::class);
});

it('rejects a stale timestamp', function () use ($paid) {
    Event::fake([PaymentCompleted::class]);
    putenv('NATIVEPHP_EVENTS_SIGNED=1');

    postSignedEvent(PaymentCompleted::class, $paid(), ['timestamp' => time() - 120])
        ->assertStatus(403);

    Event::assertNotDispatched(PaymentCompleted::class);
});

it('rejects a replayed nonce', function () use ($paid) {
    Event::fake([PaymentCompleted::class]);
    putenv('NATIVEPHP_EVENTS_SIGNED=1');

    $nonce = bin2hex(random_bytes(16));

    postSignedEvent(PaymentCompleted::class, $paid(), ['nonce' => $nonce])->assertOk();
    // Same nonce again: one-time use, refused.
    postSignedEvent(PaymentCompleted::class, $paid(), ['nonce' => $nonce])->assertStatus(403);

    Event::assertDispatchedTimes(PaymentCompleted::class, 1);
});

it('rejects a signature the app key did not make', function () use ($paid) {
    Event::fake([PaymentCompleted::class]);
    putenv('NATIVEPHP_EVENTS_SIGNED=1');

    postSignedEvent(PaymentCompleted::class, $paid(), ['signature' => str_repeat('0', 64)])
        ->assertStatus(403);

    Event::assertNotDispatched(PaymentCompleted::class);
});
