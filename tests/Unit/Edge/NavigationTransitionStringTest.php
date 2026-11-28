<?php

use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\Transition;

/*
 * navigate(), back() and replace() take a transition as a case or as its
 * backing string. The router turns a string into its case before holding it
 * for the next screen, where it is typed ?Transition.
 */

function routerTransitionOf(Transition|string|null $transition): ?Transition
{
    return (new ReflectionMethod(NativeRouter::class, 'transitionOf'))->invoke(null, $transition);
}

it('turns a transition given as a string into its case', function () {
    expect(routerTransitionOf('fade'))->toBe(Transition::Fade)
        ->and(routerTransitionOf('slide_from_bottom'))->toBe(Transition::SlideFromBottom);
});

it('passes a case or no transition through', function () {
    expect(routerTransitionOf(Transition::SlideFromLeft))->toBe(Transition::SlideFromLeft)
        ->and(routerTransitionOf(null))->toBeNull();
});

it('gives an unknown string no transition, so the navigation uses its default', function () {
    expect(routerTransitionOf('wobble'))->toBeNull();
});
