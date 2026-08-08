<?php

use Native\Mobile\Events\Camera\PhotoCancelled;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Http\Controllers\DispatchEventFromAppController;
use Native\Mobile\Support\NativeCallbacks;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\CustomPickEvent;
use Tests\Fixtures\Edge\PickerScreen;

/**
 * Fluent native callbacks across a process boundary. The in-memory tier
 * dies with the process (OS kill on device, next request on web);
 * NativeCallbacks::flush() simulates exactly that between registering a
 * callback and delivering its result event, so every passing test here
 * proves the DURABLE tier alone can fire the callback on a live
 * component.
 */
beforeEach(function () {
    NativeCallbacks::flush();
});

afterEach(function () {
    NativeCallbacks::flush();
});

// ── $this-bound closures (the carrier trick) ────────

it('fires a $this-bound closure from the durable tier after a process death', function () {
    $screen = Native::test(PickerScreen::class)
        ->call('startClosure')
        ->assertAwaitingNativeEvent(MediaSelected::class);

    NativeCallbacks::flush(); // "process died" — memory tier gone

    $screen->emitNative(MediaSelected::class, [
        'success' => true,
        'files' => [['path' => '/tmp/photo.jpg']],
        'count' => 1,
        'id' => 'closure-pick',
    ])->assertSee('Picked: /tmp/photo.jpg!'); // private $secretSuffix appended
});

it('restores private-member access when the woken closure rebinds', function () {
    // Covered by the assertion above ('!' comes from a private prop), but
    // assert the state directly too so a rendering change can't mask it.
    $screen = Native::test(PickerScreen::class)->call('startClosure');

    NativeCallbacks::flush();

    $screen->emitNative(MediaSelected::class, [
        'success' => true, 'files' => [['path' => '/a.png']], 'count' => 1, 'id' => 'closure-pick',
    ])->assertSet('picked', '/a.png!');
});

it('still fires the warm in-memory copy without any process death', function () {
    Native::test(PickerScreen::class)
        ->call('startClosure')
        ->emitNative(MediaSelected::class, [
            'success' => true, 'files' => [['path' => '/warm.png']], 'count' => 1, 'id' => 'closure-pick',
        ])
        ->assertSet('picked', '/warm.png!');
});

it('consumes the callback after firing (one outcome per capture)', function () {
    $screen = Native::test(PickerScreen::class)
        ->call('startClosure')
        ->emitNative(MediaSelected::class, [
            'success' => true, 'files' => [['path' => '/first.png']], 'count' => 1, 'id' => 'closure-pick',
        ]);

    expect(NativeCallbacks::has('closure-pick', MediaSelected::class))->toBeFalse();

    // A duplicate delivery is a no-op, not a double mutation.
    $screen->emitNative(MediaSelected::class, [
        'success' => true, 'files' => [['path' => '/second.png']], 'count' => 1, 'id' => 'closure-pick',
    ])->assertSet('picked', '/first.png!');
});

// ── Method-name strings ─────────────────────────────

it('fires a method-name callback on the live component after a process death', function () {
    $screen = Native::test(PickerScreen::class)->call('startMethodName');

    NativeCallbacks::flush();

    $screen->emitNative(MediaSelected::class, [
        'success' => true, 'files' => [], 'count' => 3, 'id' => 'method-pick',
    ])->assertSet('status', 'picked:3')
        ->assertSee('Status: picked:3');
});

it('drops an unknown method-name string without crashing and forgets it', function () {
    $screen = Native::test(PickerScreen::class)->call('startBogusMethod');

    $screen->emitNative(MediaSelected::class, [
        'success' => true, 'files' => [], 'count' => 1, 'id' => 'bogus-pick',
    ])->assertSet('status', 'idle'); // nothing fired, nothing exploded

    expect(NativeCallbacks::has('bogus-pick', MediaSelected::class))->toBeFalse();
});

// ── onSuccess() sugar ───────────────────────────────

it('onSuccess registers for the builder success event', function () {
    Native::test(PickerScreen::class)->call('startClosure');

    expect(NativeCallbacks::has('closure-pick', MediaSelected::class))->toBeTrue();
});

// ── #[On] prefix idempotence ────────────────────────

it('normalizes an already-prefixed #[On] event name instead of double-prefixing', function () {
    $bare = new \Native\Mobile\Attributes\On(MediaSelected::class);
    $prefixed = new \Native\Mobile\Attributes\On('native:'.MediaSelected::class);

    expect($bare->event)->toBe('native:'.MediaSelected::class)
        ->and($prefixed->event)->toBe('native:'.MediaSelected::class);
});

// ── Review round two: registry hardening ────────────

