<?php

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Device;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Events\Device\ThermalStateChanged;
use Native\Mobile\Events\GlobalEventDispatcher;
use Native\Mobile\Support\Lane;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Native\Mobile\ThermalState;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Edge\GlobalEventsScreen;
use Tests\Fixtures\Edge\ListenersChild;
use Tests\Fixtures\Edge\ListenersHostScreen;
use Tests\Fixtures\Edge\Order;
use Tests\Fixtures\Edge\OrderChanged;
use Tests\Fixtures\Edge\OrderDelivered;
use Tests\Fixtures\Edge\OrderPacked;
use Tests\Fixtures\Edge\OrderPaid;
use Tests\Fixtures\Edge\OrderReviewed;
use Tests\Fixtures\Edge\OrderShipped;
use Tests\Fixtures\Edge\ScriptedEventBridge;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    NativeRouter::clearRoutes();
    NativeRouter::register('/orders/{ship}', GlobalEventsScreen::class);

    ComponentRegistry::reset();
    ComponentRegistry::components(['listeners-child' => ListenersChild::class]);

    Device::forgetThermalState();

    // The mark of a lane is a static of the process, and a request on
    // a web view lane in an earlier test leaves one behind. These
    // tests all play the main lane, which has no such mark.
    Lane::reset();
});

afterEach(function () {
    NativeRouter::clearRoutes();
    ComponentRegistry::reset();
    Device::forgetThermalState();
});

/**
 * Send a screen through one of its two real loops, with the given
 * events scripted into its wait call and a shutdown to end on.
 * The bridge holds each frame and the timeout of each wait.
 */
function runGlobalEventsLoop(NativeComponent $screen, string $loop, array $events): ScriptedEventBridge
{
    $bridge = new class($events) extends ScriptedEventBridge
    {
        /** @var list<int> */
        public array $waits = [];

        public function elementWaitEvent(int $timeoutMs): ?array
        {
            $this->waits[] = $timeoutMs;

            return parent::elementWaitEvent($timeoutMs);
        }
    };

    app()->instance(FakeBridge::class, $bridge);

    $screen->{$loop}();

    return $bridge;
}

function deviceEventFrame(string $event, array $payload = []): array
{
    return ['type' => NativeComponent::EVENT_NATIVE, 'event' => $event, 'payload' => $payload];
}

// ── Both ways ───────────────────────────────────────

it('reaches the screen and Laravel as the marker says, each of them once', function (string $event, array $payload, string $from, array $onScreen, int $inLaravel, int $frames) {
    $heard = 0;
    Event::listen($event, function () use (&$heard) {
        $heard++;
    });

    $screen = Native::test(GlobalEventsScreen::class);

    $from === 'device'
        ? $screen->emitNative($event, $payload)
        : event(new $event(...$payload));

    $screen->assertSet('log', $onScreen)->assertRenderCount($frames);

    expect($heard)->toBe($inLaravel);
})->with([
    'a marked event from the device' => [
        OrderShipped::class, ['orderId' => 42, 'status' => 'shipped'], 'device', ['shipped:42:shipped'], 1, 2,
    ],
    'an unmarked event from the device' => [
        OrderPacked::class, ['orderId' => 42], 'device', ['packed'], 0, 2,
    ],
    'a marked event fired with event()' => [
        OrderShipped::class, ['orderId' => 42, 'status' => 'shipped'], 'php', ['shipped:42:shipped'], 1, 2,
    ],
    'an unmarked event fired with event()' => [
        OrderPacked::class, ['orderId' => 42], 'php', [], 1, 1,
    ],
]);

it('does not feed a marked event back into itself', function (string $from) {
    $heard = 0;
    Event::listen(OrderShipped::class, function () use (&$heard) {
        if (++$heard > 3) {
            throw new RuntimeException('The event keeps coming back to Laravel.');
        }
    });

    $screen = Native::test(GlobalEventsScreen::class);

    $from === 'device'
        ? $screen->emitNative(OrderShipped::class, ['orderId' => 42, 'status' => 'shipped'])
        : event(new OrderShipped(42, 'shipped'));

    // Three more interactions give a loop every chance to show itself.
    $screen->call('pack')->call('pack')->call('pack');

    expect($screen->get('log'))->toBe(['shipped:42:shipped'])
        ->and($heard)->toBe(1);
})->with(['from the device' => 'device', 'from PHP' => 'php']);

