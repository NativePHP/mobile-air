<?php

use Illuminate\Support\Facades\Event;
use Native\Mobile\Device;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Async\AsyncTaskFailed;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Events\Device\ThermalStateChanged;
use Native\Mobile\Support\AsyncTaskRegistry;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Native\Mobile\ThermalState;
use Tests\Fixtures\Edge\FilteredListenersScreen;
use Tests\Fixtures\Edge\ListenersChild;
use Tests\Fixtures\Edge\ListenersHostScreen;
use Tests\Fixtures\Edge\OrderDelivered;
use Tests\Fixtures\Edge\PingReceived;
use Tests\Fixtures\Edge\ScriptedEventBridge;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    ComponentRegistry::reset();
    ComponentRegistry::components(['listeners-child' => ListenersChild::class]);

    Device::forgetThermalState();
});

afterEach(function () {
    ComponentRegistry::reset();
    Device::forgetThermalState();
});

// ── Matching ────────────────────────────────────────

it('runs a filtered listener for a payload that matches its filter, and renders once', function () {
    Native::test(FilteredListenersScreen::class)
        ->emitNative('OrderShipped', ['orderId' => 42, 'status' => 'sent'])
        ->assertSet('log', ['thisOrderShipped:sent'])
        ->assertSee('Log: thisOrderShipped:sent')
        ->assertRenderCount(2);
});

it('neither runs a filtered listener nor renders for a payload that does not match its filter', function (string $event, array $payload) {
    Native::test(FilteredListenersScreen::class)
        ->emitNative($event, $payload)
        ->assertSet('log', [])
        ->assertRenderCount(1);
})->with([
    'another value under the key of the filter' => ['OrderShipped', ['orderId' => 7, 'status' => 'sent']],
    'no key of the filter at all' => ['OrderShipped', ['status' => 'sent']],
    'a marked event that Laravel has no listener for' => [OrderDelivered::class, ['orderId' => 7]],
]);

it('treats an empty filter as no filter', function () {
    Native::test(FilteredListenersScreen::class)
        ->emitNative('ListCleared', ['reason' => 'manual'])
        ->assertSet('log', ['emptyFilter']);
});

it('matches a null in the filter against a null in the payload and not against a missing key', function () {
    Native::test(FilteredListenersScreen::class)
        ->emitNative('DraftSaved', [])
        ->emitNative('DraftSaved', ['id' => 0])
        ->assertSet('log', [])
        ->emitNative('DraftSaved', ['id' => null])
        ->assertSet('log', ['unsavedDraft']);
});

it('needs every key of a filter to match', function () {
    Native::test(FilteredListenersScreen::class)
        ->emitNative('JobFinished', ['queue' => 'mail', 'failed' => false])
        ->emitNative('JobFinished', ['queue' => 'sms', 'failed' => true])
        ->emitNative('JobFinished', ['queue' => 'mail'])
        ->assertSet('log', [])
        ->emitNative('JobFinished', ['queue' => 'mail', 'failed' => true])
        ->assertSet('log', ['mailJobFailed']);
});

it('matches two numbers that are equal as numbers, and compares everything else strictly', function (string $event, array $payload, array $heard) {
    Native::test(FilteredListenersScreen::class)
        ->emitNative($event, $payload)
        ->assertSet('log', $heard);
})->with([
    // JSON has one number type, and a device writes a whole double without its fraction.
    'a float in the filter, the whole int in the payload' => ['ProgressMade', ['ratio' => 1], ['done']],
    'an int in the filter, the whole float in the payload' => ['OrderShipped', ['orderId' => 42.0, 'status' => 'sent'], ['thisOrderShipped:sent']],
    'an int in the filter, a float with a fraction in the payload' => ['OrderShipped', ['orderId' => 42.5, 'status' => 'sent'], []],
    'an int in the filter, a numeric string in the payload' => ['OrderShipped', ['orderId' => '42', 'status' => 'sent'], []],
    'a true in the filter, a one in the payload' => ['JobFinished', ['queue' => 'mail', 'failed' => 1], []],
]);

// ── Dotted keys ─────────────────────────────────────

