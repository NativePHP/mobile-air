<?php

use Native\Mobile\Edge\Asgard\ServerRegion;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\Enums\ActionSource;
use Native\Mobile\Edge\Exceptions\ComponentMethodNotFoundException;
use Native\Mobile\Edge\Exceptions\DirectlyCallingLifecycleHooksNotAllowedException;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Fixtures\Edge\ActionPipelineChild;
use Tests\Fixtures\Edge\ActionPipelineScreen;

/**
 * NativeComponent::callAction() is the single place the framework invokes a
 * developer's method. These tests hold that property: each route is driven
 * end to end and must (a) be seen by callAction() with the right source and
 * arguments, and (b) stop dead when callAction() declines to delegate.
 *
 * (b) is the part that matters. Recording proves a route reaches the
 * chokepoint; refusing to delegate proves the route has no other way to
 * reach the handler.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    ComponentRegistry::reset();
    ComponentRegistry::components([
        'action-pipeline-child' => ActionPipelineChild::class,
    ]);
});

afterEach(fn () => ComponentRegistry::reset());

/** Run a closure inside the component's scope, to reach protected internals. */
function inComponent(TestableComponent $screen, Closure $fn): mixed
{
    return Closure::bind($fn, $screen->instance(), NativeComponent::class)();
}

/** Register a kinded callback on the screen and fire a real wire event at it. */
function fireKinded(TestableComponent $screen, string $expression, string $kind, array $event): void
{
    inComponent($screen, function () use ($expression, $kind, $event) {
        /** @var NativeComponent $this */
        $id = $this->nativeCallbacks->register($expression, $kind);
        $this->dispatchUiEvent([...$event, 'callback_id' => $id]);
    });
}

/**
 * Paint a fragment whose button calls a client action, then tap it the
 * way a user would. set() repaints without going through callAction().
 */
function tapFragmentClientAction(TestableComponent $screen): void
{
    ServerRegion::applyFragment('picks', [
        'type' => 'pressable',
        'on_press' => ['call' => 'pick', 'args' => ['red', 2], 'target' => 'client'],
        'children' => [['type' => 'text', 'props' => ['text' => 'Pick red']]],
    ], $screen->instance());

    $screen->set('count', $screen->get('count'))->tap('Pick red');
}

/**
 * Fire due polls the way the idle tick does — through runDuePolls(), not the
 * harness sugar. pollDefinitions is a lazy cache, so build it before winding
 * the deadlines back.
 */
function firePollsViaRunloop(TestableComponent $screen): void
{
    inComponent($screen, function () {
        /** @var NativeComponent $this */
        $this->pollDefinitions();

        foreach (array_keys($this->pollDefinitions) as $i) {
            $this->pollDefinitions[$i]['next'] = 0;
        }

        $this->runDuePolls();
    });
}

// ── Each route reaches the chokepoint ───────────────

it('routes a tap through callAction as an interaction', function () {
    $screen = Native::test(ActionPipelineScreen::class)->tap('bump');

    expect($screen->instance()->actions)->toBe([['interaction', 'bump', []]])
        ->and($screen->instance()->ran)->toBe(['bump'])
        ->and($screen->instance()->count)->toBe(1);
});

it('routes a #[Poll] tick through callAction from the runloop', function () {
    $screen = Native::test(ActionPipelineScreen::class);

    firePollsViaRunloop($screen);

    expect($screen->instance()->actions)->toBe([['poll', 'protectedTick', []], ['poll', 'tick', []]])
        ->and($screen->instance()->ran)->toBe(['protectedTick', 'tick']);
});

it('routes a native event with an array payload through callAction', function () {
    $screen = Native::test(ActionPipelineScreen::class)->emitNative('pinged', ['who' => 'device']);

    expect($screen->instance()->actions)->toBe([['native_event', 'onPinged', ['who' => 'device']]])
        ->and($screen->instance()->ran)->toBe(['onPinged:device']);
});

it('routes a native event with a scalar payload through callAction', function () {
    $screen = Native::test(ActionPipelineScreen::class);

    inComponent($screen, function () {
        /** @var NativeComponent $this */
        $this->dispatchNativeEvent(['event' => 'scalar-ping', 'payload' => 'boom']);
    });

    expect($screen->instance()->actions)->toBe([['native_event', 'onScalarPing', ['boom']]])
        ->and($screen->instance()->ran)->toBe(['onScalarPing:boom']);
});

