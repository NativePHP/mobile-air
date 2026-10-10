<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use Native\Mobile\Http\Bridge\BridgeDispatcher;
use Native\Mobile\Runtime;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\Support\GlobalEventTransport;
use Native\Mobile\Support\Lane;
use Native\Mobile\Testing\FakeBridge;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Fixtures\Edge\GlobalEventsScreen;
use Tests\Fixtures\Edge\LaneWork;
use Tests\Fixtures\Edge\Order;
use Tests\Fixtures\Edge\OrderAudited;
use Tests\Fixtures\Edge\OrderChanged;
use Tests\Fixtures\Edge\OrderDelivered;
use Tests\Fixtures\Edge\OrderPacked;
use Tests\Fixtures\Edge\OrderRefunded;
use Tests\Fixtures\Edge\OrderShipped;
use Tests\Support\Bridge;

beforeEach(function () {
    $this->globals = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST, $_ENV];
    $this->verbosity = getenv('SHELL_VERBOSITY');

    forgetLanes();

    Runtime::boot($this->app);

    // Every lane of an app has the same key, which the shell of a device hands to each of them.
    bootWithAppKey(laneAppKey());

    // The queue lane takes its jobs from the database, as it does on a device.
    config(['queue.default' => 'database']);
    (require __DIR__.'/../../../database/migrations/9999_12_31_000000_create_jobs_table.php')->up();

    Route::get('/lane-work', fn () => (new LaneWork)->handle());
    Artisan::command('lane:work', fn () => (new LaneWork)->handle());

    // Native takes every async task, and posts every event to a live native screen.
    FakeBridge::enable()
        ->respondTo('AsyncTask.Dispatch', ['success' => true])
        ->respondTo('Event.Broadcast', ['success' => true, 'delivered' => true]);

    NativeComponent::restoreActive(null);
    OrderAudited::$woken = 0;

    // A task that an earlier test left in the spool is no file of an event.
    File::deleteDirectory(AsyncTaskTransport::directory());
});

afterEach(function () {
    [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST, $_ENV] = $this->globals;

    LaneWork::$work = LaneWork::$answer = LaneWork::$failure = null;

    // The --quiet of the queue worker silences every artisan command of the process after it.
    putenv($this->verbosity === false ? 'SHELL_VERBOSITY' : "SHELL_VERBOSITY={$this->verbosity}");
    putenv('NATIVEPHP_ASYNC_JUMP');

    File::deleteDirectory(AsyncTaskTransport::directory());

    forgetLanes();
});

/**
 * Take back what a lane leaves in the statics of the process: its
 * mark, and what it has warned about, which it logs only once.
 * Each test starts without them, and leaves none behind.
 */
function forgetLanes(): void
{
    Lane::reset();
    GlobalEventTransport::reset();
}

/**
 * Run work on a lane, through the entry point that the native side
 * uses for it, and answer what the work returned. What the work
 * threw is thrown again as soon as that lane has handled it.
 */
function onLane(string $lane, Closure $work): mixed
{
    LaneWork::$work = $work;
    LaneWork::$answer = LaneWork::$failure = null;

    if ($lane === 'queue') {
        Queue::push(new LaneWork);
    }

    match ($lane) {
        'async' => Runtime::artisan('native:async:run --id='.spoolLaneWork()),
        'jump' => Runtime::artisan('native:async:run --jump --id='.spoolLaneWork()),
        'queue' => Runtime::artisan('queue:work --once --quiet'),
        'webview', 'persistent' => BridgeDispatcher::dispatch('ios', $lane, 'GET', '/lane-work', '/native.php'),
        'artisan' => Runtime::artisan('lane:work'),
    };

    if (LaneWork::$failure !== null) {
        throw LaneWork::$failure;
    }

    return LaneWork::$answer;
}

/** Hand the work to the async lane as the main lane does, and answer the id of the task. */
function spoolLaneWork(): string
{
    $id = (string) Str::uuid();

    AsyncTaskTransport::dispatch($id, serialize(['kind' => 'task', 'task' => LaneWork::class, 'args' => []]));

    return $id;
}

/**
 * What native posts for the loop of a native screen for every call that
 * PHP made, oldest first. Native takes the payload of a call alone,
 * and posts it under the one internal name that it has for it.
 *
 * @return list<array{event: string, payload: array{data: string, sig: string}}>
 */
function posted(): array
{
    return array_map(
        fn (array $call) => ['event' => '__global_event', 'payload' => $call['params']['payload']],
        FakeBridge::current()->callsTo('Event.Broadcast'),
    );
}

