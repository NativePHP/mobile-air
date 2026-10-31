<?php

use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ChromeTabsLayout;
use Tests\Fixtures\Edge\FallbackProbeScreen;
use Tests\Fixtures\Edge\LazyChromeScreen;
use Tests\Fixtures\Edge\LogFeedScreen;
use Tests\Fixtures\Edge\PlainBackScreen;
use Tests\Fixtures\Edge\PopToTargetScreen;
use Tests\Fixtures\Edge\PopToTrace;
use Tests\Fixtures\Edge\ThreadScreen;

/**
 * Frame-level contract for the per-mount `screen_tokens` metadata the
 * root chrome sentinels carry: an OPAQUE, random, per-INSTANCE token
 * (stable across re-renders/resumes of the same mounted screen; fresh
 * on every new mount), delivered bottom-up for the whole live stack so
 * the device side can key per-screen saveable state (scroll position,
 * pager page) by screen-MOUNT identity — never by URI, which a fresh
 * REPLACE of the same URI would alias.
 */
beforeEach(function () {
    NativeRouter::clearRoutes();
    PopToTrace::resetAll();
    Native::fakeBridge();

    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    NativeRouter::register('/log', LogFeedScreen::class);
    NativeRouter::register('/log/thread', ThreadScreen::class);
    NativeRouter::register('/fallback', FallbackProbeScreen::class);
});

afterEach(function () {
    NativeRouter::clearRoutes();
});

it('carries an opaque per-mount screen token that is stable across re-renders', function () {
    LazyChromeScreen::resetCounters();
    $screen = Native::test(LazyChromeScreen::class, layout: ChromeTabsLayout::class);
    $token = $screen->instance()->screenToken();

    // Opaque and non-numeric by construction: 32 hex chars of entropy,
    // so a fresh process can never collide with a previous mount's
    // token (the numeric-counter hazard).
    expect($token)->toMatch('/^[0-9a-f]{32}$/');

    $screen->assertElement('native_root_tabs', fn (array $n) => ($n['props']['screen_tokens'] ?? null) === [$token]);

    // Re-render the SAME instance: the token must not move.
    $screen->call('bump')->call('bump');
    $screen->assertElement('native_root_tabs', fn (array $n) => ($n['props']['screen_tokens'] ?? null) === [$token]);
});

it('gives a fresh mount of the same uri a brand new token', function () {
    $first = Native::visit('/log');
    $second = Native::visit('/log');

    expect($first->instance()->screenToken())
        ->toMatch('/^[0-9a-f]{32}$/')
        ->not->toBe($second->instance()->screenToken());
});

it('appends the pushed screen token and plain back removes only the top one', function () {
    $router = new NativeRouter;
    $router->preloadStack([
        ['uri' => '/log', 'params' => []],
        ['uri' => '/log/thread', 'params' => []],
    ]);

    $exit = $router->start(PlainBackScreen::class, [], '/log/profile');
    expect($exit)->toBe('/feed-done');

    // Liveness observed LIVE at each lifecycle point: after the single
    // back the thread (not the feed) is top again — three mounts, all
    // distinct — and each later resume peels exactly one token.
    expect(ThreadScreen::$resumeTokens)->toBe([LogFeedScreen::$token, ThreadScreen::$token])
        ->and(LogFeedScreen::$resumeTokens)->toBe([LogFeedScreen::$token])
        ->and(LogFeedScreen::$token)->not->toBe(ThreadScreen::$token);
});

it('popTo unwind evicts exactly the popped tokens and keeps the target mount', function () {
    $router = new NativeRouter;
    $router->preloadStack([
        ['uri' => '/log', 'params' => []],
        ['uri' => '/log/thread', 'params' => []],
    ]);

    $exit = $router->start(PopToTargetScreen::class, ['target' => '/log'], '/log/profile');
    expect($exit)->toBe('/feed-done');

    // Full bottom-up liveness at the moment the unwind started: feed,
    // thread, pop-to target — three DISTINCT opaque tokens in order.
    expect(PopToTargetScreen::$unmountTokens)->toBe(
        [LogFeedScreen::$token, ThreadScreen::$token, PopToTargetScreen::$token]
    );

    // After the unwind the target is the ONLY live mount, and it is the
    // SAME token the feed mounted with (never a remount).
    expect(LogFeedScreen::$resumeTokens)->toBe([LogFeedScreen::$token])
        ->and(LogFeedScreen::$mounts)->toBe(1);
});

it('popTo replace fallback carries a fresh token for the new mount', function () {
    $router = new NativeRouter;
    $router->preloadStack([
        ['uri' => '/log', 'params' => []],
    ]);

    $exit = $router->start(PopToTargetScreen::class, ['target' => '/fallback'], '/log/thread');
    expect($exit)->toBe('/feed-done');

    // The fallback's fresh mount: feed still live underneath, fallback
    // on top with a NEW token, distinct from the replaced screen's.
    expect(PopToTargetScreen::$unmountTokens)->toBe([LogFeedScreen::$token, PopToTargetScreen::$token])
        ->and(FallbackProbeScreen::$mountTokens)->toBe([LogFeedScreen::$token, FallbackProbeScreen::$token])
        ->and(FallbackProbeScreen::$token)->not->toBe(PopToTargetScreen::$token)
        ->and(FallbackProbeScreen::$token)->toMatch('/^[0-9a-f]{32}$/');
});

it('carries the screen token on a lazy placeholder frame, before mount runs', function () {
    LazyChromeScreen::resetCounters();

    $screen = Native::test(LazyChromeScreen::class, layout: ChromeTabsLayout::class);

    // The placeholder published before mount(): it must already name
    // the mount's token so the device pane binds to the right identity
    // from the very first frame.
    $placeholderFrames = array_values(array_filter(
        Native::fakeBridge()->publishes,
        fn (array $tree): bool => ($tree['type'] ?? '') === 'native_root_tabs',
    ));

    expect($placeholderFrames)->not->toBeEmpty();

    $token = $screen->instance()->screenToken();
    expect($token)->toMatch('/^[0-9a-f]{32}$/')
        ->and($placeholderFrames[0]['props']['screen_tokens'] ?? null)->toBe([$token]);
});
