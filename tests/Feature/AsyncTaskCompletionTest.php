<?php

use Illuminate\Support\Facades\Cache;
use Native\Mobile\AsyncTask;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Async\AsyncTaskFailed;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Support\AsyncTaskRegistry;
use Native\Mobile\Support\NativeCallbacks;

/**
 * Minimal component double that lets a test drive the native-event path and
 * observe state mutations from async completion callbacks.
 */
class AsyncScreenDouble extends NativeComponent
{
    public mixed $result = null;

    public ?string $error = null;

    public mixed $sharedResult = null;

    public function fireEvent(array $event): void
    {
        $this->dispatchNativeEvent($event);
    }
}

/** Point NativeComponent::active() at a specific component (as the runloop would). */
function setActiveComponent(?NativeComponent $component): void
{
    $prop = new ReflectionProperty(NativeComponent::class, 'nativeActiveComponent');
    $prop->setAccessible(true);
    $prop->setValue(null, $component !== null ? WeakReference::create($component) : null);
}

function finishedEvent(string $id, mixed $result): array
{
    return ['type' => 20, 'event' => AsyncTaskFinished::class, 'payload' => ['id' => $id, 'result' => $result]];
}

afterEach(function () {
    setActiveComponent(null);
});

it('fires the finished() callback when the origin screen is still topmost', function () {
    $screen = new AsyncScreenDouble;
    setActiveComponent($screen);

    $id = 'a1';
    AsyncTaskRegistry::register($id, $screen, null);
    NativeCallbacks::register($id, AsyncTaskFinished::class, function ($result) {
        $this->result = $result;
    });

    $screen->fireEvent(finishedEvent($id, 'DONE'));

    expect($screen->result)->toBe('DONE')
        // Task cleaned up after firing.
        ->and(AsyncTaskRegistry::scope($id))->toBeNull();
});

it('drops the callback when the user has navigated to another screen', function () {
    $origin = new AsyncScreenDouble;
    $current = new AsyncScreenDouble;

    $id = 'a2';
    AsyncTaskRegistry::register($id, $origin, null);
    NativeCallbacks::register($id, AsyncTaskFinished::class, function ($result) {
        $this->result = $result;
    });

    // A different screen is topmost when the result arrives.
    setActiveComponent($current);
    $current->fireEvent(finishedEvent($id, 'DONE'));

    expect($current->result)->toBeNull()
        ->and($origin->result)->toBeNull()
        // Dropped task is forgotten, not left dangling.
        ->and(AsyncTaskRegistry::scope($id))->toBeNull();
});

it('drops the callback when the origin screen has been freed and its object id recycled', function () {
    $origin = new AsyncScreenDouble;
    $id = 'a2b';
    AsyncTaskRegistry::register($id, $origin, null);
    NativeCallbacks::register($id, AsyncTaskFinished::class, function ($result) {
        $this->result = $result;
    });

    // The origin screen is popped and freed. PHP is free to hand its object id
    // to the next component allocated — which is what an id comparison would
    // mistake for the origin.
    unset($origin);

    $current = new AsyncScreenDouble;
    setActiveComponent($current);
    $current->fireEvent(finishedEvent($id, 'DONE'));

    expect($current->result)->toBeNull()
        ->and(AsyncTaskRegistry::scope($id))->toBeNull();
});

it('routes a failed() callback an AsyncTaskException with the original message', function () {
    $screen = new AsyncScreenDouble;
    setActiveComponent($screen);

    $id = 'a3';
    AsyncTaskRegistry::register($id, $screen, null);
    NativeCallbacks::register($id, AsyncTaskFailed::class, function ($e) {
        $this->error = $e->getMessage();
    });

    $screen->fireEvent([
        'type' => 20,
        'event' => AsyncTaskFailed::class,
        'payload' => ['id' => $id, 'exceptionClass' => 'RuntimeException', 'message' => 'kaboom', 'trace' => null],
    ]);

    expect($screen->error)->toBe('kaboom');
});

it('delivers a shared() task as a named event regardless of active screen', function () {
    $origin = new AsyncScreenDouble;
    $current = new AsyncScreenDouble;

    $id = 'a4';
    // Shared alias set; origin is irrelevant for delivery.
    AsyncTaskRegistry::register($id, $origin, 'report-ready');

    // Any active component listening for the alias handles it.
    $current->registerNativeEventListener('report-ready', function ($event) {
        $this->sharedResult = $event->result;
    });

    setActiveComponent($current);
    $current->fireEvent(finishedEvent($id, 'SHARED'));

    expect($current->sharedResult)->toBe('SHARED')
        ->and(AsyncTaskRegistry::scope($id))->toBeNull();
});

it('registers async callbacks in memory only, so a dispatch costs no cache write', function () {
    $screen = new AsyncScreenDouble;
    setActiveComponent($screen);

    $fake = AsyncTask::fake();
    // The fake runs inline, so register the callback the way a real dispatch
    // would and check the tiers directly.
    NativeCallbacks::register('durability', AsyncTaskFinished::class, static fn () => null, durable: false);

    expect(NativeCallbacks::resolve('durability', AsyncTaskFinished::class))->not->toBeNull()
        ->and(Cache::has('native_cb:durability:'.AsyncTaskFinished::class))->toBeFalse();

    NativeCallbacks::flush();

    // Tier 1 was the only copy: nothing survives the flush.
    expect(NativeCallbacks::resolve('durability', AsyncTaskFinished::class))->toBeNull()
        ->and($fake->count())->toBe(0);
});

it('does not keep the last active screen alive once it has been freed', function () {
    $screen = new AsyncScreenDouble;
    setActiveComponent($screen);

    $weak = WeakReference::create($screen);
    unset($screen);

    // A strong static would pin the screen — and its whole state graph — for the
    // life of the process.
    expect($weak->get())->toBeNull()
        ->and(NativeComponent::active())->toBeNull();
});