/**
 * Fire an event in a queued job, and answer what native
 * posted for the main lane because of that event.
 *
 * @return array{event: string, payload: array{data: string, sig: string}}
 */
function crossed(object $event): array
{
    onLane('queue', fn () => event($event));

    return last(posted());
}

/**
 * Play the main lane, which is another interpreter and has no mark of
 * a lane. What native posted for the loop of a native screen comes
 * in at the live screen there, as any event of the device does.
 *
 * @param  array{event: string, payload: array<string, mixed>}  $posted
 */
function arriveOnMainLane(array $posted): TestableComponent
{
    Lane::reset();

    return Native::test(GlobalEventsScreen::class)->emitNative($posted['event'], $posted['payload']);
}

/**
 * The app key that every lane of these tests has, in the `base64:`
 * form that Laravel generates a key in. A test that wants one
 * lane to have another key, or none, boots with that one.
 */
function laneAppKey(): string
{
    return 'base64:'.base64_encode(hash('sha256', 'the app key of every lane', true));
}

/**
 * Give the application an app key, or none, as a lane has it when it
 * boots. Laravel makes its encrypter once and keeps it, so the one
 * that was made with the key before this one is forgotten here.
 */
function bootWithAppKey(?string $key): void
{
    config(['app.key' => $key]);

    app()->forgetInstance('encrypter');
}

/**
 * Stand in for the extension of a device, which no test loads. It
 * answers the counters of the native event queue that a test
 * gave with queueHolds(), and null when a test gave none.
 */
function nativephp_event_queue_stats(): mixed
{
    return app()->bound('native.event-queue') ? app('native.event-queue') : null;
}

/**
 * Let the native event queue hold this many frames and bytes, next to
 * the limits that the extension has for it on a device. The lanes
 * of a test read them as the extension would answer them.
 */
function queueHolds(int $frames, int $bytes): void
{
    app()->instance('native.event-queue', [
        'depth' => $frames,
        'bytes' => $bytes,
        'dropped' => 0,
        'max_frames' => 1024,
        'max_bytes' => 8388608,
    ]);
}

function createOrdersTable(): void
{
    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->string('status');
        $table->timestamps();
    });
}

// ── The crossing ────────────────────────────────────

it('is heard once by Laravel and once by the screen on the main lane, and by no listener in the lane that fired it', function (string $lane) {
    $heard = [];
    Event::listen(OrderShipped::class, function (OrderShipped $event) use (&$heard) {
        $heard[] = "{$event->orderId}:{$event->status}";

        return 'noted';
    });

    // No listener ran where the event was fired, so event() answers there as if nobody listened.
    expect(onLane($lane, fn () => event(new OrderShipped(42, 'shipped'))))->toBe([])
        ->and($heard)->toBe([])
        ->and(posted())->toHaveCount(1);

    arriveOnMainLane(posted()[0])
        ->assertSet('log', ['shipped:42:shipped'])
        ->assertRenderCount(2);

    // The main lane ran the listener, and sent nothing back to where the event came from.
    expect($heard)->toBe(['42:shipped'])
        ->and(posted())->toHaveCount(1);
})->with(['a queued job' => 'queue', 'an async task' => 'async', 'a request of a web view' => 'webview']);

it('leaves an unmarked event with the listeners of the lane that fired it', function () {
    Event::listen(OrderPacked::class, fn () => 'noted');

    expect(onLane('queue', fn () => event(new OrderPacked(42))))->toContain('noted')
        ->and(posted())->toBe([]);
});

it('keeps a marked event with its own listeners when it is fired outside those three lanes', function (string $lane) {
    Event::listen(OrderShipped::class, fn () => 'noted');

    expect(onLane($lane, fn () => event(new OrderShipped(42, 'shipped'))))->toContain('noted')
        ->and(posted())->toBe([]);
})->with([
    'a request of a web view app, on the persistent lane' => 'persistent',
    'an artisan command that is not the queue worker' => 'artisan',
]);

it('does not send an event that the device posted to the events endpoint of a web view lane on to the main lane', function () {
    $heard = 0;
    Event::listen(OrderShipped::class, function () use (&$heard) {
        $heard++;
    });

    $response = Bridge::parse(BridgeDispatcher::dispatch(
        'android', 'webview', 'POST', '/_native/api/events', '/native.php',
        headers: 'Content-Type: application/json',
        body: json_encode(['event' => OrderShipped::class, 'payload' => ['orderId' => 42, 'status' => 'shipped']]),
    ));

    // The native screen heard this event from the device itself, so one from here would be one too many.
    expect($response['status'])->toBe(200, $response['body'])
        ->and($heard)->toBe(1)
        ->and(posted())->toBe([]);
});

