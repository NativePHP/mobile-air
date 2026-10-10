<?php

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Concerns\BroadcastsGlobally as OldMarker;
use Native\Mobile\Events\GlobalEventDispatcher as OldDispatcher;
use Native\Mobile\Support\GlobalEventTransport;
use Native\Mobile\Support\Lane;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use SupaNative\Core\Contracts\BroadcastsGlobally;
use SupaNative\Core\Support\GlobalEventDispatcher;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Edge\GlobalEventsScreen;
use Tests\Fixtures\Edge\OrderArchived;
use Tests\Fixtures\Edge\OrderReturned;
use Tests\Fixtures\Edge\OrderShipped;

// The marker and the dispatcher live in supanative/core. The old name of
// the marker is an interface of this package that extends core's, and
// the old name of the dispatcher is an alias of the class in core.

beforeEach(function () {
    Lane::reset();
    GlobalEventTransport::reset();
    NativeComponent::restoreActive(null);
});

dataset('carrying the old name', [
    'an event that names the old name' => [fn () => new OrderShipped(42, 'shipped')],
    'an event that names both names' => [fn () => new OrderReturned(42)],
]);

/**
 * Listen for the marker under both of its names, by name
 * and with a typed closure, and answer the list which
 * each listener adds its own line to when it runs.
 */
function listenUnderBothNames(): ArrayObject
{
    $heard = new ArrayObject;

    Event::listen(OldMarker::class, fn () => $heard->append('the old name'));
    Event::listen(BroadcastsGlobally::class, fn () => $heard->append('the name in core'));
    Event::listen(fn (OldMarker $event) => $heard->append('a closure typed with the old name'));
    Event::listen(fn (BroadcastsGlobally $event) => $heard->append('a closure typed with the name in core'));

    return $heard;
}

/**
 * Run PHP in a process of its own, with the autoloader of this package loaded,
 * and answer what the code returned. Which name is used first is what these
 * tests are about, and a process that ran other tests has used them all.
 */
function inFreshProcess(string $code): mixed
{
    $autoload = var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true);

    $process = new Process([PHP_BINARY, '-r', "require {$autoload}; echo json_encode((function () { {$code} })());"]);
    $process->mustRun();

    return json_decode($process->getOutput(), true);
}

// ── The marker ──────────────────────────────────────

it('keeps the old name of the marker as an interface of its own, which extends the one in core', function () {
    $oldName = new ReflectionClass(OldMarker::class);

    expect($oldName->getName())->toBe('Native\Mobile\Events\Concerns\BroadcastsGlobally')
        ->and($oldName->isInterface())->toBeTrue()
        ->and($oldName->getInterfaceNames())->toBe([BroadcastsGlobally::class])
        ->and(is_subclass_of(OldMarker::class, BroadcastsGlobally::class))->toBeTrue()
        ->and(str_replace('\\', '/', $oldName->getFileName()))->toEndWith('/src/Events/Concerns/BroadcastsGlobally.php');
});

it('passes every check under the old name and under the name in core, for an event that carries the old name', function (object $event) {
    $byOldName = fn (OldMarker $event): OldMarker => $event;
    $byNameInCore = fn (BroadcastsGlobally $event): BroadcastsGlobally => $event;

    expect($event instanceof OldMarker)->toBeTrue()
        ->and($event instanceof BroadcastsGlobally)->toBeTrue()
        ->and(is_subclass_of($event, OldMarker::class))->toBeTrue()
        ->and(is_subclass_of($event::class, BroadcastsGlobally::class))->toBeTrue()
        ->and($byOldName($event))->toBe($event)
        ->and($byNameInCore($event))->toBe($event)
        ->and(array_values(class_implements($event)))->toEqualCanonicalizing([OldMarker::class, BroadcastsGlobally::class]);
})->with('carrying the old name');

it('takes an event that names the name in core alone as marked, and as no instance of the old name', function () {
    $event = new OrderArchived(42);

    $byNameInCore = fn (BroadcastsGlobally $event): BroadcastsGlobally => $event;
    $parameterOfOldName = fn (OldMarker $event) => $event;
    $returnOfOldName = fn (object $event): OldMarker => $event;

    expect($event instanceof BroadcastsGlobally)->toBeTrue()
        ->and(is_subclass_of($event::class, BroadcastsGlobally::class))->toBeTrue()
        ->and($byNameInCore($event))->toBe($event)
        ->and(array_values(class_implements($event)))->toBe([BroadcastsGlobally::class]);

    // The old name extends core's and not the other way round, so an event that names core's alone is none of it.
    expect($event instanceof OldMarker)->toBeFalse()
        ->and(is_subclass_of($event, OldMarker::class))->toBeFalse()
        ->and(fn () => $parameterOfOldName($event))->toThrow(TypeError::class)
        ->and(fn () => $returnOfOldName($event))->toThrow(TypeError::class);
});

it('marks an event whose class names the old name, when that class is the first to use either name', function () {
    $marked = inFreshProcess(<<<'PHP'
        $event = new Tests\Fixtures\Edge\OrderShipped(42, 'shipped');

        $interfaces = array_values(class_implements($event));
        sort($interfaces);

        return [
            'by the name in core' => $event instanceof SupaNative\Core\Contracts\BroadcastsGlobally,
            'by the old name' => $event instanceof Native\Mobile\Events\Concerns\BroadcastsGlobally,
            'interfaces' => $interfaces,
        ];
    PHP);

    expect($marked)->toBe([
        'by the name in core' => true,
        'by the old name' => true,
        'interfaces' => [OldMarker::class, BroadcastsGlobally::class],
    ]);
});

