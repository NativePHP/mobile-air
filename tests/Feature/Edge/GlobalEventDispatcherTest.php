<?php

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Support\GlobalEventRouter;
use Native\Mobile\Support\Lane;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use SupaNative\Core\Contracts\BroadcastsGlobally;
use SupaNative\Core\Contracts\RoutesGlobalEvents;
use SupaNative\Core\Platform;
use SupaNative\Core\Support\GlobalEventDispatcher;
use Tests\Fixtures\Edge\GlobalEventsScreen;
use Tests\Fixtures\Edge\OrderDelivered;
use Tests\Fixtures\Edge\OrderPacked;
use Tests\Fixtures\Edge\OrderShipped;

// The dispatcher of supanative/core, which asks the platform package two
// things through RoutesGlobalEvents. What mobile answers is tested in
// BroadcastsGloballyTest and in BroadcastsGloballyAcrossLanesTest.

beforeEach(function () {
    Lane::reset();
    NativeComponent::restoreActive(null);
});

afterEach(function () {
    Platform::set(null);
});

/**
 * Bind a platform object that sends every marked event, or none, and
 * that keeps what the dispatcher asked of it in order. A listener
 * of a test can add its own line to tell what ran before what.
 */
function routeGlobalEventsThrough(bool $sends): object
{
    $router = new class($sends) implements RoutesGlobalEvents
    {
        /** @var list<string> */
        public array $asked = [];

        public function __construct(private bool $sends) {}

        public function sendElsewhere(BroadcastsGlobally $event): bool
        {
            $this->asked[] = 'send';

            return $this->sends;
        }

        public function handToScreens(BroadcastsGlobally $event): void
        {
            $this->asked[] = 'hand';
        }
    };

    app()->instance(RoutesGlobalEvents::class, $router);

    return $router;
}

// ── The platform object ─────────────────────────────

it('binds the router of this package for the dispatcher to ask', function () {
    expect(app(RoutesGlobalEvents::class))->toBeInstanceOf(GlobalEventRouter::class)
        ->and(app(RoutesGlobalEvents::class))->toBe(app(RoutesGlobalEvents::class))
        ->and(app('events'))->toBeInstanceOf(GlobalEventDispatcher::class);
});

it('leaves the router to the desktop package on a desktop platform, and installs the dispatcher all the same', function (string $platform) {
    // The platform is declared before PHP starts, so the provider reads it as it registers.
    Platform::set($platform);

    $this->refreshApplication();

    expect(app()->bound(RoutesGlobalEvents::class))->toBeFalse()
        ->and(app('events'))->toBeInstanceOf(GlobalEventDispatcher::class);
})->with([Platform::MACOS, Platform::WINDOWS, Platform::LINUX]);

it('behaves as the dispatcher of Laravel while no platform has bound a router', function () {
    app()->offsetUnset(RoutesGlobalEvents::class);

    // Everything a router of this package would need to send an event from another lane.
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    app()->forgetInstance('encrypter');
    FakeBridge::enable()->respondTo('Event.Broadcast', ['success' => true, 'delivered' => true]);

    Log::spy();

    Event::listen(OrderShipped::class, fn () => 'noted');

    // On another lane the listeners run where the event was fired, and nothing is sent.
    expect(Lane::background(fn () => event(new OrderShipped(42, 'shipped'))))->toContain('noted')
        ->and(Lane::background(fn () => Event::until(new OrderShipped(42, 'shipped'))))->toBe('noted')
        ->and(FakeBridge::current()->callsTo('Event.Broadcast'))->toBe([]);

    // On the main lane the live screen is handed nothing.
    $screen = Native::test(GlobalEventsScreen::class);

    expect(event(new OrderShipped(42, 'shipped')))->toContain('noted');

    $screen->assertSet('log', [])->assertRenderCount(1);

    Log::shouldNotHaveReceived('warning');
});

it('runs no listener for a marked event that the platform has sent, and answers as if nobody listened', function () {
    $router = routeGlobalEventsThrough(sends: true);

    Event::listen(OrderShipped::class, function () use ($router) {
        $router->asked[] = 'listener';

        return 'noted';
    });

    expect(event(new OrderShipped(42, 'shipped')))->toBe([])
        ->and(Event::until(new OrderShipped(42, 'shipped')))->toBeNull()
        ->and($router->asked)->toBe(['send', 'send']);
});

it('keeps a marked event and its listeners here when the platform did not send it, and hands it to the screens after them', function () {
    $router = routeGlobalEventsThrough(sends: false);

    Event::listen(OrderShipped::class, function () use ($router) {
        $router->asked[] = 'listener';

        return 'noted';
    });

    expect(event(new OrderShipped(42, 'shipped')))->toContain('noted')
        ->and($router->asked)->toBe(['send', 'listener', 'hand']);

    $router->asked = [];

    expect(Event::until(new OrderShipped(42, 'shipped')))->toBe('noted')
        ->and($router->asked)->toBe(['send', 'listener', 'hand']);
});

