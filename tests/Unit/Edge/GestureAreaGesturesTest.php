<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\GestureArea;
use Native\Mobile\Edge\NativeTagPrecompiler;
use Native\Mobile\Edge\SharedValue;

// ── Pinch binding ───────────────────────────────────

it('carries a pinch SharedValue as pinch-id / pinch-initial props', function () {
    $zoom = SharedValue::make(1.5);

    $area = GestureArea::make();
    $area->applyAttributes(['pinch' => $zoom]);

    $props = $area->getResolvedProps(new CallbackRegistry);

    expect($props['pinch-id'])->toBe($zoom->id);
    expect($props['pinch-initial'])->toBe(1.5);
});

it('carries pinch-min / pinch-max bounds to the wire', function () {
    $area = GestureArea::make();
    $area->applyAttributes([
        'pinch' => SharedValue::make(1.0),
        'pinch-min' => '0.5',
        'pinch-max' => '3',
    ]);

    $props = $area->getResolvedProps(new CallbackRegistry);

    expect($props['pinch-min'])->toBe(0.5);
    expect($props['pinch-max'])->toBe(3.0);
});

it('omits pinch bounds when not set', function () {
    $area = GestureArea::make();
    $area->applyAttributes(['pinch' => SharedValue::make(1.0)]);

    $props = $area->getResolvedProps(new CallbackRegistry);

    expect($props)->not->toHaveKey('pinch-min');
    expect($props)->not->toHaveKey('pinch-max');
});

it('omits pinch props when no pinch value is bound', function () {
    $props = GestureArea::make()->getResolvedProps(new CallbackRegistry);

    expect($props)->not->toHaveKey('pinch-id');
    expect($props)->not->toHaveKey('pinch-initial');
});

it('registers on_pinch_end with a resolvable callback id', function () {
    $registry = new CallbackRegistry;

    $props = GestureArea::make()
        ->onPinchEnd('zoomEnded')
        ->getResolvedProps($registry);

    expect($props['on_pinch_end'])->toBeInt()->toBeGreaterThan(0);
    expect($registry->resolve($props['on_pinch_end'])['method'])->toBe('zoomEnded');
});

// ── Pan ─────────────────────────────────────────────

it('carries pan-x and pan-y SharedValues as id / initial props, each axis independently', function () {
    $dx = SharedValue::make(12.0);
    $dy = SharedValue::make(-4.0);

    $area = GestureArea::make();
    $area->applyAttributes(['pan-x' => $dx, 'pan-y' => $dy]);

    $props = $area->getResolvedProps(new CallbackRegistry);

    expect($props['pan-x-id'])->toBe($dx->id)
        ->and($props['pan-x-initial'])->toBe(12.0)
        ->and($props['pan-y-id'])->toBe($dy->id)
        ->and($props['pan-y-initial'])->toBe(-4.0);

    $xOnly = GestureArea::make();
    $xOnly->applyAttributes(['pan-x' => $dx]);
    $props = $xOnly->getResolvedProps(new CallbackRegistry);

    expect($props)->toHaveKey('pan-x-id')
        ->and($props)->not->toHaveKey('pan-y-id');
});

it('registers on_drag_end with the drag_end kind so dispatch decodes "x,y"', function () {
    $registry = new CallbackRegistry;

    $props = GestureArea::make()
        ->onDragEnd('released')
        ->getResolvedProps($registry);

    expect($props['on_drag_end'])->toBeInt()->toBeGreaterThan(0)
        ->and($registry->resolve($props['on_drag_end'])['method'])->toBe('released')
        ->and($registry->kind($props['on_drag_end']))->toBe('drag_end');

    expect(GestureArea::make()->getResolvedProps(new CallbackRegistry))->not->toHaveKey('on_drag_end');
});

// ── Swipe ───────────────────────────────────────────

it('registers on_swipe with the finger count from swipe-fingers', function () {
    $registry = new CallbackRegistry;

    $area = GestureArea::make()->onSwipe('handleSwipe');
    $area->applyAttributes(['swipe-fingers' => '3']);

    $props = $area->getResolvedProps($registry);

    expect($props['on_swipe'])->toBeInt()->toBeGreaterThan(0);
    expect($props['swipe-fingers'])->toBe(3);
    expect($registry->resolve($props['on_swipe'])['method'])->toBe('handleSwipe');
});

it('defaults swipe-fingers to 1 and clamps invalid counts up to 1', function () {
    $props = GestureArea::make()
        ->onSwipe('handleSwipe')
        ->getResolvedProps(new CallbackRegistry);

    expect($props['swipe-fingers'])->toBe(1);

    $area = GestureArea::make()->onSwipe('handleSwipe');
    $area->applyAttributes(['swipe-fingers' => '0']);

    expect($area->getResolvedProps(new CallbackRegistry)['swipe-fingers'])->toBe(1);
});

it('omits swipe props when no swipe handler is set', function () {
    $area = GestureArea::make();
    $area->applyAttributes(['swipe-fingers' => '3']);

    $props = $area->getResolvedProps(new CallbackRegistry);

    expect($props)->not->toHaveKey('on_swipe');
    expect($props)->not->toHaveKey('swipe-fingers');
});

// ── Precompiler event-attr conversion ───────────────

describe('precompiler @swipe / @pinchEnd conversion', function () {
    beforeEach(function () {
        $this->precompiler = new NativeTagPrecompiler;
        NativeTagPrecompiler::setActive(true);
    });

    afterEach(function () {
        NativeTagPrecompiler::setActive(false);
    });

    it('converts @swipe and @pinchEnd to underscored attrs', function () {
        $result = ($this->precompiler)(
            '<native:gesture-area @swipe="onSwipe" swipe-fingers="3" @pinchEnd="onZoomEnd">x</native:gesture-area>'
        );

        expect($result)->toContain("'_swipe' => 'onSwipe'");
        expect($result)->toContain("'_pinchEnd' => 'onZoomEnd'");
        expect($result)->toContain("'swipe-fingers' => '3'");
    });

    it('converts @dragEnd to an underscored attr', function () {
        $result = ($this->precompiler)(
            '<native:gesture-area :pan-x="$dx" @dragEnd="onRelease">x</native:gesture-area>'
        );

        expect($result)->toContain("'_dragEnd' => 'onRelease'");
    });

    it('still converts @swipeDelete despite the shared prefix with @swipe', function () {
        $result = ($this->precompiler)(
            '<native:list-item @swipeDelete="removeRow" />'
        );

        expect($result)->toContain("'_swipeDelete' => 'removeRow'");
        expect($result)->not->toContain('_swipeDelete\' => \'removeRow\'Delete');
    });
});
