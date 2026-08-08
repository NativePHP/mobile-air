<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Contracts\RuntimeObserver;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\Runtime\ComponentContext;
use Native\Mobile\Edge\Runtime\ComponentPublished;
use Native\Mobile\Edge\Runtime\Dispatch as RuntimeDispatch;
use Native\Mobile\Edge\Runtime\DispatchFinished;
use Native\Mobile\Edge\Runtime\DispatchKind;
use Native\Mobile\Edge\Runtime\DispatchStarting;
use Native\Mobile\Edge\Runtime\RenderTimings;
use Native\Mobile\Edge\Runtime\RuntimeFailed;
use Native\Mobile\Edge\RuntimeObservers;
use Native\Mobile\Testing\FakeBridge;

class RuntimeObservedComponent extends NativeComponent
{
    public int $count = 0;

    public bool $stopAfterRender = false;

    public function render(): Column
    {
        if ($this->stopAfterRender) {
            $this->stop();
        }

        return Column::make();
    }

    public function initializeCallbacks(): int
    {
        $this->nativeCallbacks = new CallbackRegistry;

        return $this->nativeCallbacks->register('increment');
    }

    public function dispatchUi(array $event): void
    {
        $this->dispatch($event);
    }

    public function dispatchPluginEvent(string $event, array $payload): void
    {
        $this->dispatchNativeEvent([
            'type' => self::EVENT_NATIVE,
            'event' => $event,
            'payload' => $payload,
        ]);
    }

    public function increment(): void
    {
        $this->count++;
    }
}

afterEach(function () {
    RuntimeObservers::reset();
});

function runtimeObserverSpy(): RuntimeObserver
{
    return new class implements RuntimeObserver
    {
        public array $published = [];

        public array $starting = [];

        public array $finished = [];

        public array $failures = [];

        public array $startingStates = [];

        public array $finishedStates = [];

        public function componentPublished(ComponentPublished $event): void
        {
            $this->published[] = $event;
        }

        public function dispatchStarting(DispatchStarting $event): void
        {
            $this->starting[] = $event;
            $this->startingStates[] = $event->dispatch->context->component->count ?? null;
        }

        public function dispatchFinished(DispatchFinished $event): void
        {
            $this->finished[] = $event;
            $this->finishedStates[] = $event->dispatch->context->component->count ?? null;
        }

        public function failed(RuntimeFailed $event): void
        {
            $this->failures[] = $event;
        }
    };
}

it('fans runtime notifications out and supports explicit unregistration', function () {
    $observer = runtimeObserverSpy();
    $id = RuntimeObservers::register($observer);
    $component = new RuntimeObservedComponent;
    $context = new ComponentContext($component, '/counter', 2);
    $dispatch = new RuntimeDispatch(
        id: 1,
        context: $context,
        kind: DispatchKind::Interaction,
        method: 'increment',
        eventType: 1,
    );
    $exception = new RuntimeException('broken');

    RuntimeObservers::componentPublished(new ComponentPublished(
        $context,
        new RenderTimings(1.0, 0.5, 0.25),
    ));
    RuntimeObservers::dispatchStarting(new DispatchStarting($dispatch));
    RuntimeObservers::dispatchFinished(new DispatchFinished($dispatch, 1.5));
    RuntimeObservers::failed(new RuntimeFailed($context, $exception));

    expect(RuntimeObservers::any())->toBeTrue()
        ->and($observer->published)->toHaveCount(1)
        ->and($observer->published[0]->context)->toBe($context)
        ->and($observer->published[0]->timings?->serializeMs)->toBe(0.5)
        ->and($observer->starting)->toHaveCount(1)
        ->and($observer->starting[0]->dispatch)->toBe($dispatch)
        ->and($observer->finished[0]->durationMs)->toBe(1.5)
        ->and($observer->failures[0]->exception)->toBe($exception);

    RuntimeObservers::unregister($id);

    expect(RuntimeObservers::any())->toBeFalse();
});

it('isolates application behavior from observer failures', function () {
    RuntimeObservers::register(new class implements RuntimeObserver
    {
        public function componentPublished(ComponentPublished $event): void
        {
            throw new RuntimeException('observer');
        }

        public function dispatchStarting(DispatchStarting $event): void
        {
            throw new RuntimeException('observer');
        }

        public function dispatchFinished(DispatchFinished $event): void
        {
            throw new RuntimeException('observer');
        }

        public function failed(RuntimeFailed $event): void
        {
            throw new RuntimeException('observer');
        }
    });
    $observer = runtimeObserverSpy();
    RuntimeObservers::register($observer);
    $component = new RuntimeObservedComponent;
    $context = new ComponentContext($component, '/counter', 1);
    $dispatch = new RuntimeDispatch(1, $context, DispatchKind::Interaction);

    RuntimeObservers::componentPublished(new ComponentPublished($context));
    RuntimeObservers::dispatchStarting(new DispatchStarting($dispatch));
    RuntimeObservers::dispatchFinished(new DispatchFinished($dispatch, 1.0));
    RuntimeObservers::failed(new RuntimeFailed($context, new RuntimeException('application')));

    expect($observer->published)->toHaveCount(1)
        ->and($observer->starting)->toHaveCount(1)
        ->and($observer->finished)->toHaveCount(1)
        ->and($observer->failures)->toHaveCount(1);
});

