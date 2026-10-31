<?php

use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\TabReselectLayout;
use Tests\Fixtures\Edge\TabReselectScreen;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

it('delivers a reselect press and its arguments to the current component without navigating', function (string $platform) {
    Native::test(TabReselectScreen::class, layout: TabReselectLayout::class, platform: $platform)
        ->press("backToTop('latest')")
        ->assertSet('reselects', 1)
        ->assertSet('feed', 'latest')
        ->assertNoNavigation();
})->with(['ios', 'android']);

it('continues navigating when another bottom tab is pressed', function (string $platform) {
    Native::test(TabReselectScreen::class, layout: TabReselectLayout::class, platform: $platform)
        ->tap('Detail')
        ->assertSet('reselects', 0)
        ->assertReplacedWith('/detail/1');
})->with(['ios', 'android']);
