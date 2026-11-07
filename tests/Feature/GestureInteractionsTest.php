<?php

use Native\Mobile\Edge\Exceptions\ComponentMethodNotFoundException;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\DragEndInteractionScreen;
use Tests\Fixtures\Edge\GestureScreen;

beforeEach(function () {
    NativeRouter::clearRoutes();
});

afterEach(function () {
    NativeRouter::clearRoutes();
});

// ── Swipe ───────────────────────────────────────────

it('dispatches swipe directions as strings by method name', function () {
    Native::test(GestureScreen::class)
        ->swipe('handleSwipe', 'left')
        ->assertSet('swiped', 'left')
        ->assertSee('Swiped: left');
});

it('dispatches swipes by ref through the on_swipe prop', function () {
    Native::test(GestureScreen::class)
        ->swipe('gesture-surface', 'up')
        ->assertSet('swiped', 'up');
});

// ── Pinch ───────────────────────────────────────────

it('dispatches pinch-end scale as a float by method name', function () {
    Native::test(GestureScreen::class)
        ->pinch('zoomEnded', 2.5)
        ->assertSet('zoom', 2.5)
        ->assertSee('Zoom: 2.5');
});

it('dispatches pinch-end by ref through the on_pinch_end prop', function () {
    Native::test(GestureScreen::class)
        ->pinch('gesture-surface', 0.5)
        ->assertSet('zoom', 0.5);
});

// ── Drag end ────────────────────────────────────────

it('dispatches drag-end as two floats decoded from the "x,y" payload', function () {
    Native::test(GestureScreen::class)
        ->dragEnd('released', 142.5, -8)
        ->assertSet('releasedX', 142.5)
        ->assertSet('releasedY', -8.0);
});

it('dispatches drag-end by ref through on_drag_end, not the swipe handler on the same node', function () {
    Native::test(GestureScreen::class)
        ->dragEnd('gesture-surface', 142.5, -8)
        ->assertSet('releasedX', 142.5)
        ->assertSet('releasedY', -8.0)
        ->assertSet('swiped', 'none');
});

it('decodes a payload with a missing component as 0', function () {
    Native::test(GestureScreen::class)
        ->fireEvent('released', 2, ['text' => '-90'])
        ->assertSet('releasedX', -90.0)
        ->assertSet('releasedY', 0.0);
});

it('refuses a drag-end bound to a protected method, as it does for a tap', function () {
    $test = Native::test(DragEndInteractionScreen::class);

    expect(fn () => $test->dragEnd('hidden', 10, 20))
        ->toThrow(ComponentMethodNotFoundException::class)
        ->and($test->instance()->hiddenRan)->toBeFalse();
});

it('puts a validation failure in a drag-end handler in the error bag', function () {
    $test = Native::test(DragEndInteractionScreen::class)
        ->dragEnd('rejectDrag', 10, 20);

    expect($test->instance()->getErrorBag()->first('position'))->toBe('Dropped out of bounds.');
});

it('flushes events dispatched from a drag-end handler inside the interaction', function () {
    $screen = Native::test(DragEndInteractionScreen::class)->instance();

    // Drive the component directly: the test harness and navigate() both
    // flush on their own, which would hide a missing flush here.
    $events = (function () {
        $id = $this->nativeCallbacks->lookup('dropped');
        $this->dispatchUiEvent(['type' => 2, 'callback_id' => $id, 'text' => '42,0']);

        return $this->events;
    })->call($screen);

    expect($events)->toBe(['dropped:42']);
});

// ── Trigger ─────────────────────────────────────────

it('dispatches a trigger with no arguments by method name', function () {
    Native::test(GestureScreen::class)
        ->trigger('pulled')
        ->assertSet('triggered', 1);
});

it('dispatches a trigger by ref through on_trigger, not the other handlers on the same node', function () {
    Native::test(GestureScreen::class)
        ->trigger('gesture-surface')
        ->assertSet('triggered', 1)
        ->assertSet('releasedX', 0.0)
        ->assertSet('swiped', 'none');
});
