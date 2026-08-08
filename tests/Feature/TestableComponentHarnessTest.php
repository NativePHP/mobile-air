<?php

use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Fixtures\Edge\CounterScreen;

class InstrumentedTestableComponent extends TestableComponent {}

beforeEach(function (): void {
    NativeRouter::clearRoutes();
    TestableComponent::useHarness(null);
});

afterEach(function (): void {
    NativeRouter::clearRoutes();
    TestableComponent::useHarness(null);
});

it('uses a registered harness for component and route test entry points', function (): void {
    NativeRouter::register('/counter', CounterScreen::class);
    TestableComponent::useHarness(InstrumentedTestableComponent::class);

    expect(Native::test(CounterScreen::class))
        ->toBeInstanceOf(InstrumentedTestableComponent::class)
        ->and(Native::visit('/counter'))
        ->toBeInstanceOf(InstrumentedTestableComponent::class);
});

it('falls back to the built-in harness for an invalid registration', function (): void {
    TestableComponent::useHarness(stdClass::class);

    expect(Native::test(CounterScreen::class))
        ->toBeInstanceOf(TestableComponent::class)
        ->not->toBeInstanceOf(InstrumentedTestableComponent::class);
});
