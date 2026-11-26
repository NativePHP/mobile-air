<?php

use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ChromeTabsLayout;
use Tests\Fixtures\Edge\InlineTopBarScreen;

/**
 * The tabs sentinel carries every URI on the router stack, so iOS can find a
 * tab's root in any frame, even one that lands with the root's own frame
 * unseen (see PerTabNavigationCoordinator.receive).
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

it('sends the router stack on the tabs sentinel, bottom first', function () {
    $screen = Native::test(InlineTopBarScreen::class, layout: ChromeTabsLayout::class);

    $router = new NativeRouter;
    Closure::bind(function () use ($screen) {
        /** @var NativeRouter $this */
        foreach (['/', '/push?type=replied&commit_id=c1', '/detail/1', '/detail/1/commits/c1'] as $uri) {
            $this->stack[] = ['component' => $screen->instance(), 'uri' => $uri, 'params' => []];
        }
    }, $router, NativeRouter::class)();
    $screen->instance()->setRouter($router);

    $screen->call('save')->assertElement('native_root_tabs', fn (array $n) => ($n['props']['stack_depth'] ?? null) === 4
        && ($n['props']['stack_uris'] ?? null) === "/\n/push?type=replied&commit_id=c1\n/detail/1\n/detail/1/commits/c1");
});

it('sends an empty stack when the screen has no router', function () {
    Native::test(InlineTopBarScreen::class, layout: ChromeTabsLayout::class)
        ->assertElement('native_root_tabs', fn (array $n) => ($n['props']['stack_uris'] ?? null) === '');
});
