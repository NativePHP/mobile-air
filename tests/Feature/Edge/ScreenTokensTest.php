<?php

use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ChromeStackLayout;
use Tests\Fixtures\Edge\ChromeTabsLayout;
use Tests\Fixtures\Edge\InlineTopBarScreen;
use Tests\Fixtures\Edge\ScreenTokenDetailScreen;
use Tests\Fixtures\Edge\ScreenTokenListScreen;
use Tests\Fixtures\Edge\ScreenTokenTrace;

/**
 * Per-mount screen tokens (mobile-air #298). The chrome sentinels carry the
 * token of every live screen on the router stack, bottom first, so Android
 * can keep a pushed-over screen's saved view state (a list's scroll
 * position) and drop it once that screen leaves the stack. A token belongs
 * to one mounted instance: re-renders and resumes keep it, a fresh mount —
 * even of the same URI — gets a new one.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
    NativeRouter::clearRoutes();
    ScreenTokenTrace::reset();
    Native::fakeBridge();
});

afterEach(function () {
    NativeRouter::clearRoutes();
    ScreenTokenTrace::reset();
});

/** @param  list<NativeComponent>  $components */
function routerWithStack(array $components): NativeRouter
{
    $router = new NativeRouter;
    Closure::bind(function () use ($components) {
        /** @var NativeRouter $this */
        foreach ($components as $i => $component) {
            $this->stack[] = ['component' => $component, 'uri' => "/level/{$i}", 'params' => []];
        }
    }, $router, NativeRouter::class)();

    return $router;
}

it('gives a screen an opaque token that survives re-renders', function () {
    $screen = Native::test(InlineTopBarScreen::class, layout: ChromeTabsLayout::class);
    $token = $screen->instance()->screenToken();

    expect($token)->toMatch('/^[0-9a-f]{32}$/');

    $screen->assertElement('native_root_tabs', fn (array $n) => ($n['props']['screen_tokens'] ?? null) === $token);

    $screen->call('save')->call('save');

    expect($screen->instance()->screenToken())->toBe($token);
    $screen->assertElement('native_root_tabs', fn (array $n) => ($n['props']['screen_tokens'] ?? null) === $token);
});

it('gives every fresh mount of the same screen a new token', function () {
    $first = Native::test(InlineTopBarScreen::class, layout: ChromeTabsLayout::class)->instance();
    $second = Native::test(InlineTopBarScreen::class, layout: ChromeTabsLayout::class)->instance();

    expect($first->screenToken())->not->toBe($second->screenToken());
});

it('sends the live stack tokens bottom first on the tabs sentinel', function () {
    $root = Native::test(InlineTopBarScreen::class, layout: ChromeTabsLayout::class);
    $pushed = Native::test(InlineTopBarScreen::class, layout: ChromeTabsLayout::class);

    $pushed->instance()->setRouter(routerWithStack([$root->instance(), $pushed->instance()]));

    $expected = $root->instance()->screenToken()."\n".$pushed->instance()->screenToken();
    $pushed->call('save')->assertElement('native_root_tabs', fn (array $n) => ($n['props']['screen_tokens'] ?? null) === $expected);
});

it('sends the live stack tokens bottom first on the stack sentinel', function () {
    $root = Native::test(InlineTopBarScreen::class, layout: ChromeStackLayout::class);
    $pushed = Native::test(InlineTopBarScreen::class, layout: ChromeStackLayout::class);

    $pushed->instance()->setRouter(routerWithStack([$root->instance(), $pushed->instance()]));

    $expected = $root->instance()->screenToken()."\n".$pushed->instance()->screenToken();
    $pushed->call('save')->assertElement('native_root_stack', fn (array $n) => ($n['props']['screen_tokens'] ?? null) === $expected);
});

it('appends its own token when the screen is not on the stack yet', function () {
    $root = Native::test(InlineTopBarScreen::class, layout: ChromeStackLayout::class);
    $incoming = Native::test(InlineTopBarScreen::class, layout: ChromeStackLayout::class);

    $incoming->instance()->setRouter(routerWithStack([$root->instance()]));

    $expected = $root->instance()->screenToken()."\n".$incoming->instance()->screenToken();
    $incoming->call('save')->assertElement('native_root_stack', fn (array $n) => ($n['props']['screen_tokens'] ?? null) === $expected);
});

it('keeps the resumed screen token and drops the popped one on back', function () {
    NativeRouter::register('/tokens/list', ScreenTokenListScreen::class, ChromeStackLayout::class);
    NativeRouter::register('/tokens/detail', ScreenTokenDetailScreen::class, ChromeStackLayout::class);

    $exit = (new NativeRouter)->start(ScreenTokenListScreen::class, [], '/tokens/list');

    expect($exit)->toBe('/tokens-done');

    $list = ScreenTokenTrace::first('list.mount');
    $detail = ScreenTokenTrace::first('detail.mount');
    $resume = ScreenTokenTrace::first('list.resume');

    expect($list['stack'])->toBe([$list['own']])
        ->and($detail['stack'])->toBe([$list['own'], $detail['own']])
        ->and($detail['own'])->not->toBe($list['own'])
        // Back: the list is resumed as the same instance, the detail token is gone.
        ->and($resume['own'])->toBe($list['own'])
        ->and($resume['stack'])->toBe([$list['own']]);
});

it('gives a same-uri replace a fresh token and drops the replaced one', function () {
    NativeRouter::register('/tokens/list', ScreenTokenListScreen::class, ChromeStackLayout::class);
    NativeRouter::register('/tokens/detail', ScreenTokenDetailScreen::class, ChromeStackLayout::class);
    ScreenTokenTrace::$replaceDetailOnce = true;

    $exit = (new NativeRouter)->start(ScreenTokenListScreen::class, [], '/tokens/list');

    expect($exit)->toBe('/tokens-done');

    $list = ScreenTokenTrace::first('list.mount');
    [$replaced, $fresh] = ScreenTokenTrace::all('detail.mount');

    expect($fresh['own'])->not->toBe($replaced['own'])
        ->and($fresh['stack'])->toBe([$list['own'], $fresh['own']])
        ->and($fresh['stack'])->not->toContain($replaced['own'])
        ->and(ScreenTokenTrace::first('list.resume')['stack'])->toBe([$list['own']]);
});
