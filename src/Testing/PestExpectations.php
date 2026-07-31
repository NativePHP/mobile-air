<?php

namespace Native\Mobile\Testing;

/**
 * Optional Pest expectation sugar over TestableComponent, so harness
 * assertions compose with expect() chains:
 *
 *     expect(Native::test(Counter::class))
 *         ->toSee('Count: 0')
 *         ->toHaveSet('count', 0);
 *
 * Opt in from tests/Pest.php:
 *
 *     \Native\Mobile\Testing\PestExpectations::register();
 */
class PestExpectations
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered || ! function_exists('expect')) {
            return;
        }

        self::$registered = true;

        expect()->extend('toSee', function (string $text) {
            /** @var TestableComponent $screen */
            $screen = $this->value;
            $screen->assertSee($text);

            return $this;
        });

        expect()->extend('toNotSee', function (string $text) {
            $this->value->assertDontSee($text);

            return $this;
        });

        expect()->extend('toHaveSet', function (string $property, mixed $value) {
            $this->value->assertSet($property, $value);

            return $this;
        });

        expect()->extend('toHaveNavigatedTo', function (string $uri) {
            $this->value->assertNavigatedTo($uri);

            return $this;
        });

        expect()->extend('toHaveElement', function (string $type, ?callable $matcher = null) {
            $this->value->assertElement($type, $matcher);

            return $this;
        });

        expect()->extend('toBeOnScreen', function (string $componentClass) {
            $this->value->assertScreen($componentClass);

            return $this;
        });

        expect()->extend('toBeAccessible', function () {
            $this->value->assertAccessible();

            return $this;
        });
    }
}
