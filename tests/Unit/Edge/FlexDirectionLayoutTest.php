<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeElementCollector;

beforeEach(fn () => NativeElementCollector::reset());
afterEach(fn () => NativeElementCollector::reset());

// flex-row / flex-col parse to a flexDirection attribute; the collector
// must forward it into the layout wire format or the utility silently
// does nothing on device.

it('forwards flex-row into the layout array', function () {
    NativeElementCollector::open('column', ['class' => 'flex-row']);
    NativeElementCollector::close();

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['layout']['flex_direction'] ?? null)->toBe(1);
});

it('forwards flex-col into the layout array', function () {
    NativeElementCollector::open('row', ['class' => 'flex-col']);
    NativeElementCollector::close();

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['layout']['flex_direction'] ?? null)->toBe(0);
});