it('routes both halves of a child emit through the parent callAction', function () {
    $screen = Native::test(ActionPipelineScreen::class);

    inComponent($screen, function () {
        /** @var NativeComponent $this */
        $this->nativeChildComponents[array_key_first($this->nativeChildComponents)]->speak();
    });

    // The tag binding fires on the parent first, then the #[On] listener as
    // the emit bubbles. Both land on the screen, both through callAction.
    expect($screen->instance()->actions)->toBe([
        ['parent_binding', 'markSaved', ['tag', 'hi']],
        ['component_event', 'onChildSaid', ['hi']],
    ])->and($screen->instance()->ran)->toBe(['markSaved:tag:hi', 'onChildSaid:hi']);
});

it('routes a search_query callback through callAction and keeps the return', function () {
    $screen = Native::test(ActionPipelineScreen::class);

    fireKinded($screen, 'onSearchQuery', 'search_query', ['type' => 2, 'text' => 'nat']);

    expect($screen->instance()->actions)->toBe([['search_query', 'onSearchQuery', ['nat']]])
        ->and($screen->instance()->ran)->toBe(['onSearchQuery'])
        ->and($screen->searchResults())->toBe(['hit:nat']);
});

it('routes a virtual_window callback through callAction with decoded bounds', function () {
    $screen = Native::test(ActionPipelineScreen::class);

    fireKinded($screen, "loadRange('rows')", 'virtual_window', ['type' => 2, 'text' => '5,9']);

    expect($screen->instance()->actions)->toBe([['virtual_window', 'loadRange', ['rows', 5, 9]]])
        ->and($screen->instance()->ran)->toBe(['loadRange:rows:5-9']);
});

it('routes a text_selection callback through callAction with decoded offsets', function () {
    $screen = Native::test(ActionPipelineScreen::class);

    fireKinded($screen, "selectionChanged('field')", 'text_selection', [
        'type' => 2,
        'text' => "1,4\x1Fhello",
    ]);

    expect($screen->instance()->actions)->toBe([['text_selection', 'selectionChanged', ['field', 'hello', 1, 4]]])
        ->and($screen->instance()->ran)->toBe(['selectionChanged:field:hello:1-4']);
});

it('routes a drag_end callback through callAction with the decoded translation', function () {
    $screen = Native::test(ActionPipelineScreen::class);

    fireKinded($screen, "dragged('card')", 'drag_end', ['type' => 2, 'text' => '12.5,-3']);

    expect($screen->instance()->actions)->toBe([['drag_end', 'dragged', ['card', 12.5, -3.0]]])
        ->and($screen->instance()->ran)->toBe(['dragged:card:12.5,-3']);
});

it('routes a fragment client callback through callAction with its args as sent', function () {
    $screen = Native::test(ActionPipelineScreen::class);

    tapFragmentClientAction($screen);

    expect($screen->instance()->actions)->toBe([['fragment_client', 'pick', ['red', 2]]])
        ->and($screen->instance()->ran)->toBe(['pick:red:2']);
});

// ── No route bypasses the chokepoint ────────────────

it('reaches no handler by any route when callAction declines to delegate', function () {
    $screen = Native::test(ActionPipelineScreen::class);
    $screen->instance()->blockActions = true;

    $screen->tap('bump')->emitNative('pinged', ['who' => 'device']);

    firePollsViaRunloop($screen);

    inComponent($screen, function () {
        /** @var NativeComponent $this */
        $this->dispatchNativeEvent(['event' => 'scalar-ping', 'payload' => 'boom']);
        $this->nativeChildComponents[array_key_first($this->nativeChildComponents)]->speak();
    });

    fireKinded($screen, 'onSearchQuery', 'search_query', ['type' => 2, 'text' => 'nat']);
    fireKinded($screen, "loadRange('rows')", 'virtual_window', ['type' => 2, 'text' => '5,9']);
    fireKinded($screen, "selectionChanged('field')", 'text_selection', ['type' => 2, 'text' => "1,4\x1Fhello"]);
    fireKinded($screen, "dragged('card')", 'drag_end', ['type' => 2, 'text' => '1,2']);
    tapFragmentClientAction($screen);

    // Every source was seen...
    expect($screen->instance()->sources())->toBe([
        'interaction',
        'native_event',
        'poll',
        'poll',
        'native_event',
        'parent_binding',
        'component_event',
        'search_query',
        'virtual_window',
        'text_selection',
        'drag_end',
        'fragment_client',
    ]);

    // ...and every case of the enum was among them, so a new
    // source cannot be added without a route test here.
    expect(array_values(array_unique($screen->instance()->sources())))
        ->toEqualCanonicalizing(array_column(ActionSource::cases(), 'value'));

    // ...and not one handler ran, so no route around callAction exists.
    expect($screen->instance()->ran)->toBe([])
        ->and($screen->instance()->count)->toBe(0)
        ->and($screen->searchResults())->toBe([]);
});

