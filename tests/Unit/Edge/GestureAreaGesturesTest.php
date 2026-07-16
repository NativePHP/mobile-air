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

    it('still converts @swipeDelete despite the shared prefix with @swipe', function () {
        $result = ($this->precompiler)(
            '<native:list-item @swipeDelete="removeRow" />'
        );

        expect($result)->toContain("'_swipeDelete' => 'removeRow'");
        expect($result)->not->toContain('_swipeDelete\' => \'removeRow\'Delete');
    });
});