it('reads a nested value of the payload through a dotted key', function () {
    Native::test(FilteredListenersScreen::class)
        ->emitNative('MessageReceived', ['sender' => ['id' => 8], 'body' => 'from eight'])
        ->emitNative('MessageReceived', ['body' => 'no sender'])
        ->emitNative('MessageReceived', ['sender' => ['id' => 7], 'body' => 'from seven'])
        ->assertSet('log', ['fromSeven:from seven']);
});

it('lets a literal key that contains the dot win over the nested value', function () {
    Native::test(FilteredListenersScreen::class)
        ->emitNative('MessageReceived', ['sender.id' => 8, 'sender' => ['id' => 7], 'body' => 'nested only'])
        ->assertSet('log', [])
        ->emitNative('MessageReceived', ['sender.id' => 7, 'sender' => ['id' => 8], 'body' => 'literal'])
        ->assertSet('log', ['fromSeven:literal']);
});

// ── Enums ───────────────────────────────────────────

it('compares a backed enum by its backing value on either side', function (array $payload, array $heard) {
    Native::test(FilteredListenersScreen::class)
        ->emitNative(ThermalStateChanged::class, $payload)
        ->assertSet('log', $heard);
})->with([
    'an enum in the filter, its backing value in the payload' => [
        ['state' => 'critical', 'previous' => 'hot'], ['tooHot'],
    ],
    'an enum in the filter, the enum in the payload' => [
        ['state' => ThermalState::Critical, 'previous' => ThermalState::Hot], ['tooHot'],
    ],
    'a backing value in the filter, the enum in the payload' => [
        ['state' => ThermalState::Normal, 'previous' => ThermalState::Warm], ['cooledDown'],
    ],
    'another case of the enum in the payload' => [
        ['state' => ThermalState::Hot, 'previous' => ThermalState::Warm], [],
    ],
]);

// ── Several listeners on one event ──────────────────

it('judges two listeners on one event each on their own filter, and renders once when one of them matched', function () {
    Native::test(FilteredListenersScreen::class)
        ->emitNative('LineReceived', ['alias' => 'queue', 'data' => 'one'])
        ->assertRenderCount(2)
        ->emitNative('LineReceived', ['alias' => 'other', 'data' => 'two'])
        ->assertRenderCount(2)
        ->emitNative('LineReceived', ['alias' => 'encode', 'data' => 'three'])
        ->assertSet('log', ['fromQueue:one', 'fromEncoder:three'])
        ->assertRenderCount(3);
});

it('runs a filtered and an unfiltered listener on one event together, in declared order', function () {
    Native::test(FilteredListenersScreen::class)
        ->emitNative('TaskUpdated', ['urgent' => true])
        ->assertSet('log', ['urgentTask', 'everyTask'])
        ->emitNative('TaskUpdated', ['urgent' => false])
        ->assertSet('log', ['urgentTask', 'everyTask', 'everyTask']);
});

it('runs a method with two filters once for each event that either or both of them match', function () {
    Native::test(FilteredListenersScreen::class)
        ->emitNative('StockChanged', ['sku' => 'A', 'low' => true])
        ->assertSet('log', ['restock:A'])
        ->emitNative('StockChanged', ['sku' => 'B', 'low' => true])
        ->emitNative('StockChanged', ['sku' => 'C', 'low' => false])
        ->assertSet('log', ['restock:A', 'restock:B']);
});

// ── Rendering ───────────────────────────────────────

it('renders after a native event the screen has no listener for, also right after a miss', function () {
    Native::test(FilteredListenersScreen::class)
        ->emitNative('OrderShipped', ['orderId' => 7])
        ->assertRenderCount(1)
        ->emitNative('NobodyListens', ['orderId' => 7])
        ->assertSet('log', [])
        ->assertRenderCount(2);
});