// ── The harness drives the same chokepoint ──────────

it('routes the harness call/firePoll/search verbs through callAction too', function () {
    $screen = Native::test(ActionPipelineScreen::class)
        ->call('bump')
        ->firePoll('tick')
        ->firePolls()
        ->search('nat');

    expect($screen->instance()->sources())->toBe(['interaction', 'poll', 'poll', 'poll', 'search_query'])
        ->and($screen->instance()->ran)->toBe(['bump', 'tick', 'protectedTick', 'tick', 'onSearchQuery'])
        ->and($screen->searchResults())->toBe(['hit:nat']);
});

// ── Names chosen outside the class are guarded ──────

/*
 * A template expression or a device event picks the method name on these
 * routes, so the name must not reach anything the developer did not make a
 * public action: not a protected method, not a lifecycle hook, not one of
 * NativeComponent's own public methods. On a device the template is trusted
 * code, but the same rule is what keeps a remote renderer (asgard) from
 * turning a callback into a call to an arbitrary method.
 */
dataset('guarded kinds', [
    'tap' => [null, ['type' => 0]],
    'search_query' => ['search_query', ['type' => 2, 'text' => 'x']],
    'virtual_window' => ['virtual_window', ['type' => 2, 'text' => '1,2']],
    'text_selection' => ['text_selection', ['type' => 2, 'text' => "0,0\x1Fx"]],
    'drag_end' => ['drag_end', ['type' => 2, 'text' => '1,2']],
]);

it('refuses a protected method named by a template callback', function (?string $kind, array $event) {
    $screen = Native::test(ActionPipelineScreen::class);

    expect(fn () => fireKinded($screen, 'secret', $kind ?? '', $event))
        ->toThrow(ComponentMethodNotFoundException::class);

    expect($screen->instance()->ran)->toBe([]);
})->with('guarded kinds');

it('refuses a lifecycle hook named by a template callback', function (?string $kind, array $event) {
    $screen = Native::test(ActionPipelineScreen::class);

    expect(fn () => fireKinded($screen, 'hydrateThing', $kind ?? '', $event))
        ->toThrow(DirectlyCallingLifecycleHooksNotAllowedException::class);
})->with('guarded kinds');

it('refuses NativeComponent\'s own public methods named by a template callback', function (string $method) {
    $screen = Native::test(ActionPipelineScreen::class);

    expect(fn () => fireKinded($screen, $method, '', ['type' => 0]))
        ->toThrow(ComponentMethodNotFoundException::class);
})->with(['stop', 'run', 'dispatch', 'query']);

it('refuses a protected method named by a child\'s @event binding', function () {
    $screen = Native::test(ActionPipelineScreen::class);

    inComponent($screen, function () {
        /** @var NativeComponent $this */
        $child = $this->nativeChildComponents[array_key_first($this->nativeChildComponents)];
        Closure::bind(fn () => $this->nativeChildEventBindings['child-said'] = 'secret', $child, NativeComponent::class)();
        $child->emit('child-said', 'hi');
    });
})->throws(ComponentMethodNotFoundException::class);

it('keeps the guard when a callAction() override never calls parent', function () {
    $screen = Native::test(ActionPipelineScreen::class);
    $screen->instance()->bypassParent = true;

    expect(fn () => fireKinded($screen, 'secret', '', ['type' => 0]))
        ->toThrow(ComponentMethodNotFoundException::class);

    // The override never saw the call, so it had no chance to run it.
    expect($screen->instance()->actions)->toBe([])
        ->and($screen->instance()->ran)->toBe([]);
});

it('still runs a protected #[Poll] method, since an attribute names it', function () {
    $screen = Native::test(ActionPipelineScreen::class)->firePoll('protectedTick');

    expect($screen->instance()->ran)->toBe(['protectedTick']);
});

it('guards every source a template or the device names, and only those', function () {
    $guarded = collect(ActionSource::cases())
        ->mapWithKeys(fn (ActionSource $source) => [$source->value => $source->guarded()])
        ->all();

    // Changing an answer here widens or narrows what remote input can
    // reach. Make it a deliberate, reviewed change.
    expect($guarded)->toBe([
        'native_event' => true,
        'poll' => false,
        'component_event' => false,
        'parent_binding' => true,
        'interaction' => true,
        'search_query' => true,
        'virtual_window' => true,
        'text_selection' => true,
        'drag_end' => true,
        'fragment_client' => true,
    ]);
});
