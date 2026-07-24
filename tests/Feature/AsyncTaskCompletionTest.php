<?php

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
    $prop->setValue(null, $component);
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
    AsyncTaskRegistry::register($id, spl_object_id($screen), null);
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
    AsyncTaskRegistry::register($id, spl_object_id($origin), null);
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

it('routes a failed() callback an AsyncTaskException with the original message', function () {
    $screen = new AsyncScreenDouble;
    setActiveComponent($screen);

    $id = 'a3';
    AsyncTaskRegistry::register($id, spl_object_id($screen), null);
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
    AsyncTaskRegistry::register($id, spl_object_id($origin), 'report-ready');

    // Any active component listening for the alias handles it.
    $current->registerNativeEventListener('report-ready', function ($event) {
        $this->sharedResult = $event->result;
    });

    setActiveComponent($current);
    $current->fireEvent(finishedEvent($id, 'SHARED'));

    expect($current->sharedResult)->toBe('SHARED')
        ->and(AsyncTaskRegistry::scope($id))->toBeNull();
});
