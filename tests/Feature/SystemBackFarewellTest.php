<?php

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NavigationIntent;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\DetailScreen;

/**
 * back() publishes one farewell frame so a PHP-initiated pop has fresh
 * content to show while the native side animates it. A system back
 * (hardware button, back chevron, edge-swipe pop) is the opposite case:
 * native navigation may already have removed the departing screen and
 * shrunk its path, so its farewell frame can arrive as an unknown URI —
 * reconciled as a brand-new push. These tests pin the split: farewell for
 * PHP-initiated backs, none when answering a system back.
 */
function farewellScreen(): NativeComponent
{
    return new class extends NativeComponent
    {
        public int $farewellFrames = 0;

        public function render(): Element
        {
            return Column::make();
        }

        protected function publishFinalState(): void
        {
            $this->farewellFrames++;
        }
    };
}

it('publishes a farewell frame for a PHP-initiated back', function () {
    $screen = farewellScreen();

    $screen->back();

    expect($screen->getNavigationIntent()?->type)->toBe(NavigationIntent::BACK)
        ->and($screen->farewellFrames)->toBe(1);
});

it('skips the farewell frame when answering a system back', function () {
    $screen = farewellScreen();

    $screen->handleSystemBack();

    expect($screen->getNavigationIntent()?->type)->toBe(NavigationIntent::BACK)
        ->and($screen->farewellFrames)->toBe(0);
});

it('resets the system-back flag when the back handler throws', function () {
    $screen = new class extends NativeComponent
    {
        public int $farewellFrames = 0;

        public function render(): Element
        {
            return Column::make();
        }

        protected function publishFinalState(): void
        {
            $this->farewellFrames++;
        }

        public function onBackPressed(): void
        {
            throw new RuntimeException('boom');
        }
    };

    try {
        $screen->handleSystemBack();
    } catch (RuntimeException) {
        // expected
    }

    $screen->back();

    expect($screen->farewellFrames)->toBe(1);
});

it('skips the farewell frame for a harness back press', function () {
    $bridge = Native::fakeBridge();

    $screen = Native::test(DetailScreen::class);
    $mounted = count($bridge->publishes);

    $screen->pressBack()->assertWentBack();

    expect($bridge->publishes)->toHaveCount($mounted);
});

it('publishes a farewell frame for a PHP-initiated harness back', function () {
    $bridge = Native::fakeBridge();

    $screen = Native::test(DetailScreen::class);
    $mounted = count($bridge->publishes);

    $screen->tap('Go back')->assertWentBack();

    expect($bridge->publishes)->toHaveCount($mounted + 1);
});