it('answers what the listeners of Laravel answered', function () {
    Event::listen(OrderShipped::class, fn () => 'noted');

    // The screen stays the live one for as long as the test holds its harness.
    $screen = Native::test(GlobalEventsScreen::class);

    expect(event(new OrderShipped(42, 'shipped')))->toContain('noted')
        ->and(Event::until(new OrderShipped(42, 'shipped')))->toBe('noted');
});

it('reaches the screen once the transaction that an event waits for has committed, and never after a rollback', function () {
    $screen = Native::test(GlobalEventsScreen::class);

    $heardInside = DB::transaction(function () use ($screen) {
        event(new OrderPaid(1));

        return $screen->get('log');
    });

    expect($heardInside)->toBe([]);

    $screen->assertSet('log', ['paid:1'])->assertRenderCount(2);

    DB::beginTransaction();
    event(new OrderPaid(2));
    DB::rollBack();

    $screen->assertSet('log', ['paid:1'])->assertRenderCount(2);
});

// ── Deferred delivery ───────────────────────────────

it('runs the listener after the handler that fired the event has returned, and renders once', function () {
    Native::test(GlobalEventsScreen::class)
        ->call('ship')
        ->assertSet('log', ['ship:returned', 'shipped:42:shipped'])
        ->assertSee('Log: ship:returned,shipped:42:shipped')
        ->assertRenderCount(2);
});

it('renders once for a burst of fifty events', function () {
    $screen = Native::test(GlobalEventsScreen::class)
        ->call('shipBurst', 50)
        ->assertRenderCount(2);

    expect($screen->get('log'))->toHaveCount(50)
        ->and($screen->get('log')[0])->toBe('shipped:1:burst')
        ->and($screen->get('log')[49])->toBe('shipped:50:burst');
});

it('leaves an event that a listener fires for the turn after, which renders again', function () {
    Native::test(GlobalEventsScreen::class)
        ->call('ship', 42, 'relay')
        ->assertSet('log', ['ship:returned', 'shipped:42:relay', 'relay:returned', 'delivered:42'])
        // The first frame, one for the turn of the interaction and one for the turn of the second event.
        ->assertRenderCount(3);
});

it('delivers the component event that a listener dispatched right after its event, in the same turn', function () {
    $screen = Native::test(GlobalEventsScreen::class);

    event(new OrderDelivered(5));

    $screen->assertSet('log', ['noted:5'])
        ->assertSee('Log: noted:5')
        ->assertRenderCount(2);
});

it('fails the test when a listener keeps firing an event that its own screen hears', function () {
    $screen = Native::test(GlobalEventsScreen::class);

    expect(fn () => $screen->call('ship', 1, 'again'))->toThrow(AssertionFailedError::class);
});

it('delivers an event fired in mount() on the first turn, in the first frame', function () {
    Native::test(GlobalEventsScreen::class, ['ship' => 7])
        ->assertSet('log', ['shipped:7:mounted'])
        ->assertSee('Log: shipped:7:mounted')
        ->assertRenderCount(1);
});

it('lets the exception of a listener reach the test', function () {
    $screen = Native::test(GlobalEventsScreen::class);

    expect(fn () => $screen->call('ship', 1, 'explode'))
        ->toThrow(RuntimeException::class, 'Shipping failed');
});

// ── Reach ───────────────────────────────────────────

it('reaches no screen that was never mounted', function () {
    $screen = new GlobalEventsScreen;

    event(new OrderShipped(42, 'shipped'));

    // Its loop finds nothing waiting when it runs later on.
    runGlobalEventsLoop($screen, 'runLoop', []);

    expect($screen->log)->toBe([]);
});

it('reaches no screen that is gone', function (Closure $leave) {
    $screen = Native::test(GlobalEventsScreen::class);

    $leave($screen);

    event(new OrderShipped(42, 'shipped'));

    expect($screen->get('log'))->toBe([])
        ->and($screen->renderCount())->toBe(1);
})->with([
    'a screen that was unmounted' => [fn (TestableComponent $screen) => $screen->instance()->unmount()],
    'a screen that has navigated away' => [fn (TestableComponent $screen) => $screen->call('navigate', '/orders/7')],
]);