it('hands native the serialized event and its signature, as the one parameter of Event.Broadcast', function () {
    onLane('queue', fn () => event(new OrderShipped(42, 'shipped')));

    $calls = FakeBridge::current()->callsTo('Event.Broadcast');

    // Swift and Kotlin take the object under `payload` and post it as it is, under a name of their own.
    expect($calls)->toHaveCount(1)
        ->and(array_keys($calls[0]['params']))->toBe(['payload'])
        ->and(array_keys($calls[0]['params']['payload']))->toBe(['data', 'sig']);

    ['data' => $data, 'sig' => $sig] = $calls[0]['params']['payload'];

    // Base64 holds no byte that JSON or a C string would cut, as the NUL bytes of serialize() are.
    expect($data)->toMatch('/^[A-Za-z0-9+\/]+={0,2}$/')
        ->and(unserialize(base64_decode($data, true)))->toEqual(new OrderShipped(42, 'shipped'))
        ->and($sig)->toBeString()->not->toBeEmpty();
});

// ── What an event carries ───────────────────────────

it('carries the model that an event holds to the main lane as a model', function () {
    $heard = [];
    Event::listen(OrderChanged::class, function (OrderChanged $event) use (&$heard) {
        $heard[] = $event;
    });

    $posted = crossed(new OrderChanged((new Order)->forceFill(['id' => 42, 'status' => 'paid']), collect([['sku' => 'A']])));

    // The dotted filter keys of the screen read the model and the collection that arrived.
    arriveOnMainLane($posted)->assertSet('log', ['thisOrderChanged', 'firstLineIsA']);

    expect($heard)->toHaveCount(1)
        ->and($heard[0]->order)->toBeInstanceOf(Order::class)
        ->and($heard[0]->order->getAttributes())->toBe(['id' => 42, 'status' => 'paid'])
        ->and($heard[0]->lines)->toBeInstanceOf(Collection::class);
});

it('fetches the model of an event that uses SerializesModels again on the main lane, as a queued job does', function () {
    createOrdersTable();

    $order = Order::create(['status' => 'paid']);

    $posted = crossed(new OrderRefunded($order));

    // The row changes while the event is on its way, and the main lane reads it as it is on arrival.
    Order::query()->whereKey($order->getKey())->update(['status' => 'refunded']);

    arriveOnMainLane($posted)->assertSet('log', ["refunded:{$order->getKey()}:refunded"]);
});

it('drops an event whose model is gone by the time it arrives, and says so in the log', function () {
    createOrdersTable();

    $order = Order::create(['status' => 'paid']);

    $heard = 0;
    Event::listen(OrderRefunded::class, function () use (&$heard) {
        $heard++;
    });

    $posted = crossed(new OrderRefunded($order));

    $order->delete();

    Log::spy();

    arriveOnMainLane($posted)->assertSet('log', [])->assertRenderCount(1);

    // No listener ran for this event in any lane, and the log is the one place that says so.
    expect($heard)->toBe(0);

    Log::shouldHaveReceived('warning');
});

// ── On the screen ───────────────────────────────────

it('renders on the main lane as for an event that was fired there', function (int $orderId, bool $inLaravel, array $onScreen, int $frames) {
    if ($inLaravel) {
        Event::listen(OrderDelivered::class, fn () => null);
    }

    arriveOnMainLane(crossed(new OrderDelivered($orderId)))
        ->assertSet('log', $onScreen)
        ->assertRenderCount($frames);
})->with([
    'once for an order that the filter of the screen names' => [42, false, ['delivered:42'], 2],
    'not at all for an order that the filter does not name' => [7, false, [], 1],
    'once for a Laravel listener that ran, though the filter does not name the order' => [7, true, [], 2],
]);

// ── What the main lane takes ────────────────────────

it('has no effect on the main lane, and is never unserialized, when this app did not sign the payload or it was changed', function (Closure $forge) {
    $heard = 0;
    Event::listen(OrderAudited::class, function () use (&$heard) {
        $heard++;
    });

    $posted = crossed(new OrderAudited(42));

    // The event counts how often PHP unserializes one, and stays at zero for what the main lane does not trust.
    $screen = arriveOnMainLane(['payload' => $forge($posted['payload'])] + $posted)
        ->assertSet('log', [])
        ->assertRenderCount(1);

    expect($heard)->toBe(0)
        ->and(OrderAudited::$woken)->toBe(0);

    // The payload as the lane sent it does arrive, on the same screen.
    $screen->emitNative($posted['event'], $posted['payload'])->assertSet('log', ['audited:42']);

    expect($heard)->toBe(1)
        ->and(OrderAudited::$woken)->toBe(1);
})->with([
    'data that was changed' => [fn (array $payload) => ['data' => base64_encode(serialize(new OrderAudited(7)))] + $payload],
    'a signature that was changed' => [fn (array $payload) => ['sig' => substr_replace($payload['sig'], $payload['sig'][0] === 'f' ? '0' : 'f', 0, 1)] + $payload],
    'no signature' => [fn (array $payload) => ['data' => $payload['data']]],
    'a signature that a lane with the key of another app made' => [function () {
        bootWithAppKey('base64:'.base64_encode(hash('sha256', 'the app key of another app', true)));

        try {
            return crossed(new OrderAudited(42))['payload'];
        } finally {
            bootWithAppKey(laneAppKey());
        }
    }],
]);

