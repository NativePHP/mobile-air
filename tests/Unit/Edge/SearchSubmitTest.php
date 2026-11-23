<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\NativeRootTabs;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ChromeScreen;
use Tests\Fixtures\Edge\SearchTabScreen;
use Tests\Fixtures\Edge\SearchTabsLayout;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

it('registers the search submit handler as a plain callback on the native root', function () {
    $registry = new CallbackRegistry;
    $root = NativeRootTabs::make();
    $root->applyAttributes(['navSearchOnSubmitMethod' => 'onSearchSubmit']);

    $props = $root->toArray($registry)['props'];

    expect($props['nav_search_on_submit'])->toBeInt()->toBeGreaterThan(0)
        ->and($registry->kind($props['nav_search_on_submit']))->toBeNull();
});

it('leaves the search submit handler off the root when none is set', function () {
    expect(NativeRootTabs::make()->toArray(new CallbackRegistry)['props'] ?? [])
        ->not->toHaveKey('nav_search_on_submit');
});

it('wires a screen that overrides onSearchSubmit to the search tab', function () {
    $tree = Native::test(SearchTabScreen::class, layout: SearchTabsLayout::class)->tree();
    $props = $tree['props'];

    expect($tree['type'])->toBe('native_root_tabs')
        ->and($props)->toHaveKeys(['nav_search_on_query', 'nav_search_on_submit'])
        ->and($props['nav_search_on_submit'])->not->toBe($props['nav_search_on_query']);
});

it('leaves search submit unwired on a screen without onSearchSubmit', function () {
    $tree = Native::test(ChromeScreen::class, layout: SearchTabsLayout::class)->tree();

    expect($tree['type'])->toBe('native_root_tabs')
        ->and($tree['props'])->not->toHaveKey('nav_search_on_submit');
});

it('navigates from the search submit, as the runloop would on the SUBMIT event', function () {
    Native::test(SearchTabScreen::class, layout: SearchTabsLayout::class)
        ->submitSearch('winter')
        ->assertNavigatedTo('/search?q=winter');
});
