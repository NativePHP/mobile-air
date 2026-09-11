<?php

use Native\Mobile\Edge\NativeRouter;
use Tests\Fixtures\Edge\CounterScreen;

// `{param?}` is the documented optional-segment syntax, but `\w` does not match `?`, so
// the placeholder survived into the regex literally and the pattern could only ever match
// the string "{param?}". BootPlanner (both platforms) matches these patterns happily, so a
// route written this way booted into the native runloop and then resolved to no screen at
// all — with or without the segment present.

beforeEach(fn () => NativeRouter::clearRoutes());
afterEach(fn () => NativeRouter::clearRoutes());

it('resolves an optional segment when it is present', function () {
    NativeRouter::register('/posts/{slug?}', CounterScreen::class);

    $resolved = NativeRouter::resolve('/posts/hello-world');

    expect($resolved)->not->toBeNull()
        ->and($resolved['class'])->toBe(CounterScreen::class)
        ->and($resolved['params'])->toBe(['slug' => 'hello-world']);
});

it('resolves the same route when the optional segment is omitted', function () {
    NativeRouter::register('/posts/{slug?}', CounterScreen::class);

    $resolved = NativeRouter::resolve('/posts');

    expect($resolved)->not->toBeNull()
        ->and($resolved['class'])->toBe(CounterScreen::class)
        // Absent, not blank: a screen checks whether it has the parameter, and an empty
        // string would answer yes.
        ->and($resolved['params'])->toBe([]);
});

it('reports both forms as native routes, which is what the hosts ask', function () {
    // BootPlanner decides the boot mode from this answer, so disagreeing with resolve()
    // is what produced a native launch with nothing to show.
    NativeRouter::register('/posts/{slug?}', CounterScreen::class);

    expect(NativeRouter::isNativeRoute('/posts/hello'))->toBeTrue()
        ->and(NativeRouter::isNativeRoute('/posts'))->toBeTrue();
});

it('still requires a required segment', function () {
    NativeRouter::register('/posts/{slug}', CounterScreen::class);

    expect(NativeRouter::resolve('/posts/hello'))->not->toBeNull()
        ->and(NativeRouter::resolve('/posts'))->toBeNull();
});

it('handles an optional segment after a required one', function () {
    NativeRouter::register('/users/{id}/posts/{slug?}', CounterScreen::class);

    expect(NativeRouter::resolve('/users/7/posts/hello')['params'])->toBe(['id' => '7', 'slug' => 'hello'])
        ->and(NativeRouter::resolve('/users/7/posts')['params'])->toBe(['id' => '7'])
        ->and(NativeRouter::resolve('/users/7'))->toBeNull();
});

it('does not let an optional segment swallow a deeper path', function () {
    NativeRouter::register('/posts/{slug?}', CounterScreen::class);

    expect(NativeRouter::resolve('/posts/2026/august'))->toBeNull();
});
