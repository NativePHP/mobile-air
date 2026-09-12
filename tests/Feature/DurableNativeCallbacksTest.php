<?php

use Native\Mobile\Events\Camera\PermissionDenied;
use Native\Mobile\Events\Camera\PhotoCancelled;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Http\Controllers\DispatchEventFromAppController;
use Native\Mobile\Support\NativeCallbacks;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\CustomPickEvent;
use Tests\Fixtures\Edge\PickerScreen;
use Tests\Fixtures\Edge\WrongPickScreen;

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

// ── Review round three: fixes-of-fixes ──────────────

it('keeps durability alive for repeat captures from the same source line', function () {
    // Builders default to fresh UUID ids: the same code line registering
    // again after the previous capture COMPLETED is normal reuse, not a
    // conflation — a stale same-line mapping must not kill durability
    // for every capture after the first.
    $screen = Native::test(PickerScreen::class)->call('startUuidPicker');

    // First capture completes (id-less delivery consumes + forgets).
    $screen->emitNative(MediaSelected::class, ['success' => true, 'files' => [], 'count' => 1])
        ->assertSet('status', 'uuid-picked');

    // Reset so the final assertion can't be satisfied by stale state —
    // with the guard bug reintroduced, this test MUST fail.
    $screen->set('status', 'idle');

    // Second capture from the SAME line, then the process dies.
    $screen->call('startUuidPicker');
    NativeCallbacks::flush();

    $screen->emitNative(MediaSelected::class, ['success' => true, 'files' => [], 'count' => 1])
        ->assertSet('status', 'uuid-picked'); // durable copy fired
});

it('controller bails on method names that shadow loadable classes', function () {
    // 'error' passes class_exists() — the previous !class_exists guard
    // consumed it, crashed, and destroyed the registration.
    Native::test(PickerScreen::class)->call('startClassNamedMethod');

    $controller = new DispatchEventFromAppController;
    $fire = new ReflectionMethod($controller, 'fireCallback');
    $fire->setAccessible(true);

    $handled = $fire->invoke($controller, MediaSelected::class, ['id' => 'named-pick'], new MediaSelected(true));

    expect($handled)->toBeFalse()
        ->and(NativeCallbacks::has('named-pick', MediaSelected::class))->toBeTrue();
});

it('skips a durable method-name callback owned by a different screen', function () {
    // The string carries no binding; the owner comes from the registering
    // call stack. Post-kill, a screen with a same-named method must NOT
    // fire it.
    Native::test(PickerScreen::class)->call('startMethodName');

    NativeCallbacks::flush();

    Native::test(WrongPickScreen::class)
        ->emitNative(MediaSelected::class, ['success' => true, 'files' => [], 'count' => 1, 'id' => 'method-pick'])
        ->assertSet('status', 'idle'); // NOT 'WRONGLY-FIRED'

    expect(NativeCallbacks::has('method-pick', MediaSelected::class))->toBeFalse();
});

it('skips durability when a captured object holds a resource', function () {
    $screen = Native::test(PickerScreen::class)->call('startDtoResource');

    expect(NativeCallbacks::has('dto-pick', MediaSelected::class))->toBeTrue(); // warm fine

    NativeCallbacks::flush();

    $screen->emitNative(MediaSelected::class, ['success' => true, 'files' => [], 'count' => 1, 'id' => 'dto-pick'])
        ->assertSet('status', 'idle'); // no poisoned durable copy fired
});

// ── Review round four ───────────────────────────────

it('records the owner for class-shadowing method names too', function () {
    // 'error' passes class_exists — pre-fix the owner tag was skipped
    // for it, so a wrong screen with an error() method fired it.
    Native::test(PickerScreen::class)->call('startClassNamedMethod');

    NativeCallbacks::flush();

    Native::test(WrongPickScreen::class)
        ->emitNative(MediaSelected::class, ['success' => true, 'files' => [], 'count' => 1, 'id' => 'named-pick'])
        ->assertSet('status', 'idle'); // NOT 'WRONGLY-FIRED-ERROR'

    expect(NativeCallbacks::has('named-pick', MediaSelected::class))->toBeFalse();
});

