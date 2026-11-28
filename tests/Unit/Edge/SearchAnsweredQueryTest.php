<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\NativeRootTabs;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\AnsweredSearchScreen;
use Tests\Fixtures\Edge\AnsweredSearchTabsLayout;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

it('maps the answered query onto the native root', function () {
    $root = NativeRootTabs::make();
    $root->applyAttributes(['navSearchAnsweredQuery' => 'alp']);

    expect($root->toArray(new CallbackRegistry)['props']['nav_search_answered_query'])->toBe('alp');
});

it('publishes no answered query before the first search', function () {
    $tree = Native::test(AnsweredSearchScreen::class, layout: AnsweredSearchTabsLayout::class)->tree();

    expect($tree['type'])->toBe('native_root_tabs')
        ->and($tree['props'])->not->toHaveKey('nav_search_answered_query');
});

it('publishes the query its search items answer, even when there are none', function () {
    $screen = Native::test(AnsweredSearchScreen::class, layout: AnsweredSearchTabsLayout::class);

    expect($screen->search('alp')->tree()['props']['nav_search_answered_query'])->toBe('alp')
        ->and($screen->searchResults())->toBe([['title' => 'Alpha']]);

    // No results is an answer too: the renderers say "No Results" only for the answered query.
    expect($screen->search('zzz')->tree()['props']['nav_search_answered_query'])->toBe('zzz')
        ->and($screen->searchResults())->toBe([]);
});
