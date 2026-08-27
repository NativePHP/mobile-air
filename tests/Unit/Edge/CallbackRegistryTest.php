<?php

use Native\Mobile\Edge\CallbackRegistry;

// Callback ids are content-addressed (a pure function of the expression
// string), so the same expression yields the same id in any process,
// any request, any registry — no counter state to replay. Stale trees
// (e.g. a native view that outlived a hot-reload PHP restart) resolve
// to the right callback instead of whatever the counter happened to
// hand out this boot.

it('derives the same id for the same expression in any registry', function () {
    $a = new CallbackRegistry;
    $b = new CallbackRegistry;

    expect($a->register('increment'))->toBe($b->register('increment'))
        ->and($a->register('add(5)'))->toBe($b->register('add(5)'));
});

it('is idempotent within a registry', function () {
    $r = new CallbackRegistry;

    expect($r->register('increment'))->toBe($r->register('increment'));
});

it('gives distinct expressions distinct ids', function () {
    $r = new CallbackRegistry;
    $ids = array_map(fn ($e) => $r->register($e), [
        'increment', 'decrement', 'add(5)', "openDetail('a')", "__syncProperty('query')",
    ]);

    expect(array_unique($ids))->toHaveCount(count($ids));
});

it('keeps ids positive and inside the signed 32-bit range', function () {
    $r = new CallbackRegistry;

    foreach (['a', 'increment', 'openDetail', 'add(5)', "__syncProperty('query')"] as $expr) {
        $id = $r->register($expr);

        expect($id)->toBeGreaterThan(0)
            ->and($id)->toBeLessThanOrEqual(0x7FFFFFFF);
    }
});

it('resolves a registered id back to its parsed expression', function () {
    $r = new CallbackRegistry;

    expect($r->resolve($r->register('add(5)')))->toBe(['method' => 'add', 'args' => [5]])
        ->and($r->resolve(123456789))->toBeNull();
});

it('derives distinct ids for the same expression under different scopes', function () {
    $screen = new CallbackRegistry;
    $childA = new CallbackRegistry('card|key:a');
    $childB = new CallbackRegistry('card|key:b');

    $ids = [$screen->register('bump'), $childA->register('bump'), $childB->register('bump')];

    expect(array_unique($ids))->toHaveCount(3)
        // Same scope reproduces the same id — determinism survives scoping.
        ->and((new CallbackRegistry('card|key:a'))->register('bump'))->toBe($ids[1]);
});

it('exposes stored navigation configs keyed by stable content-addressed keys', function () {
    $r = new CallbackRegistry;
    $key = $r->registerNavigation(['uri' => '/detail/7']);

    expect($r->navigations())->toBe([$key => ['uri' => '/detail/7']])
        // Same config re-registered anywhere reproduces the identical key.
        ->and((new CallbackRegistry)->registerNavigation(['uri' => '/detail/7']))->toBe($key);
});
