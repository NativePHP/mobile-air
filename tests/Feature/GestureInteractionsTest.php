<?php

use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
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

it('reports 0 for a missing axis in the drag-end payload', function () {
    Native::test(GestureScreen::class)
        ->fireEvent('released', 2, ['text' => '-90'])
        ->assertSet('releasedX', -90.0)
        ->assertSet('releasedY', 0.0);
});