it('observes interaction dispatch around the actual component mutation', function () {
    $observer = runtimeObserverSpy();
    RuntimeObservers::register($observer);
    $component = new RuntimeObservedComponent;
    $callbackId = $component->initializeCallbacks();

    $component->dispatchUi(['type' => 1, 'callback_id' => $callbackId, 'node_id' => 7]);

    expect($component->count)->toBe(1)
        ->and($observer->starting)->toHaveCount(1)
        ->and($observer->starting[0]->dispatch->kind)->toBe(DispatchKind::Interaction)
        ->and($observer->startingStates[0])->toBe(0)
        ->and($observer->finished)->toHaveCount(1)
        ->and($observer->finishedStates[0])->toBe(1)
        ->and($observer->finished[0]->dispatch->nodeId)->toBe(7)
        ->and($observer->finished[0]->durationMs)->toBeFloat();
});

it('observes native dispatch around the actual component mutation', function () {
    $observer = runtimeObserverSpy();
    RuntimeObservers::register($observer);
    $component = new RuntimeObservedComponent;
    $component->registerNativeEventListener('counter.changed', function (object $event): void {
        $this->count = $event->value;
    });

    $component->dispatchPluginEvent('counter.changed', ['value' => 5]);

    expect($component->count)->toBe(5)
        ->and($observer->starting)->toHaveCount(1)
        ->and($observer->starting[0]->dispatch->kind)->toBe(DispatchKind::Native)
        ->and($observer->starting[0]->dispatch->event)->toBe('counter.changed')
        ->and($observer->starting[0]->dispatch->payload)->toBe(['value' => 5])
        ->and($observer->startingStates[0])->toBe(0)
        ->and($observer->finished)->toHaveCount(1)
        ->and($observer->finishedStates[0])->toBe(5)
        ->and($observer->finished[0]->exception)->toBeNull();
});

it('reports a native dispatch exception without changing propagation', function () {
    $observer = runtimeObserverSpy();
    RuntimeObservers::register($observer);
    $component = new RuntimeObservedComponent;
    $exception = new RuntimeException('native listener failed');
    $component->registerNativeEventListener('counter.failed', function () use ($exception): void {
        throw $exception;
    });

    try {
        $component->dispatchPluginEvent('counter.failed', []);
    } catch (RuntimeException $thrown) {
        expect($thrown)->toBe($exception);
    }

    expect($observer->starting)->toHaveCount(1)
        ->and($observer->finished)->toHaveCount(1)
        ->and($observer->finished[0]->exception)->toBe($exception);
});

it('observes a published component frame from the real run loop', function () {
    $bridge = FakeBridge::enable();
    $observer = runtimeObserverSpy();
    RuntimeObservers::register($observer);
    $component = new RuntimeObservedComponent;
    $component->stopAfterRender = true;

    $component->runLoop();

    expect($bridge->publishes)->toHaveCount(1)
        ->and($observer->published)->toHaveCount(1)
        ->and($observer->published[0]->context->component)->toBe($component)
        ->and($observer->published[0]->context->id)->toBe(spl_object_hash($component))
        ->and($observer->published[0]->timings)->toBeInstanceOf(RenderTimings::class)
        ->and($observer->published[0]->timings->renderMs)->toBeFloat()
        ->and($observer->published[0]->timings->serializeMs)->toBeFloat()
        ->and($observer->published[0]->timings->publishMs)->toBeFloat();
});

it('reports the same runtime failure only once', function () {
    FakeBridge::enable();
    $observer = runtimeObserverSpy();
    RuntimeObservers::register($observer);
    $component = new RuntimeObservedComponent;
    $exception = new RuntimeException('broken render');

    $component->renderErrorScreen($exception);
    $component->renderErrorScreen($exception);

    expect($observer->failures)->toHaveCount(1)
        ->and($observer->failures[0]->exception)->toBe($exception)
        ->and($observer->failures[0]->context->component)->toBe($component)
        ->and($observer->failures[0]->context->id)->toBe(spl_object_hash($component));
});