// ── Staying in the lane ─────────────────────────────

it('stays in the lane that fired it, where its listeners then run, when it cannot cross', function (Closure $arrange, Closure $event, bool $warns) {
    $arrange();

    Log::spy();

    $heard = 0;
    Event::listen(BroadcastsGlobally::class, function () use (&$heard) {
        $heard++;

        return 'noted';
    });

    expect(onLane('queue', fn () => event($event())))->toContain('noted')
        ->and($heard)->toBe(1);

    // A screen that is not there is no fault. For an event that can never cross, the log is all a developer gets.
    $warns ? Log::shouldHaveReceived('warning') : Log::shouldNotHaveReceived('warning');
})->with([
    'no native screen is live' => [
        fn () => FakeBridge::current()->respondTo('Event.Broadcast', ['success' => true, 'delivered' => false]),
        fn () => new OrderShipped(42, 'shipped'),
        false,
    ],
    'native does not answer the call' => [
        fn () => FakeBridge::current()->respondTo('Event.Broadcast', null),
        fn () => new OrderShipped(42, 'shipped'),
        false,
    ],
    'an event that cannot be serialized' => [
        fn () => null,
        fn () => new OrderChanged(new stdClass, fn () => 'a closure'),
        true,
    ],
    'an event that is too large for the native event queue' => [
        fn () => null,
        fn () => new OrderShipped(42, str_repeat('x', GlobalEventTransport::MAX_PAYLOAD_BYTES)),
        true,
    ],
    'an app without a key to sign the event with' => [
        fn () => bootWithAppKey(null),
        fn () => new OrderShipped(42, 'shipped'),
        true,
    ],
]);

it('stays in the lane that fired it, and is not posted, while the native event queue has no room for it', function (int $frames, int $bytes) {
    queueHolds($frames, $bytes);

    Log::spy();

    $heard = 0;
    Event::listen(OrderShipped::class, function () use (&$heard) {
        $heard++;

        return 'noted';
    });

    // A full queue takes a post all the same and drops its oldest event for it, so nothing may be posted to it.
    expect(onLane('queue', fn () => event(new OrderShipped(42, 'shipped'))))->toContain('noted')
        ->and($heard)->toBe(1)
        ->and(posted())->toBe([]);

    Log::shouldHaveReceived('warning');
})->with([
    'it holds as many frames as it takes' => [1024, 4096],
    'the event would take it over its bytes' => [3, 8388608 - 8],
]);

it('still crosses to the main lane when the native event queue has one place left for it', function () {
    queueHolds(frames: 1023, bytes: 4096);

    Event::listen(OrderShipped::class, fn () => 'noted');

    expect(onLane('queue', fn () => event(new OrderShipped(42, 'shipped'))))->toBe([])
        ->and(posted())->toHaveCount(1);
});

// ── Jump ────────────────────────────────────────────

it('reaches the screen from an async task under Jump, in the order of firing and ahead of the completion of the task', function () {
    $heard = 0;
    Event::listen(OrderShipped::class, function () use (&$heard) {
        $heard++;
    });

    onLane('jump', function () {
        event(new OrderShipped(1, 'shipped'));
        event(new OrderShipped(2, 'shipped'));
    });

    expect($heard)->toBe(0);

    // What the loop on the dev machine takes from the spool of the subprocess, in the order it takes it.
    $first = AsyncTaskTransport::drainJumpCompletion();
    $second = AsyncTaskTransport::drainJumpCompletion();
    $third = AsyncTaskTransport::drainJumpCompletion();

    expect($third['event'])->toBe(AsyncTaskFinished::class);

    arriveOnMainLane($first)
        ->emitNative($second['event'], $second['payload'])
        ->assertSet('log', ['shipped:1:shipped', 'shipped:2:shipped']);

    expect($heard)->toBe(2);
});