it('asks the platform about no event but a marked one that PHP fired', function (Closure $fire) {
    $router = routeGlobalEventsThrough(sends: true);

    $heard = 0;
    Event::listen([OrderShipped::class, OrderPacked::class, 'order.shipped'], function () use (&$heard) {
        $heard++;
    });

    $fire();

    expect($router->asked)->toBe([])
        ->and($heard)->toBe(1);
})->with([
    'an event without the marker' => [fn () => event(new OrderPacked(42))],
    'a string event that carries a marked object' => [fn () => event('order.shipped', new OrderShipped(42, 'shipped'))],
    'a marked event that was built from a native payload' => [function () {
        GlobalEventDispatcher::markFromNative($event = new OrderShipped(42, 'shipped'));

        event($event);
    }],
]);

it('asks for the router at each marked event, so that one bound later is the one that answers', function () {
    app()->offsetUnset(RoutesGlobalEvents::class);

    Event::listen(OrderShipped::class, fn () => 'noted');

    expect(event(new OrderShipped(42, 'shipped')))->toContain('noted');

    $router = routeGlobalEventsThrough(sends: true);

    expect(event(new OrderShipped(42, 'shipped')))->toBe([])
        ->and($router->asked)->toBe(['send']);
});

it('keeps a listener under the name it was given, also when that is the name of a loaded class in other letters', function (string $name) {
    $heard = 0;
    Event::listen($name, function () use (&$heard) {
        $heard++;
    });

    event($name);

    // The dispatcher of Laravel looks a string event up under the very name that it was fired with.
    expect(app('events'))->toBeInstanceOf(GlobalEventDispatcher::class)
        ->and(class_exists($name, false))->toBeTrue()
        ->and($heard)->toBe(1)
        ->and(Event::getRawListeners())->toHaveKey($name);

    // A dispatcher without the wildcard listeners of the app, which listen for every name.
    $events = new GlobalEventDispatcher(new Container);
    $events->listen($name, fn () => 'noted');

    expect($events->hasListeners($name))->toBeTrue()
        ->and($events->dispatch($name))->toBe(['noted']);

    $events->forget($name);

    expect($events->hasListeners($name))->toBeFalse()
        ->and($events->dispatch($name))->toBe([]);
})->with(['error', 'exception', 'closure']);

// ── Installing ──────────────────────────────────────

it('is installed once, however many packages install it', function () {
    // An app as Laravel makes it, before any package has registered.
    app()->instance('events', $original = new Dispatcher(app()));
    Event::clearResolvedInstance('events');

    Log::spy();

    GlobalEventDispatcher::install(app());

    $installed = app('events');

    GlobalEventDispatcher::install(app());

    $heard = 0;
    Event::listen(OrderPacked::class, function () use (&$heard) {
        $heard++;
    });

    event(new OrderPacked(42));

    // The second call found the first one's dispatcher in place, and left it there.
    expect($installed::class)->toBe(GlobalEventDispatcher::class)
        ->and(app('events'))->toBe($installed)
        ->and(Event::getFacadeRoot())->toBe($installed)
        ->and($heard)->toBe(1)
        ->and($original->hasListeners(OrderPacked::class))->toBeTrue();

    Log::shouldNotHaveReceived('warning');
});

it('makes the facade let go of the dispatcher that it has kept, so that it hands out the one that was installed', function () {
    // The facade resolves the dispatcher of the app once, and keeps it from then on.
    $kept = Event::getFacadeRoot();

    // An app as Laravel makes it. Nothing tells the facade that the app has another dispatcher by now.
    app()->instance('events', new Dispatcher(app()));

    expect(Event::getFacadeRoot())->toBe($kept);

    GlobalEventDispatcher::install(app());

    expect(Event::getFacadeRoot())->toBe(app('events'))
        ->and(Event::getFacadeRoot())->not->toBe($kept);
});