it('does not reach a covered screen, and delivers nothing when it comes back', function () {
    // The screen fires an event on its way out, which it is not to hear on its return either.
    $orders = Native::test(GlobalEventsScreen::class)->call('shipThenLeave');
    $next = $orders->follow()->assertSet('log', ['shipped:7:mounted']);

    event(new OrderShipped(8, 'covered'));

    $next->assertSet('log', ['shipped:7:mounted', 'shipped:8:covered']);

    expect($orders->get('log'))->toBe([]);

    $next->goBack()->assertSet('log', []);

    // The screen that came back is the live one again.
    event(new OrderShipped(9, 'back'));

    $orders->assertSet('log', ['shipped:9:back']);
});

it('does not reach a nested component, as a native event does not', function () {
    $screen = Native::test(ListenersHostScreen::class)->assertSee('Child heard 0');

    event(new OrderShipped(42, 'shipped'));

    $screen->assertSet('log', ['shipped:42'])
        ->assertSee('Child heard 0')
        ->assertDontSee('Child heard 1');
});

it('reaches an on() closure', function () {
    $screen = Native::test(GlobalEventsScreen::class);

    $screen->instance()->registerNativeEventListener(OrderDelivered::class, function ($event) {
        $this->log[] = "closure:{$event->orderId}";
    });

    event(new OrderDelivered(7));

    $screen->assertSet('log', ['closure:7'])->assertRenderCount(2);
});

it('follows a listener that navigates, without another frame, and drops what waited behind its event', function () {
    Native::test(GlobalEventsScreen::class)
        ->call('shipPair', 'leave')
        ->assertNavigatedTo('/orders/7')
        ->assertSet('log', ['shipped:1:leave'])
        ->assertRenderCount(1);
});

// ── Filters and arguments ───────────────────────────

it('passes the `when` filter of a listener for an event from PHP, and renders only for a match', function (int $orderId, array $heard, int $frames) {
    $screen = Native::test(GlobalEventsScreen::class);

    event(new OrderDelivered($orderId));

    $screen->assertSet('log', $heard)->assertRenderCount($frames);
})->with([
    'an order that the filter names' => [42, ['delivered:42'], 2],
    'an order that the filter does not name' => [7, [], 1],
]);

it('gives a handler the same arguments for an event from the device and from PHP', function (string $from) {
    $screen = Native::test(GlobalEventsScreen::class);

    $from === 'device'
        ? $screen->emitNative(ThermalStateChanged::class, ['state' => 'critical', 'previous' => 'hot'])
        : event(new ThermalStateChanged(ThermalState::Critical, ThermalState::Hot));

    // The parameter typed with the enum got the enum, and the one typed string its backing value.
    $screen->assertSet('log', ['thermal:Critical:hot', 'tooHot']);
})->with(['from the device' => 'device', 'from PHP' => 'php']);

it('keeps a null of an event a null, for a nullable parameter of a handler and for a listener of Laravel', function (string $from) {
    $nulls = ['orderId' => null, 'comment' => null, 'recommended' => null, 'stars' => null];

    $heard = [];
    Event::listen(OrderReviewed::class, function (OrderReviewed $event) use (&$heard) {
        $heard[] = get_object_vars($event);
    });

    $screen = Native::test(GlobalEventsScreen::class);

    $from === 'device'
        ? $screen->emitNative(OrderReviewed::class, $nulls)
        : event(new OrderReviewed(null, null, null, null));

    $screen->assertSet('log', ['reviewed:[null,null,null,null]']);

    expect($heard)->toBe([$nulls]);
})->with(['from the device' => 'device', 'from PHP' => 'php']);

it('reads a dotted filter key from a model and from a collection', function () {
    $screen = Native::test(GlobalEventsScreen::class);

    event(new OrderChanged((new Order)->forceFill(['id' => 42]), collect([['sku' => 'A'], ['sku' => 'B']])));

    $screen->assertSet('log', ['thisOrderChanged', 'firstLineIsA']);

    event(new OrderChanged((new Order)->forceFill(['id' => 7]), collect([['sku' => 'B']])));

    $screen->assertSet('log', ['thisOrderChanged', 'firstLineIsA'])->assertNotRerendered();
});

