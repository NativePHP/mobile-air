<?php

use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Support\NativeCallbacks;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\PingReceived;

/*
 * A result is matched to its fluent callback by the id its Pending* builder sent.
 * The event-class fallback exists for results that come back with no id at all
 * (the gallery picker drops it, some events never carry one). A result whose id
 * matches nothing comes from another operation that registered no callback, so it
 * must not take this one: the callback would run with the wrong result and its own
 * result would then find nothing. See #713.
 */

it('does not hand a result with an unknown id to another pending callback', function () {
    // awaitPing() registers its callback under 'ping-capture'.
    $screen = Native::test(CounterScreen::class)
        ->call('awaitPing')
        ->emitNative(PingReceived::class, ['message' => 'stranger', 'id' => 'another-request'])
        ->assertAwaitingNativeEvent(PingReceived::class)
        ->emitNative(PingReceived::class, ['message' => 'mine', 'id' => 'ping-capture'])
        ->assertNotAwaitingNativeEvent(PingReceived::class);

    // The #[On] listener hears both; the callback runs once, with its own result.
    expect($screen->get('pings'))->toBe(['stranger', 'cb:mine', 'mine']);
});

it('still falls back to the pending callback when the result has no id', function () {
    $screen = Native::test(CounterScreen::class)
        ->call('awaitPing')
        ->emitNative(PingReceived::class, ['message' => 'id dropped'])
        ->assertNotAwaitingNativeEvent(PingReceived::class);

    expect($screen->get('pings'))->toBe(['cb:id dropped', 'id dropped']);
});

it('does not hand a web view result with an unknown id to another pending callback', function () {
    $received = new ArrayObject;

    NativeCallbacks::register('capture-mine', PhotoTaken::class, static function (PhotoTaken $event) use ($received) {
        $received[] = $event->path;
    }, durable: false);

    $this->postJson('/_native/api/events', [
        'event' => PhotoTaken::class,
        'payload' => ['path' => '/tmp/theirs.jpg', 'id' => 'capture-theirs'],
    ])->assertOk()->assertJson(['success' => true, 'callback' => false]);

    $this->postJson('/_native/api/events', [
        'event' => PhotoTaken::class,
        'payload' => ['path' => '/tmp/mine.jpg', 'id' => 'capture-mine'],
    ])->assertOk()->assertJson(['success' => true, 'callback' => true]);

    $this->postJson('/_native/api/events', [
        'event' => PhotoTaken::class,
        'payload' => ['path' => '/tmp/no-id.jpg'],
    ])->assertOk()->assertJson(['success' => true, 'callback' => false]);

    expect($received->getArrayCopy())->toBe(['/tmp/mine.jpg']);
});

it('still falls back in the web view when the result has no id', function () {
    $received = new ArrayObject;

    NativeCallbacks::register('gallery-pick', PhotoTaken::class, static function (PhotoTaken $event) use ($received) {
        $received[] = $event->path;
    }, durable: false);

    $this->postJson('/_native/api/events', [
        'event' => PhotoTaken::class,
        'payload' => ['path' => '/tmp/picked.jpg'],
    ])->assertOk()->assertJson(['success' => true, 'callback' => true]);

    expect($received->getArrayCopy())->toBe(['/tmp/picked.jpg']);
});
