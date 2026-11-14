<?php

namespace Native\Mobile\Testing;

/**
 * Entry point for the native component testing suite — the NativePHP
 * equivalent of Livewire::test().
 *
 *     use Native\Mobile\Testing\Native;
 *
 *     Native::test(Counter::class)
 *         ->tap('Increment')
 *         ->assertSet('count', 1)
 *         ->assertSee('Count: 1');
 *
 *     Native::visit('/profile/5')
 *         ->assertSee('Profile');
 *
 *     Native::fakeBridge()->respondTo('Geolocation.GetCurrentPosition', [
 *         'latitude' => 40.7, 'longitude' => -74.0,
 *     ]);
 */
final class Native
{
    /** Mount and render a component class. */
    public static function test(string $componentClass, array $params = [], array $data = [], ?string $layout = null, ?string $platform = null): TestableComponent
    {
        return TestableComponent::test($componentClass, $params, $data, $layout, $platform);
    }

    /** Mount the component registered for a native route URI. */
    public static function visit(string $uri, array $data = [], ?string $platform = null): TestableComponent
    {
        return TestableComponent::visit($uri, $data, $platform);
    }

    /**
     * The FakeBridge for the current test — enable it up front to script
     * bridge responses before the component mounts.
     */
    public static function fakeBridge(): FakeBridge
    {
        return FakeBridge::enable();
    }
}
