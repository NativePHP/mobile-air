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

// ── Argument literals ───────────────────────────────

// The conversion to JSON used to be `str_replace("'", '"')`, which rewrote every
// apostrophe whatever its role. Three silent failures came out of that, and the
// third is the one worth the guard: the handler runs, with data nobody wrote.

it('keeps an apostrophe inside a double-quoted argument', function () {
    expect(CallbackRegistry::parse('save("don\'t")'))
        ->toBe(['method' => 'save', 'args' => ["don't"]]);
});

it('keeps an escaped apostrophe inside a single-quoted argument', function () {
    // Previously became `it"s fine` — no error, wrong value, handler invoked anyway.
    expect(CallbackRegistry::parse("rename('it\\'s fine')"))
        ->toBe(['method' => 'rename', 'args' => ["it's fine"]]);
});

it('keeps a double quote inside a single-quoted argument', function () {
    expect(CallbackRegistry::parse("say('he said \"hi\"')"))
        ->toBe(['method' => 'say', 'args' => ['he said "hi"']]);
});

it('still parses the ordinary literals', function () {
    expect(CallbackRegistry::parse('add(5)'))->toBe(['method' => 'add', 'args' => [5]])
        ->and(CallbackRegistry::parse("setName('Ada')"))->toBe(['method' => 'setName', 'args' => ['Ada']])
        ->and(CallbackRegistry::parse('flag(true, null)'))->toBe(['method' => 'flag', 'args' => [true, null]])
        ->and(CallbackRegistry::parse("pair('a', 'b')"))->toBe(['method' => 'pair', 'args' => ['a', 'b']])
        ->and(CallbackRegistry::parse('increment'))->toBe(['method' => 'increment', 'args' => []])
        ->and(CallbackRegistry::parse('reset()'))->toBe(['method' => 'reset', 'args' => []]);
});

it('keeps a comma inside a quoted argument out of the argument split', function () {
    expect(CallbackRegistry::parse("greet('Hello, world')"))
        ->toBe(['method' => 'greet', 'args' => ['Hello, world']]);
});

it('falls back to no arguments for an expression it cannot parse', function () {
    // The contract callers rely on (they spread the result), but no longer silent.
    expect(CallbackRegistry::parse('save(this is not a literal)'))
        ->toBe(['method' => 'save', 'args' => []]);
});