it('renders after a miss when something else ran for the event', function (Closure $arrange, string $event, array $payload, array $heard) {
    $screen = Native::test(FilteredListenersScreen::class);

    $arrange($screen);

    $frames = $screen->renderCount();

    $screen->emitNative($event, $payload)
        ->assertSet('log', $heard)
        ->assertRenderCount($frames + 1);
})->with([
    'a closure registered with on()' => [
        fn (TestableComponent $screen) => $screen->instance()->registerNativeEventListener('OrderShipped', function ($event) {
            $this->log[] = "closure:{$event->orderId}";
        }),
        'OrderShipped', ['orderId' => 7], ['closure:7'],
    ],
    'a fluent callback' => [
        fn (TestableComponent $screen) => $screen->call('awaitPing'),
        PingReceived::class, ['message' => 'other', 'id' => 'ping-capture'], ['callback:other'],
    ],
    // What such a listener changed may be on screen, as the theme and the thermal state of this package are.
    'a Laravel listener for a marked event' => [
        fn (TestableComponent $screen) => Event::listen(OrderDelivered::class, function (OrderDelivered $event) use ($screen) {
            $screen->instance()->log[] = "laravel:{$event->orderId}";
        }),
        OrderDelivered::class, ['orderId' => 7], ['laravel:7'],
    ],
]);

it('does not render after a miss for a wildcard listener, which a logging tool has for every event', function () {
    $heard = [];
    Event::listen('*', function (string $event) use (&$heard) {
        $heard[] = $event;
    });

    Native::test(FilteredListenersScreen::class)
        ->emitNative(OrderDelivered::class, ['orderId' => 7])
        ->assertSet('log', [])
        ->assertRenderCount(1);

    expect($heard)->toContain(OrderDelivered::class);
});

// ── Component events ────────────────────────────────

it('runs every listener of a component event in declared order, with a filter matched against its named arguments', function (Closure $pick) {
    $screen = Native::test(ListenersHostScreen::class);

    $pick($screen, 6)->assertSet('log', ['pickedAny:6', 'countPick:6']);
    $pick($screen, 5)->assertSet('log', ['pickedAny:6', 'countPick:6', 'pickedFive:5', 'pickedAny:5', 'countPick:5']);
})->with([
    'the arguments of an emit() in a nested child' => [fn (TestableComponent $screen, int $id) => $screen->tap("pick-{$id}")],
    'the arguments of a dispatch() on the screen' => [fn (TestableComponent $screen, int $id) => $screen->call('pickFromScreen', $id)],
]);

// ── Async tasks ─────────────────────────────────────

it('filters the shared() result of an async task on its status', function () {
    $screen = Native::test(FilteredListenersScreen::class);

    AsyncTaskRegistry::register('task-1', $screen->instance(), 'report-ready');
    AsyncTaskRegistry::register('task-2', $screen->instance(), 'report-ready');

    $screen->emitNative(AsyncTaskFinished::class, ['id' => 'task-1', 'result' => 'DONE'])
        ->assertSet('log', [])
        ->emitNative(AsyncTaskFailed::class, ['id' => 'task-2', 'message' => 'kaboom'])
        ->assertSet('log', ['reportFailed:kaboom']);
});

// ── The real loops ──────────────────────────────────

it('publishes no frame after a miss, keeps the frame on screen usable, and draws again on the next idle tick', function (string $loop) {
    $screen = new FilteredListenersScreen;

    // The script of the wait call of the loop, which ends on a shutdown once it is empty.
    $bridge = new ScriptedEventBridge([
        ['type' => NativeComponent::EVENT_NATIVE, 'event' => 'OrderShipped', 'payload' => ['orderId' => 7]],
        ['type' => 0, 'callback_id' => (new CallbackRegistry)->register('increment')],
        ['type' => NativeComponent::EVENT_NATIVE, 'event' => 'OrderShipped', 'payload' => ['orderId' => 42, 'status' => 'sent']],
        ['type' => NativeComponent::EVENT_NATIVE, 'event' => 'OrderShipped', 'payload' => ['orderId' => 7]],
        null,
    ]);

    app()->instance(FakeBridge::class, $bridge);

    $screen->{$loop}();

    // The first frame, one for the tap, one for the event that matched and one for the idle tick.
    expect($bridge->publishes)->toHaveCount(4)
        ->and($screen->log)->toBe(['thisOrderShipped:sent'])
        ->and($screen->count)->toBe(1);
})->with(['run()' => 'run', 'runLoop()' => 'runLoop']);
