<?php

use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Support\NativeCallbacks;
use Native\Mobile\Testing\Native;
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
