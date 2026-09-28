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

function nestedPollAndOnCall(NativeComponent $component, string $method): mixed
{
    return Closure::bind(fn () => $this->{$method}(), $component, NativeComponent::class)();
}

function nestedPollAndOnChild(NativeComponent $host): NestedPollAndOnChild
{
    $children = (fn () => $this->nativeChildComponents)->call($host);

    return $children[array_key_first($children)];
}

it('runs a child #[Poll] method when the host runs due polls', function () {
    $host = Native::test(NestedPollAndOnScreen::class)->instance();
    $child = nestedPollAndOnChild($host);

    Closure::bind(function () {
        foreach ($this->pollDefinitions() as $i => $def) {
            $this->pollDefinitions[$i]['next'] = 0;
        }
    }, $child, NativeComponent::class)();

    nestedPollAndOnCall($host, 'runDuePolls');

    expect($child->ticks)->toBe(1);
});

it('counts child #[Poll] deadlines in the host timeout', function () {
    $host = Native::test(NestedPollAndOnScreen::class)->instance();

    expect(nestedPollAndOnCall($host, 'nextEventTimeout'))->toBeGreaterThan(0);
});

it('delivers a native event to a child #[On] listener', function () {
    $screen = Native::test(NestedPollAndOnScreen::class)
        ->emitNative('PingReceived', ['message' => 'hello-from-test']);

    expect(nestedPollAndOnChild($screen->instance())->pings)->toBe(['hello-from-test']);
});