// ── Event::fake() ───────────────────────────────────

it('reaches no screen when the event is faked, while every other event still arrives', function () {
    $screen = Native::test(GlobalEventsScreen::class);

    Event::fake([OrderDelivered::class]);

    event(new OrderDelivered(42));

    $screen->assertSet('log', [])->assertRenderCount(1);

    Event::assertDispatched(OrderDelivered::class);

    // An event that is not faked still arrives, and so does a faked one that the device sends.
    event(new OrderShipped(42, 'shipped'));

    $screen->assertSet('log', ['shipped:42:shipped'])
        ->emitNative(OrderDelivered::class, ['orderId' => 42])
        ->assertSet('log', ['shipped:42:shipped', 'delivered:42']);
});

// ── Another dispatcher ──────────────────────────────

it('warns that marked events stay off screens when the app has bound an event dispatcher of its own', function () {
    Log::spy();

    $custom = new class(app()) extends Dispatcher {};
    app()->instance('events', $custom);
    Event::clearResolvedInstance('events');

    // What the service provider does when it registers, for an app that boots with this dispatcher.
    GlobalEventDispatcher::install(app());

    $heard = 0;
    Event::listen(OrderShipped::class, function () use (&$heard) {
        $heard++;
    });

    $screen = Native::test(GlobalEventsScreen::class);

    event(new OrderShipped(42, 'shipped'));

    $screen->assertSet('log', []);

    // Laravel hears the event as before, and the log is all that tells why the screen does not.
    expect($heard)->toBe(1);

    Log::shouldHaveReceived('warning');
});

// ── The real loops ──────────────────────────────────

it('takes the next turn without blocking while an event waits in the inbox, and draws a frame only when a listener ran', function (string $loop, int $orderId, array $heard, int $frames) {
    $screen = new GlobalEventsScreen;

    // The handler fires an event, and the listener of that one fires a second, which arrives a turn later.
    $bridge = runGlobalEventsLoop($screen, $loop, [
        deviceEventFrame('ship-requested', ['orderId' => $orderId, 'status' => 'relay']),
        null,
    ]);

    expect($screen->log)->toBe(['ship:returned', "shipped:{$orderId}:relay", 'relay:returned', ...$heard])
        ->and($bridge->publishes)->toHaveCount($frames)
        // The first and the last wait block, and the one in between returns for the event that waits.
        ->and($bridge->waits)->toHaveCount(3)
        ->and($bridge->waits[0])->toBe(-1)
        ->and($bridge->waits[1])->toBeGreaterThanOrEqual(0)
        ->and($bridge->waits[2])->toBe(-1);
})->with(['run()' => 'run', 'runLoop()' => 'runLoop'])->with([
    'a second event that a filter of the screen matches' => [42, ['delivered:42'], 3],
    'a second event that no filter of the screen matches' => [7, [], 2],
]);

it('leaves the loop at once when the screen navigates, and delivers nothing of what still waited when it comes back', function (string $loop, array $event, array $heard) {
    $screen = new GlobalEventsScreen;

    $bridge = runGlobalEventsLoop($screen, $loop, [$event]);

    // The loop left before it waited a second time, though an event still waited for its turn.
    expect($screen->getNavigationIntent()?->uri)->toBe('/orders/7')
        ->and($bridge->waits)->toHaveCount(1)
        ->and($screen->log)->toBe($heard);

    // The router clears the intent of a covered screen, and runs its loop again on return.
    $screen->resetNavigationIntent();

    runGlobalEventsLoop($screen, 'runLoop', []);

    expect($screen->log)->toBe($heard);
})->with(['run()' => 'run', 'runLoop()' => 'runLoop'])->with([
    'a handler that fired an event and navigated' => [deviceEventFrame('leave-requested'), []],
    'a listener that navigated, with another event behind its own' => [deviceEventFrame('pair-requested', ['first' => 'leave']), ['shipped:1:leave']],
]);
