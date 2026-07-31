<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Image;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Elements\TopBarTitle;
use Native\Mobile\Edge\Layouts\Builders\NavBar;

/**
 * `NavBar::titleView()` / `logo()` — a custom element (logo, wordmark lockup)
 * rendered in the nav bar's centered principal slot in place of the string
 * title. The native-chrome renderers pluck a `top_bar_title` marker out of the
 * `NativeRootStack` / `NativeRootTabs` children; these cover the PHP half
 * (builder + marker) that feeds them.
 */
it('builds an Image titleView from the logo() sugar, sized to a nav height', function () {
    $navBar = NavBar::make()->logo('images/jump-logo.png', 30);

    $view = $navBar->getTitleView();
    expect($view)->toBeInstanceOf(Image::class);

    $node = $view->toArray(new CallbackRegistry);
    expect($node['type'])->toBe('image');
    expect($node['props']['src'])->toBe('images/jump-logo.png');
    expect($node['layout']['height'])->toBe(30.0);
});

it('accepts an arbitrary element tree as the title view', function () {
    $navBar = NavBar::make()->titleView(Text::make('Jump'));

    expect($navBar->getTitleView())->toBeInstanceOf(Text::class);
});

it('has no title view for a plain string title (additive — no regression)', function () {
    expect(NavBar::make()->title('Jump')->getTitleView())->toBeNull();
});

it('serializes the TopBarTitle marker with its content as a child', function () {
    $wrapper = TopBarTitle::make();
    $wrapper->addChild(Image::make('logo.png'));

    $node = $wrapper->toArray(new CallbackRegistry);

    expect($node['type'])->toBe('top_bar_title');
    expect($node['children'][0]['type'])->toBe('image');
});