it('fires an id-less result event from the durable tier via the latest-id index', function () {
    // The headline kill scenario: Android drops the id across the
    // lifecycle bounce AND the memory tier died with the process.
    $screen = Native::test(PickerScreen::class)->call('startClosure');

    NativeCallbacks::flush();

    $screen->emitNative(MediaSelected::class, [
        'success' => true, 'files' => [['path' => '/idless.png']], 'count' => 1,
        // no 'id' key at all
    ])->assertSet('picked', '/idless.png!');
});

it('skips a durable closure whose owning screen class is not the live one', function () {
    Native::test(PickerScreen::class)->call('startClosure');

    NativeCallbacks::flush();

    // Cold start lands on a different screen; ScreenA's closure must not
    // be rebound to it.
    Native::test(CounterScreen::class)
        ->emitNative(MediaSelected::class, [
            'success' => true, 'files' => [['path' => '/wrong.png']], 'count' => 1, 'id' => 'closure-pick',
        ]);

    expect(NativeCallbacks::has('closure-pick', MediaSelected::class))->toBeFalse();
});

it('drops durability for both closures of a one-line chain', function () {
    Native::test(PickerScreen::class)->call('startOneLineChain');

    NativeCallbacks::flush(); // memory gone — only durable copies could fire

    expect(NativeCallbacks::has('oneline-pick', PhotoTaken::class))->toBeFalse()
        ->and(NativeCallbacks::has('oneline-pick', PhotoCancelled::class))->toBeFalse();
});

it('keeps first-class callables durable without the carrier', function () {
    $screen = Native::test(PickerScreen::class)->call('startFirstClass');

    NativeCallbacks::flush();

    $screen->emitNative(MediaSelected::class, [
        'success' => true, 'files' => [], 'count' => 7, 'id' => 'fc-pick',
    ])->assertSet('status', 'picked:7');
});

it('skips durability for resource-capturing closures instead of poisoning them', function () {
    $screen = Native::test(PickerScreen::class)->call('startResourceClosure');

    // Warm copy still fires…
    expect(NativeCallbacks::has('resource-pick', MediaSelected::class))->toBeTrue();

    NativeCallbacks::flush();

    // …but no durable copy was written (a poisoned one would TypeError here).
    $screen->emitNative(MediaSelected::class, [
        'success' => true, 'files' => [], 'count' => 1, 'id' => 'resource-pick',
    ])->assertSet('status', 'idle');
});

it('skips durability above the payload size cap', function () {
    Native::test(PickerScreen::class)->call('startHuge');

    NativeCallbacks::flush();

    expect(NativeCallbacks::has('huge-pick', MediaSelected::class))->toBeFalse();
});

it('prefers a component method over a same-named loadable class', function () {
    // class_exists('error') is true (PHP built-in, case-insensitively) —
    // the component's own method must win.
    Native::test(PickerScreen::class)
        ->call('startClassNamedMethod')
        ->emitNative(MediaSelected::class, [
            'success' => true, 'files' => [], 'count' => 1, 'id' => 'named-pick',
        ])
        ->assertSet('status', 'component-error-method');
});

it('honors ->event() declared AFTER onSuccess()', function () {
    $screen = Native::test(PickerScreen::class)->call('startCustomEvent');

    // The registration must have moved off the default MediaSelected key…
    expect(NativeCallbacks::has('custom-pick', MediaSelected::class))->toBeFalse()
        ->and(NativeCallbacks::has('custom-pick', CustomPickEvent::class))->toBeTrue();

    // …and fire under the override, durable tier included.
    NativeCallbacks::flush();

    $screen->emitNative(CustomPickEvent::class, [
        'success' => true, 'count' => 5, 'id' => 'custom-pick',
    ])->assertSet('status', 'custom:5');
});

it('leaves component-owned callbacks alone in the app-event controller', function () {
    Native::test(PickerScreen::class)->call('startMethodName');

    $controller = new DispatchEventFromAppController;
    $fire = new ReflectionMethod($controller, 'fireCallback');
    $fire->setAccessible(true);

    $handled = $fire->invoke($controller, MediaSelected::class, ['id' => 'method-pick'], new MediaSelected(true));

    // Bailed without consuming: the Edge loop still owns the callback.
    expect($handled)->toBeFalse()
        ->and(NativeCallbacks::has('method-pick', MediaSelected::class))->toBeTrue();
});

it('scopes durable keys by session off-device', function () {
    Native::test(PickerScreen::class)->call('startClosure');
    NativeCallbacks::flush();

    // Same session still finds the durable copy…
    expect(NativeCallbacks::has('closure-pick', MediaSelected::class))->toBeTrue();

    // …a different session must not.
    $original = session()->getId();
    session()->setId(str_repeat('b', 40));

    try {
        expect(NativeCallbacks::has('closure-pick', MediaSelected::class))->toBeFalse();
    } finally {
        session()->setId($original);
    }
});
