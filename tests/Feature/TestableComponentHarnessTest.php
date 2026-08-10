<?php

use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Fixtures\Edge\CounterScreen;

class InstrumentedTestableComponent extends TestableComponent {}

abstract class AbstractTestableComponent extends TestableComponent {}

class WidestCompatibleHarness extends TestableComponent
{
    public function __construct(string $componentClass, array $params, array $data, ?string $layout, ?string $platform)
    {
        parent::__construct($componentClass, $params, $data, $layout, $platform);
    }
}

class TooManyRequiredParamsHarness extends TestableComponent
{
    public function __construct(string $componentClass, array $params, array $data, ?string $layout, ?string $platform, ?string $uri)
    {
        parent::__construct($componentClass, $params, $data, $layout, $platform, $uri);
    }
}

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

it('rejects an invalid harness registration', function (): void {
    TestableComponent::useHarness(stdClass::class);
})->throws(InvalidArgumentException::class);

it('rejects a harness that cannot be instantiated', function (): void {
    TestableComponent::useHarness(AbstractTestableComponent::class);
})->throws(InvalidArgumentException::class);

it('accepts a harness requiring every argument Native::test() passes', function (): void {
    NativeRouter::register('/counter', CounterScreen::class);
    TestableComponent::useHarness(WidestCompatibleHarness::class);

    expect(Native::test(CounterScreen::class))
        ->toBeInstanceOf(WidestCompatibleHarness::class)
        ->and(Native::visit('/counter'))
        ->toBeInstanceOf(WidestCompatibleHarness::class);
});

it('rejects a harness requiring more arguments than Native::test() passes', function (): void {
    TestableComponent::useHarness(TooManyRequiredParamsHarness::class);
})->throws(InvalidArgumentException::class);
