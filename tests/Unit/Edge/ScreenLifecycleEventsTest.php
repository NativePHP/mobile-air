<?php

use Illuminate\Support\Facades\Event;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Events\Screen\ScreenMounted;
use Native\Mobile\Events\Screen\ScreenResumed;
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
    public bool $mounted = false;

    public bool $resumed = false;

    public bool $unmounted = false;

    public function mount(): void
    {
        $this->mounted = true;
    }

    public function onResume(): void
    {
        $this->resumed = true;
    }

    public function unmount(): void
    {
        $this->unmounted = true;
    }
}

class ExposedRouter extends NativeRouter
{
    public function mountAndAnnounce(NativeComponent $component, ?string $uri): void
    {
        $this->mountComponent($component, $uri);
    }

    public function resumeAndAnnounce(NativeComponent $component, ?string $uri): void
    {
        $this->resumeComponent($component, $uri);
    }

    public function unmountAndAnnounce(NativeComponent $component): void
    {
        $this->unmountComponent($component);
    }
}

it('announces the exact screen mounted and resumed by the navigation loop', function () {
    Event::fake();
    $router = new ExposedRouter;
    $component = new LifecycleProbeScreen;

    $router->mountAndAnnounce($component, '/counter');
    $router->resumeAndAnnounce($component, '/counter');

    expect($component->mounted)->toBeTrue()
        ->and($component->resumed)->toBeTrue();

    Event::assertDispatched(
        ScreenMounted::class,
        fn (ScreenMounted $event): bool => $event->component === LifecycleProbeScreen::class
            && $event->uri === '/counter'
            && $event->componentId === spl_object_hash($component),
    );
    Event::assertDispatched(
        ScreenResumed::class,
        fn (ScreenResumed $event): bool => $event->component === LifecycleProbeScreen::class
            && $event->uri === '/counter'
            && $event->componentId === spl_object_hash($component),
    );
});

it('announces a screen leaving the stack', function () {
    Event::fake();

    (new ExposedRouter)->unmountAndAnnounce($component = new LifecycleProbeScreen);

    expect($component->unmounted)->toBeTrue();

    Event::assertDispatched(
        ScreenUnmounted::class,
        fn (ScreenUnmounted $event): bool => $event->component === LifecycleProbeScreen::class
            && $event->componentId === spl_object_hash($component),
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
