<?php

use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\NavigationIntent;
use Native\Mobile\Edge\Transition;
use Native\Mobile\Testing\FakeBridge;

/*
 * After a system back (the back button, an edge swipe), native navigation
 * already shows the screen below while PHP pops and resumes it. Taps made on
 * that screen meanwhile wait in the queue, and the reset at its first frame
 * must not drop them.
 */

function holdBackTransition(NativeRouter $router, Transition $transition, bool $fromSystemBack): void
{
    (new ReflectionProperty(NativeRouter::class, 'deferredTransition'))->setValue($router, $transition);
    (new ReflectionProperty(NativeRouter::class, 'deferredFromSystemBack'))->setValue($router, $fromSystemBack);
}

class SystemBackKeepsTapsScreen extends NativeComponent {}

afterEach(fn () => FakeBridge::disable());

it('keeps taps made on the screen below while it resumes from a system back', function () {
    $bridge = FakeBridge::enable();
    $router = new NativeRouter;
    holdBackTransition($router, Transition::SlideFromLeft, fromSystemBack: true);

    $completion = ['type' => 20, 'event' => 'native:App\\Events\\Something', 'payload' => []];
    $bridge->queueEvent(['type' => 1, 'callback_id' => 7]);
    $bridge->queueEvent($completion);
    $bridge->queueEvent(['type' => 1, 'callback_id' => 8]);

    $router->flushDeferredTransition();

    expect($router->takeCarriedEvent())->toBe(['type' => 1, 'callback_id' => 7])
        ->and($router->takeCarriedEvent())->toBe($completion)
        ->and($router->takeCarriedEvent())->toBe(['type' => 1, 'callback_id' => 8])
        ->and($router->takeCarriedEvent())->toBeNull()
        ->and($bridge->resetCount)->toBe(1);
});

it('keeps a second system back as the user going back again', function () {
    $bridge = FakeBridge::enable();
    $router = new NativeRouter;
    holdBackTransition($router, Transition::SlideFromLeft, fromSystemBack: true);

    $bridge->queueEvent(['type' => NativeComponent::EVENT_SYSTEM_BACK]);

    $router->flushDeferredTransition();

    expect($router->takeCarriedEvent())->toBe(['type' => NativeComponent::EVENT_SYSTEM_BACK])
        ->and($router->takeCarriedEvent())->toBeNull();
});

it('still drops the leaving screen\'s taps on a back PHP started', function () {
    $bridge = FakeBridge::enable();
    $router = new NativeRouter;
    holdBackTransition($router, Transition::SlideFromLeft, fromSystemBack: false);

    $bridge->queueEvent(['type' => 1, 'callback_id' => 7]);

    $router->flushDeferredTransition();

    expect($router->takeCarriedEvent())->toBeNull();
});

it('marks a back that answers a system back, and only that one', function () {
    FakeBridge::enable();

    $answering = new SystemBackKeepsTapsScreen;
    $answering->handleSystemBack();

    $asked = new SystemBackKeepsTapsScreen;
    $asked->back();

    expect($answering->getNavigationIntent()?->type)->toBe(NavigationIntent::BACK)
        ->and($answering->getNavigationIntent()?->fromSystemBack)->toBeTrue()
        ->and($asked->getNavigationIntent()?->fromSystemBack)->toBeFalse();
});
