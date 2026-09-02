<?php

use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\NestedPollAndOnChild;
use Tests\Fixtures\Edge\NestedPollAndOnScreen;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    ComponentRegistry::reset();
    ComponentRegistry::components([
        'nested-poll-and-on-child' => NestedPollAndOnChild::class,
    ]);
});

afterEach(function () {
    ComponentRegistry::reset();
});

function callPrivate(NativeComponent $component, string $method): mixed
{
    return Closure::bind(fn () => $this->{$method}(), $component, NativeComponent::class)();
}

/** Live child instances of a component, keyed by identity. */
function childrenOf(NativeComponent $component): array
{
    return (fn () => $this->nativeChildComponents)->call($component);
}

it('recurses a child #[Poll] method when the host runs due polls', function () {
    $screen = Native::test(NestedPollAndOnScreen::class);
    $host = $screen->instance();
    $children = childrenOf($host);
    $child = $children[array_key_first($children)];

    Closure::bind(function () use ($child) {
        $this->pollDefinitions = null;
        foreach ($child->pollDefinitions() as $i => $def) {
            $child->pollDefinitions[$i]['next'] = 0;
        }
    }, $host, NativeComponent::class)();

    callPrivate($host, 'runDuePolls');

    expect($child->ticks)->toBe(1);
});

it('aggregates child #[Poll] deadlines into the host timeout', function () {
    $screen = Native::test(NestedPollAndOnScreen::class);

    $timeout = callPrivate($screen->instance(), 'nextEventTimeout');

    expect($timeout)->toBeGreaterThan(0);
});

it('delivers a native event to a child #[On] listener', function () {
    $screen = Native::test(NestedPollAndOnScreen::class)
        ->emitNative('PingReceived', ['message' => 'hello-from-test']);

    $children = childrenOf($screen->instance());

    expect($children[array_key_first($children)]->pings)->toBe(['hello-from-test']);
});
