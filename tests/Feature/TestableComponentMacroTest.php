<?php

use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Fixtures\Edge\CounterScreen;

/**
 * Component-level test macros.
 *
 * Plugins register element-specific vocabulary on the harness — a date
 * plugin's pickDate(), a chart plugin's assertSeries(). FakeBridge macros
 * can't serve those: the bridge holds no reference back to the component,
 * so a macro there can read what was *called on the bridge* but can't read
 * the rendered tree or fire an event at a node.
 *
 * The registration is subtle enough to be worth pinning: TestableComponent
 * defines its own __call for FakeBridge forwarding, and a class method beats
 * a trait method, so Macroable's __call is aliased and dispatched explicitly.
 * Drop the alias and every one of these silently stops resolving — which is
 * exactly the kind of regression a later refactor introduces without noticing.
 */
beforeEach(function () {
    NativeRouter::clearRoutes();
    TestableComponent::flushMacros();
    FakeBridge::flushMacros();
});

afterEach(function () {
    NativeRouter::clearRoutes();
    TestableComponent::flushMacros();
    FakeBridge::flushMacros();
});

it('resolves a macro registered on the component', function () {
    TestableComponent::macro('assertCountIs', function (int $expected) {
        expect($this->get('count'))->toBe($expected);

        return $this;
    });

    Native::test(CounterScreen::class)->assertCountIs(0);
});

it('binds $this to the component, so a macro can drive it', function () {
    // The whole point: a component macro reaches the component's own API
    // (interactions, state, the rendered tree) — not the bridge's.
    TestableComponent::macro('incrementTwice', function () {
        return $this->tap('Increment')->tap('Increment');
    });

    Native::test(CounterScreen::class)
        ->incrementTwice()
        ->assertSet('count', 2)
        ->assertSee('Count: 2');
});

it('keeps the chain on the component when a macro returns $this', function () {
    TestableComponent::macro('noop', fn () => $this);

    $chained = Native::test(CounterScreen::class)->noop();

    expect($chained)->toBeInstanceOf(TestableComponent::class);
});

it('still forwards unknown methods to FakeBridge macros', function () {
    // The pre-existing extension point (how the camera plugin works) must
    // keep working — component macros are additive, not a replacement.
    FakeBridge::macro('assertBridgeReachable', function () {
        return $this;
    });

    $chained = Native::test(CounterScreen::class)->assertBridgeReachable();

    // Bridge macros that answer fluently hand the chain back to the component.
    expect($chained)->toBeInstanceOf(TestableComponent::class);
});

it('prefers a component macro over a bridge macro of the same name', function () {
    FakeBridge::macro('whoAmI', fn () => 'bridge');
    TestableComponent::macro('whoAmI', fn () => 'component');

    expect(Native::test(CounterScreen::class)->whoAmI())->toBe('component');
});

it('still throws for a method neither the component nor the bridge knows', function () {
    Native::test(CounterScreen::class)->totallyUnknownMethod();
})->throws(BadMethodCallException::class);
