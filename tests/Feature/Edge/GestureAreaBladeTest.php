<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeTagPrecompiler;
use Native\Mobile\Edge\SharedValue;

/**
 * Blade → precompiler → collector integration for the gesture-area
 * pinch / swipe wiring — the same markup shape the demo apps use.
 */
beforeEach(function () {
    NativeElementCollector::reset();
    NativeTagPrecompiler::setActive(true);

    $testViewPath = __DIR__.'/views';
    if (! is_dir($testViewPath)) {
        mkdir($testViewPath, 0755, true);
    }

    app('view')->addLocation($testViewPath);
});

afterEach(function () {
    NativeTagPrecompiler::setActive(false);
    NativeElementCollector::reset();

    foreach (glob(__DIR__.'/views/gesture-*.php') ?: [] as $file) {
        unlink($file);
    }
});

it('renders a pinch binding with a formula-bound child from Blade', function () {
    file_put_contents(
        __DIR__.'/views/gesture-pinch.blade.php',
        '<native:gesture-area :pinch="$zoom" @pinchEnd="pinchEnded" a11y-label="Pinch to zoom">'
        .'<native:column :scale="$zoom->clamp(0.5, 3)"><native:text>pinch me</native:text></native:column>'
        .'</native:gesture-area>'
    );

    $zoom = SharedValue::make(1.25);

    NativeElementCollector::reset();
    view('gesture-pinch', ['zoom' => $zoom])->render();
    $element = NativeElementCollector::collect();

    $registry = new CallbackRegistry;
    $tree = $element->toArray($registry);

    expect($tree['type'])->toBe('gesture_area');
    expect($tree['props']['pinch-id'])->toBe($zoom->id);
    expect($tree['props']['pinch-initial'])->toBe(1.25);
    expect($registry->resolve($tree['props']['on_pinch_end']))->toBe(['method' => 'pinchEnded', 'args' => []]);

    // The child's :scale carries the initial snapshot plus the wire-encoded
    // SharedValue binding (with the clamp formula) for the renderer.
    $child = $tree['children'][0];
    expect($child['props']['scale'])->toBe(1.25);
    expect($child['props']['scale_sv'])->toBe("__sv:{$zoom->id}|clamp:0.5,3");
});

it('renders a three-finger swipe handler from Blade', function () {
    file_put_contents(
        __DIR__.'/views/gesture-swipe.blade.php',
        '<native:gesture-area @swipe="threeFingerSwiped" swipe-fingers="3" a11y-label="Three finger swipe">'
        .'<native:text>swipe me</native:text>'
        .'</native:gesture-area>'
    );

    NativeElementCollector::reset();
    view('gesture-swipe')->render();
    $element = NativeElementCollector::collect();

    $registry = new CallbackRegistry;
    $tree = $element->toArray($registry);

    expect($tree['type'])->toBe('gesture_area');
    expect($tree['props']['swipe-fingers'])->toBe(3);
    expect($registry->resolve($tree['props']['on_swipe']))->toBe(['method' => 'threeFingerSwiped', 'args' => []]);
});
