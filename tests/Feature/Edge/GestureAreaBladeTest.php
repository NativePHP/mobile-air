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

it('renders a horizontal pan binding with a drag-end handler from Blade', function () {
    file_put_contents(
        __DIR__.'/views/gesture-pan-x.blade.php',
        '<native:gesture-area :pan-x="$dx" @dragEnd="released" a11y-label="Drag the card">'
        .'<native:column :translate-x="$dx" :rotate="$dx->interpolate([-300, 300], [-15, 15])"><native:text>card</native:text></native:column>'
        .'</native:gesture-area>'
    );

    $dx = SharedValue::make();

    NativeElementCollector::reset();
    view('gesture-pan-x', ['dx' => $dx])->render();
    $element = NativeElementCollector::collect();

    $registry = new CallbackRegistry;
    $tree = $element->toArray($registry);

    expect($tree['type'])->toBe('gesture_area')
        ->and($tree['props']['pan-x-id'])->toBe($dx->id)
        ->and($tree['props']['pan-x-initial'])->toBe(0.0)
        ->and($tree['props'])->not->toHaveKey('pan-y-id')
        ->and($registry->resolve($tree['props']['on_drag_end']))->toBe(['method' => 'released', 'args' => []])
        ->and($registry->kind($tree['props']['on_drag_end']))->toBe('drag_end');

    $child = $tree['children'][0];
    expect($child['props']['translate-x_sv'])->toBe("__sv:{$dx->id}")
        ->and($child['props']['rotate_sv'])->toBe("__sv:{$dx->id}|interp:-300,300:-15,15");
});

it('renders a snapping pan from Blade', function () {
    file_put_contents(
        __DIR__.'/views/gesture-snap-x.blade.php',
        '<native:gesture-area :pan-x="$dx" :snap-x="[-96, 0]" @dragEnd="settled">'
        .'<native:row :translate-x="$dx"><native:text>row</native:text></native:row>'
        .'</native:gesture-area>'
    );

    $dx = SharedValue::make(-96);

    NativeElementCollector::reset();
    view('gesture-snap-x', ['dx' => $dx])->render();
    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['props']['pan-x-snap'])->toBe('-96,0')
        ->and($tree['props']['pan-x-initial'])->toBe(-96.0);
});

it('renders a bounded zoom and pan area from Blade', function () {
    file_put_contents(
        __DIR__.'/views/gesture-bounded.blade.php',
        '<native:gesture-area :pinch="$zoom" pinch-min="1" pinch-max="4" :pan-x="$x" :pan-y="$y" bounded :content-ratio="$ratio">'
        .'<native:image src="https://example.com/a.png" :scale="$zoom" :translate-x="$x" :translate-y="$y" />'
        .'</native:gesture-area>'
    );

    $zoom = SharedValue::make(1.0);
    $x = SharedValue::make();
    $y = SharedValue::make();

    NativeElementCollector::reset();
    view('gesture-bounded', ['zoom' => $zoom, 'x' => $x, 'y' => $y, 'ratio' => 4 / 3])->render();
    $element = NativeElementCollector::collect();

    $tree = $element->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('gesture_area')
        ->and($tree['props']['bounded'])->toBeTrue()
        ->and($tree['props']['content-ratio'])->toBe(4 / 3)
        ->and($tree['props']['pan-x-id'])->toBe($x->id)
        ->and($tree['props']['pan-y-id'])->toBe($y->id)
        ->and($tree['props']['pinch-id'])->toBe($zoom->id);
});