// ── Listeners ───────────────────────────────────────

it('hears an event that carries the old name in a listener for either name', function (object $event) {
    $heard = listenUnderBothNames();

    event($event);

    expect($heard->getArrayCopy())->toEqualCanonicalizing([
        'the old name',
        'the name in core',
        'a closure typed with the old name',
        'a closure typed with the name in core',
    ]);
})->with('carrying the old name');

it('hears an event that names the name in core alone in a listener for that name, and in none for the old name', function () {
    $heard = listenUnderBothNames();

    event(new OrderArchived(42));

    // Laravel finds the listeners of an event by the interfaces of its class, and the old name is not among these.
    expect($heard->getArrayCopy())->toEqualCanonicalizing([
        'the name in core',
        'a closure typed with the name in core',
    ]);
});

it('hears a listener for the old name that was registered before the dispatcher was installed', function () {
    // An app as Laravel makes it, and a provider that listens while it registers, before this package does.
    app()->instance('events', $original = new Dispatcher(app()));
    Event::clearResolvedInstance('events');

    $heard = [];
    $original->listen(OldMarker::class, function () use (&$heard) {
        $heard[] = 'the old name';
    });
    $original->listen(function (OldMarker $event) use (&$heard) {
        $heard[] = 'a closure typed with the old name';
    });

    GlobalEventDispatcher::install(app());

    event(new OrderShipped(42, 'shipped'));

    expect(app('events'))->toBeInstanceOf(GlobalEventDispatcher::class)
        ->and($heard)->toBe(['the old name', 'a closure typed with the old name']);
});

it('hears a listener for the old name under a dispatcher of another class that stays', function () {
    $custom = new class(app()) extends Dispatcher {};
    app()->instance('events', $custom);
    Event::clearResolvedInstance('events');

    GlobalEventDispatcher::install(app());

    $heard = listenUnderBothNames();

    event(new OrderShipped(42, 'shipped'));

    expect(app('events'))->toBe($custom)
        ->and($heard->getArrayCopy())->toEqualCanonicalizing([
            'the old name',
            'the name in core',
            'a closure typed with the old name',
            'a closure typed with the name in core',
        ]);
});

// ── Screens and lanes ───────────────────────────────

it('carries an event that names the name in core alone both ways, as one that names the old name', function () {
    $heard = 0;
    Event::listen(OrderArchived::class, function () use (&$heard) {
        $heard++;
    });

    $screen = Native::test(GlobalEventsScreen::class);

    $screen->instance()->registerNativeEventListener(OrderArchived::class, function ($event) {
        $this->log[] = "archived:{$event->orderId}";
    });

    // From PHP to the screen.
    event(new OrderArchived(7));

    $screen->assertSet('log', ['archived:7']);

    expect($heard)->toBe(1);

    // From the device to Laravel.
    $screen->emitNative(OrderArchived::class, ['orderId' => 8])
        ->assertSet('log', ['archived:7', 'archived:8']);

    expect($heard)->toBe(2);
});

it('sends an event that names the name in core alone to the main lane, which takes it as a marked one', function () {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    app()->forgetInstance('encrypter');

    FakeBridge::enable()->respondTo('Event.Broadcast', ['success' => true, 'delivered' => true]);

    // A lane without a screen sends the event, and runs no listener for it.
    expect(Lane::background(fn () => event(new OrderArchived(7))))->toBe([]);

    $posted = FakeBridge::current()->callsTo('Event.Broadcast');

    expect($posted)->toHaveCount(1);

    Lane::reset();

    $screen = Native::test(GlobalEventsScreen::class);

    $screen->instance()->registerNativeEventListener(OrderArchived::class, function ($event) {
        $this->log[] = "archived:{$event->orderId}";
    });

    // The main lane only takes an event out of its frame when that event carries the marker.
    $screen->emitNative(GlobalEventTransport::EVENT_NAME, $posted[0]['params']['payload'])
        ->assertSet('log', ['archived:7']);
});

// ── The dispatcher ──────────────────────────────────

it('has one class behind the old name of the dispatcher and the name in core', function () {
    expect((new ReflectionClass(OldDispatcher::class))->getName())->toBe(GlobalEventDispatcher::class)
        ->and(app('events'))->toBeInstanceOf(OldDispatcher::class)
        ->and(app('events'))->toBeInstanceOf(GlobalEventDispatcher::class);
});

it('resolves the old name of the dispatcher when it is the first name of this package to be used', function () {
    $resolved = inFreshProcess(<<<'PHP'
        $event = new Tests\Fixtures\Edge\OrderArchived(42);

        // The static call is what loads the old name, as the controller for device events makes it.
        Native\Mobile\Events\GlobalEventDispatcher::markFromNative($event);

        return (new ReflectionClass('Native\Mobile\Events\GlobalEventDispatcher'))->getName();
    PHP);

    expect($resolved)->toBe(GlobalEventDispatcher::class);
});
