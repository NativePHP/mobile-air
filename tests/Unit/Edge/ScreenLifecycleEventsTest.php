<?php

use Illuminate\Support\Facades\Event;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Events\Screen\ScreenUnmounted;

/*
 * The lifecycle events exist so cross-cutting observers — telemetry,
 * analytics, crash breadcrumbs — can follow the screen stack without
 * competing with the app for the mount()/onResume()/unmount() overrides.
 * The navigation loop itself needs the device bridge, so these pin the
 * seam the loop calls into.
 */

class LifecycleProbeScreen extends NativeComponent
{
    public bool $unmounted = false;

    public function unmount(): void
    {
        $this->unmounted = true;
    }
}

class ExposedRouter extends NativeRouter
{
    public function unmountAndAnnounce(NativeComponent $component): void
    {
        $this->unmountComponent($component);
    }
}

it('announces a screen leaving the stack', function () {
    Event::fake();

    (new ExposedRouter)->unmountAndAnnounce($component = new LifecycleProbeScreen);

    expect($component->unmounted)->toBeTrue();

    Event::assertDispatched(
        ScreenUnmounted::class,
        fn (ScreenUnmounted $event): bool => $event->component === LifecycleProbeScreen::class,
    );
});

it('does not let a failing listener take the navigation loop down', function () {
    Event::listen(ScreenUnmounted::class, function (): void {
        throw new RuntimeException('a listener misbehaved');
    });

    // An observer is not allowed to break the app it observes.
    (new ExposedRouter)->unmountAndAnnounce($component = new LifecycleProbeScreen);

    expect($component->unmounted)->toBeTrue();
});
