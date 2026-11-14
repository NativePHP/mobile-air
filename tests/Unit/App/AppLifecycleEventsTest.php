<?php

use Illuminate\Support\Facades\Event;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\App\AppBackgrounded;
use Native\Mobile\Events\App\AppForegrounded;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

it('marks both lifecycle events for global dispatch', function () {
    expect(is_subclass_of(AppBackgrounded::class, BroadcastsGlobally::class))->toBeTrue()
        ->and(is_subclass_of(AppForegrounded::class, BroadcastsGlobally::class))->toBeTrue();
});

it('rebuilds AppForegrounded from its native payload', function () {
    $comp = new class extends NativeComponent {};
    $build = new ReflectionMethod(NativeComponent::class, 'buildEventInstance');
    $build->setAccessible(true);

    $event = $build->invoke($comp, AppForegrounded::class, ['secondsAway' => 301]);

    expect($event)->toBeInstanceOf(AppForegrounded::class)
        ->and($event->secondsAway)->toBe(301);
});

it('rebuilds AppBackgrounded from its empty native payload', function () {
    $comp = new class extends NativeComponent {};
    $build = new ReflectionMethod(NativeComponent::class, 'buildEventInstance');
    $build->setAccessible(true);

    expect($build->invoke($comp, AppBackgrounded::class, []))->toBeInstanceOf(AppBackgrounded::class);
});

it('reaches app-wide listeners', function () {
    $comp = new class extends NativeComponent {};
    $dispatch = new ReflectionMethod(NativeComponent::class, 'dispatchGloballyIfMarked');
    $dispatch->setAccessible(true);

    $seen = [];
    Event::listen(AppBackgrounded::class, function () use (&$seen) {
        $seen[] = 'backgrounded';
    });
    Event::listen(AppForegrounded::class, function (AppForegrounded $e) use (&$seen) {
        $seen[] = "foregrounded after {$e->secondsAway}s";
    });

    $dispatch->invoke($comp, AppBackgrounded::class, []);
    $dispatch->invoke($comp, AppForegrounded::class, ['secondsAway' => 42]);

    expect($seen)->toBe(['backgrounded', 'foregrounded after 42s']);
});

it('passes the time away to a screen\'s #[On] handler', function () {
    $comp = new class extends NativeComponent
    {
        public ?int $secondsAway = null;

        #[On(AppForegrounded::class)]
        public function foregrounded(int $secondsAway): void
        {
            $this->secondsAway = $secondsAway;
        }
    };

    $register = new ReflectionMethod(NativeComponent::class, 'registerNativeEventListeners');
    $register->setAccessible(true);
    $register->invoke($comp);

    $dispatch = new ReflectionMethod(NativeComponent::class, 'dispatchNativeEvent');
    $dispatch->setAccessible(true);
    $dispatch->invoke($comp, [
        'event' => AppForegrounded::class,
        'payload' => ['secondsAway' => 900],
    ]);

    expect($comp->secondsAway)->toBe(900);
});
