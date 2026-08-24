<?php

namespace Tests\Feature;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NavigationIntent;
use Tests\TestCase;

/**
 * back() publishes one farewell frame so a PHP-initiated pop has fresh
 * content to show while the native side animates it. A SYSTEM back
 * (hardware button, back chevron, edge-swipe pop) is the opposite case:
 * the native pop has already animated, the coordinator's path has already
 * shrunk, and a farewell frame of the departing screen arrives as an
 * unknown URI — reconciled as a brand-new push. The screen flashes back
 * in, then out again. These tests pin the split: farewell for
 * PHP-initiated backs, none when answering a system back.
 */
class SystemBackFarewellTest extends TestCase
{
    private function screen(): NativeComponent
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

    public function test_a_php_initiated_back_still_publishes_a_farewell_frame(): void
    {
        $screen = $this->screen();

        $screen->back();

        $this->assertSame(NavigationIntent::BACK, $screen->getNavigationIntent()?->type);
        $this->assertSame(1, $screen->farewellFrames);
    }

    public function test_answering_a_system_back_skips_the_farewell_frame(): void
    {
        $screen = $this->screen();

        $screen->handleSystemBack();

        $this->assertSame(NavigationIntent::BACK, $screen->getNavigationIntent()?->type);
        $this->assertSame(0, $screen->farewellFrames);
    }

    public function test_the_flag_resets_even_when_the_back_handler_throws(): void
    {
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
                throw new \RuntimeException('boom');
            }
        };

        try {
            $screen->handleSystemBack();
        } catch (\RuntimeException) {
            // expected
        }

        $screen->back();

        $this->assertSame(1, $screen->farewellFrames);
    }
}