it('shares every property that the dispatcher of Laravel declares with the one that replaces it', function () {
    $original = new Dispatcher(new Container);
    $replacement = GlobalEventDispatcher::replacing($original);

    // The id of the reference that a property holds. Two objects that share a property have the same one.
    $reference = function (object $dispatcher, ReflectionProperty $property): ?string {
        $key = match (true) {
            $property->isPrivate() => "\0".$property->getDeclaringClass()->getName()."\0".$property->getName(),
            $property->isProtected() => "\0*\0".$property->getName(),
            default => $property->getName(),
        };

        return ReflectionReference::fromArrayElement((array) $dispatcher, $key)?->getId();
    };

    $declared = array_filter(
        (new ReflectionClass(Dispatcher::class))->getProperties(),
        fn (ReflectionProperty $property) => ! $property->isStatic(),
    );

    // A property that is not shared, as a private one of a later Laravel would be, has no reference at all.
    $unshared = array_filter(
        $declared,
        fn (ReflectionProperty $property) => $reference($replacement, $property) === null
            || $reference($replacement, $property) !== $reference($original, $property),
    );

    expect($declared)->not->toBeEmpty()
        ->and(array_map(fn (ReflectionProperty $property) => $property->getName(), $unshared))->toBe([]);
});

it('keeps the dispatcher of a Laravel that has no invokeListeners(), and warns that marked events stay off screens', function () {
    Log::spy();

    app()->instance('events', $original = new Dispatcher(app()));
    Event::clearResolvedInstance('events');

    // The dispatcher as it finds a Laravel before 10.30, which ends its dispatch in no method of that name.
    $onOlderLaravel = new class extends GlobalEventDispatcher
    {
        protected static function hooksIntoLaravel(): bool
        {
            return false;
        }
    };

    $onOlderLaravel::install(app());

    expect(app('events'))->toBe($original);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'will not reach native screens')
            && str_contains($message, 'has no invokeListeners(), which came with 10.30'));
});

it('leaves a dispatcher of another class in place, and warns once however many packages install it', function () {
    Log::spy();

    $custom = new class(app()) extends Dispatcher {};
    app()->instance('events', $custom);
    Event::clearResolvedInstance('events');

    GlobalEventDispatcher::install(app());
    GlobalEventDispatcher::install(app());

    expect(app('events'))->toBe($custom);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'will not reach native screens')
            && str_contains($message, "only Laravel's own can be replaced"));
});

it('warns about a dispatcher of another class when the app has booted, and not before', function () {
    Log::spy();

    // An app that two packages install the dispatcher in while they register, as mobile and desktop do.
    $app = new Application(sys_get_temp_dir());
    Container::setInstance($this->app);

    $app->instance('events', $custom = new class($app) extends Dispatcher {});

    GlobalEventDispatcher::install($app);
    GlobalEventDispatcher::install($app);

    Log::shouldNotHaveReceived('warning');

    $app->boot();

    expect($app->make('events'))->toBe($custom);

    Log::shouldHaveReceived('warning')->once();
});

// ── What a screen asks ──────────────────────────────

it('says that Laravel listens for an event in an app it was never installed in, as nothing there can tell', function () {
    // Installed in this app, where no listener is registered for the event.
    expect(GlobalEventDispatcher::laravelListensFor(new OrderShipped(42, 'shipped')))->toBeFalse();

    $host = new Container;
    $host->instance('events', new Dispatcher($host));

    Container::setInstance($host);

    try {
        $listens = GlobalEventDispatcher::laravelListensFor(new OrderShipped(42, 'shipped'));
    } finally {
        Container::setInstance($this->app);
    }

    expect($listens)->toBeTrue();
});

it('answers for the dispatcher that is in place, also in a copy of the app that it was never installed in', function () {
    $event = new OrderShipped(42, 'shipped');

    // A copy of the app, as a server that keeps the app in memory makes one for each request.
    $copy = clone $this->app;

    Container::setInstance($copy);

    try {
        $withoutListener = GlobalEventDispatcher::laravelListensFor($event);

        $copy->make('events')->listen(OrderShipped::class, fn () => 'noted');

        $withListener = GlobalEventDispatcher::laravelListensFor($event);
    } finally {
        Container::setInstance($this->app);
    }

    expect($copy->make('events'))->toBeInstanceOf(GlobalEventDispatcher::class)
        ->and($withoutListener)->toBeFalse()
        ->and($withListener)->toBeTrue();
});

it('draws a frame after a marked event from the device that nothing heard, only for a host that never installed the dispatcher', function (bool $installed, int $frames) {
    if (! $installed) {
        // What a host is that never installed the dispatcher: it has Laravel's own, and no install is on record.
        Closure::bind(static function () {
            unset(static::$installedIn[app()]);
        }, null, GlobalEventDispatcher::class)();

        app()->instance('events', new Dispatcher(app()));
        Event::clearResolvedInstance('events');
    }

    // The screen listens for this event, and its filters take the orders 42 and 5 alone.
    Native::test(GlobalEventsScreen::class)
        ->emitNative(OrderDelivered::class, ['orderId' => 7])
        ->assertSet('log', [])
        ->assertRenderCount($frames);
})->with([
    'a host that installed it keeps the frame it has' => [true, 1],
    'a host that never installed it gets a frame' => [false, 2],
]);