it('does not let an abandoned capture re-latch the same-line kill', function () {
    $screen = Native::test(PickerScreen::class)->call('startUuidPicker');

    // Abandon it: no result ever arrives. The durable copy expires
    // (simulated by clearing the cache) and the TTL window passes —
    // within the window the line stays conservatively blocked.
    cache()->flush();
    $this->travelTo(now()->addMinutes(6));

    // A later capture from the same line must regain durability.
    $screen->set('status', 'idle');
    $screen->call('startUuidPicker');
    NativeCallbacks::flush();

    $screen->emitNative(MediaSelected::class, ['success' => true, 'files' => [], 'count' => 1])
        ->assertSet('status', 'uuid-picked');
});

it('catches a resource captured by a NESTED closure', function () {
    $screen = Native::test(PickerScreen::class)->call('startNestedResource');

    NativeCallbacks::flush();

    $screen->emitNative(MediaSelected::class, ['success' => true, 'files' => [], 'count' => 1, 'id' => 'nested-pick'])
        ->assertSet('status', 'idle'); // durability was skipped, not poisoned
});

it('catches a CLOSED resource capture', function () {
    $screen = Native::test(PickerScreen::class)->call('startClosedResource');

    NativeCallbacks::flush();

    $screen->emitNative(MediaSelected::class, ['success' => true, 'files' => [], 'count' => 1, 'id' => 'closed-pick'])
        ->assertSet('status', 'idle');
});

it('fails closed when the resource scan hits its depth cap', function () {
    Native::test(PickerScreen::class)->call('startDeepResource');

    NativeCallbacks::flush();

    // Truncated walk costs durability, never risks a poisoned copy.
    expect(NativeCallbacks::has('deep-pick', MediaSelected::class))->toBeFalse();
});

// ── Review round five ───────────────────────────────

it('catches the THIRD closure of a one-line chain', function () {
    // reg2's collision forgets reg1's durable copy; reg3 must still be
    // detected via the line timestamp, not slip through because the
    // cache entry is gone.
    Native::test(PickerScreen::class)->call('startTripleLine');

    NativeCallbacks::flush();

    expect(NativeCallbacks::has('triple-pick', PhotoTaken::class))->toBeFalse()
        ->and(NativeCallbacks::has('triple-pick', PhotoCancelled::class))->toBeFalse()
        ->and(NativeCallbacks::has('triple-pick', PermissionDenied::class))->toBeFalse();
});

it('catches a line-mate whose durable copy was size-capped away', function () {
    // reg1 never wrote a cache entry (size cap) — reg2 on the same line
    // must still be treated as a conflation suspect.
    Native::test(PickerScreen::class)->call('startHugeThenSmallLine');

    NativeCallbacks::flush();

    expect(NativeCallbacks::has('hts-pick', PhotoCancelled::class))->toBeFalse();
});

it('keeps ordinary deep captures durable under the raised cap', function () {
    // Six levels of plain arrays — the shape of a model with a loaded
    // relation — must NOT lose the durable tier.
    $screen = Native::test(PickerScreen::class)->call('startDeepClean');

    NativeCallbacks::flush();

    $screen->emitNative(MediaSelected::class, ['success' => true, 'files' => [], 'count' => 1, 'id' => 'deepclean-pick'])
        ->assertSet('status', 'deepclean:1');
});

it('controller bails on component-bound array callables', function () {
    Native::test(PickerScreen::class)->call('startArrayCallback');

    $controller = new DispatchEventFromAppController;
    $fire = new ReflectionMethod($controller, 'fireCallback');
    $fire->setAccessible(true);

    $handled = $fire->invoke($controller, MediaSelected::class, ['id' => 'array-pick'], new MediaSelected(true));

    expect($handled)->toBeFalse()
        ->and(NativeCallbacks::has('array-pick', MediaSelected::class))->toBeTrue();
});
