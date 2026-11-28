<?php

use Native\Mobile\Attributes\Lazy;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\Transition;

/*
 * A #[Lazy] screen's placeholder is its first frame. The router holds a push's
 * transition until the new screen's first frame, so publishing the placeholder
 * has to release it, as the render loop does for an ordinary first frame.
 */

#[Lazy]
class LazyPlaceholderTransitionScreen extends NativeComponent
{
    public bool $mounted = false;

    public function mount(): void
    {
        $this->mounted = true;
    }

    protected function placeholder(): Element
    {
        return Column::make();
    }
}

class EagerPlaceholderTransitionScreen extends NativeComponent {}

class LazyPlaceholderTransitionRouter extends NativeRouter
{
    public function holdTransition(Transition $transition): void
    {
        $this->deferredTransition = $transition;
    }

    public function heldTransition(): ?Transition
    {
        return $this->deferredTransition;
    }

    public function make(string $class): NativeComponent
    {
        return $this->createComponent($class);
    }
}

it('releases the held transition when a lazy screen publishes its placeholder', function () {
    $router = new LazyPlaceholderTransitionRouter;
    $router->holdTransition(Transition::SlideFromRight);

    $screen = $router->make(LazyPlaceholderTransitionScreen::class);
    $screen->publishPlaceholder();

    expect($router->heldTransition())->toBeNull()
        ->and($screen->mounted)->toBeFalse();
});

it('leaves the transition held for a screen that is not lazy', function () {
    $router = new LazyPlaceholderTransitionRouter;
    $router->holdTransition(Transition::SlideFromRight);

    $router->make(EagerPlaceholderTransitionScreen::class)->publishPlaceholder();

    expect($router->heldTransition())->toBe(Transition::SlideFromRight);
});
