<?php

use Native\Mobile\Edge\Asgard\ActionRequest;
use Native\Mobile\Edge\Asgard\ComponentAddress;
use Native\Mobile\Edge\Asgard\ServerRegion;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\Toggle;
use Native\Mobile\Edge\Exceptions\ComponentMethodNotFoundException;
use Native\Mobile\Edge\Exceptions\DirectlyCallingLifecycleHooksNotAllowedException;
use Native\Mobile\Edge\Exceptions\FragmentActionNotAllowedException;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Fixtures\Edge\FragmentActionChild;
use Tests\Fixtures\Edge\FragmentActionScreen;

/**
 * Taps inside a server-rendered fragment (docs/ASGARD-PROTOCOL.md §10).
 * A callback slot is `{"call", "args", "target"}`. A client target runs a
 * #[NativeAction] method on the component that owns the region; a server
 * target sends an ActionRequest and runs nothing here.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
    ComponentRegistry::reset();
    ComponentRegistry::components(['fragment-action-child' => FragmentActionChild::class]);
    ServerRegion::flush();
    config(['app.debug' => false]);
});

afterEach(function () {
    ComponentRegistry::reset();
    ServerRegion::flush();
});

/** A pressable fragment node labelled `$label` with one press callback. */
function pressableFragment(mixed $onPress, string $label = 'Go'): array
{
    return [
        'type' => 'pressable',
        'key' => 'go',
        'on_press' => $onPress,
        'children' => [['type' => 'text', 'props' => ['text' => $label]]],
    ];
}

/** Hand a component's region a fragment and draw the next frame. */
function paint(TestableComponent $screen, string $region, array $tree, ?NativeComponent $owner = null): TestableComponent
{
    expect(ServerRegion::applyFragment($region, $tree, $owner ?? $screen->instance()))->toBeTrue();

    // set() repaints without calling any action.
    return $screen->set('range', $screen->get('range'));
}

// ── Client target ───────────────────────────────────

it('runs a #[NativeAction] method for a client target with the args as sent', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::test(FragmentActionScreen::class);

    paint($screen, 'picks', pressableFragment(
        ['call' => 'celebrate', 'args' => ['ana', 3], 'target' => 'client'],
        'Celebrate',
    ))->tap('Celebrate');

    expect($screen->get('ran'))->toBe(['celebrate:ana:3']);

    // Never the server as well.
    $source->assertNoActionsSent();
});

it('sends a client action through callAction even when its name is an interaction shortcut', function () {
    $screen = Native::test(FragmentActionScreen::class);

    paint($screen, 'picks', pressableFragment(['call' => 'exitToWeb', 'args' => ['/x'], 'target' => 'client'], 'Leave'))
        ->tap('Leave');

    expect($screen->get('actions'))->toBe([['fragment_client', 'exitToWeb']]);
    expect($screen->get('ran'))->toBe(['exitToWeb:/x']);
});

it('appends the control value to a client on_change', function () {
    // Toggle ships with a UI plugin rather than core, so stand it up here.
    $registered = ElementRegistry::all();
    ElementRegistry::register('toggle', Toggle::class);

    $screen = Native::test(FragmentActionScreen::class);

    paint($screen, 'picks', [
        'type' => 'toggle',
        'key' => 'switch',
        'on_change' => ['call' => 'toggled', 'target' => 'client'],
    ]);

    $id = ServerRegion::registryFor('picks', $screen->instance())->expressions();
    expect($id)->toHaveCount(1);

    Closure::bind(function () use ($id) {
        /** @var NativeComponent $this */
        $this->dispatchUiEvent(['type' => 3, 'callback_id' => array_values($id)[0], 'value' => true]);
    }, $screen->instance(), NativeComponent::class)();

    expect($screen->get('ran'))->toBe(['toggled:on']);

    ElementRegistry::reset();
    foreach ($registered as $type => $class) {
        ElementRegistry::register($type, $class);
    }
});

dataset('methods a fragment may not call', [
    'public but not #[NativeAction]' => ['unmarked', [], FragmentActionNotAllowedException::class],
    'NativeComponent\'s dispatch' => ['dispatch', ['x'], ComponentMethodNotFoundException::class],
    'NativeComponent\'s navigate' => ['navigate', ['/admin'], ComponentMethodNotFoundException::class],
    'NativeComponent\'s refreshServerRegions' => ['refreshServerRegions', [], ComponentMethodNotFoundException::class],
    'a lifecycle hook, even marked' => ['hydrateRange', [], DirectlyCallingLifecycleHooksNotAllowedException::class],
    'mount' => ['mount', [], DirectlyCallingLifecycleHooksNotAllowedException::class],
    'render' => ['render', [], ComponentMethodNotFoundException::class],
    'protected, even marked' => ['hidden', [], ComponentMethodNotFoundException::class],
    'a method that does not exist' => ['nope', [], ComponentMethodNotFoundException::class],
    'a class-typed parameter' => ['withObject', [['a' => 1]], FragmentActionNotAllowedException::class],
    'an optional class-typed parameter, given nothing' => ['withOptionalObject', [], FragmentActionNotAllowedException::class],
    'a callable parameter' => ['withCallable', ['phpinfo'], FragmentActionNotAllowedException::class],
    'a by-reference parameter' => ['byReference', [1], FragmentActionNotAllowedException::class],
    'an argument of the wrong type' => ['celebrate', ['ana', 'lots'], FragmentActionNotAllowedException::class],
    'too few arguments' => ['celebrate', [], FragmentActionNotAllowedException::class],
]);

it('runs nothing for a client target it may not call, and logs instead of throwing', function (string $call, array $args) {
    $source = Native::fakeFragmentSource();
    $screen = Native::test(FragmentActionScreen::class);

    paint($screen, 'picks', pressableFragment(['call' => $call, 'args' => $args, 'target' => 'client'], 'Try it'))
        ->tap('Try it');

    expect($screen->get('ran'))->toBe([]);
    $source->assertNoActionsSent();
})->with('methods a fragment may not call');

it('throws for a client target it may not call while debugging', function (string $call, array $args, string $exception) {
    $screen = Native::test(FragmentActionScreen::class);
    paint($screen, 'picks', pressableFragment(['call' => $call, 'args' => $args, 'target' => 'client'], 'Try it'));

    config(['app.debug' => true]);

    expect(fn () => $screen->tap('Try it'))->toThrow($exception);
    expect($screen->get('ran'))->toBe([]);
})->with('methods a fragment may not call');

it('runs a client action on the child that owns the region, not the screen', function () {
    $screen = Native::test(FragmentActionScreen::class)->set('withChild', true);

    $child = Closure::bind(
        fn () => $this->nativeChildComponents[array_key_first($this->nativeChildComponents)],
        $screen->instance(),
        NativeComponent::class,
    )();

    expect($child)->toBeInstanceOf(FragmentActionChild::class);

    paint($screen, 'picks', pressableFragment(['call' => 'celebrate', 'args' => ['kid'], 'target' => 'client'], 'Child party'), $child)
        ->tap('Child party');

    expect($child->ran)->toBe(['celebrate:kid:1']);
    expect($screen->get('ran'))->toBe([]);
});

// ── Server target ───────────────────────────────────

it('sends exactly one ActionRequest for a server target and runs nothing here', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::test(FragmentActionScreen::class);

    $source->respond('picks', pressableFragment(['call' => 'shuffle', 'args' => [3, 'x']], 'Shuffle'));

    // The state at the time of the tap goes in the request.
    $screen->set('range', '30d')->tap('Shuffle');

    $source->assertActionSent('picks', 'shuffle', times: 1);
    expect($source->actions())->toHaveCount(1);

    $action = $source->lastAction('picks');

    expect($action)->toBeInstanceOf(ActionRequest::class);
    expect($action->toArray())->toBe([
        'component' => ComponentAddress::for($screen->instance()),
        'region' => 'picks',
        'state' => ['range' => '30d', 'withChild' => false, 'ran' => [], 'actions' => []],
        'platform' => 'unknown',
        'action' => ['call' => 'shuffle', 'args' => [3, 'x']],
    ]);

    // The component has a #[NativeAction] shuffle(), and it did not run.
    expect($screen->get('ran'))->toBe([]);

    // An action is not a region request.
    $source->assertRequested('picks', times: 1);
});

it('treats an explicit server target like the default', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::test(FragmentActionScreen::class);

    paint($screen, 'picks', pressableFragment(['call' => 'celebrate', 'args' => ['ana', 1], 'target' => 'server'], 'Party'))
        ->tap('Party');

    $source->assertActionSent('picks', 'celebrate', times: 1);
    expect($screen->get('ran'))->toBe([]);
});

it('repaints the region with the answer to an action', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::test(FragmentActionScreen::class);

    $source->respond('picks', pressableFragment(['call' => 'shuffle'], 'Shuffle'));
    $screen->set('range', '7d')->tap('Shuffle');

    expect($source->respondToAction('picks', ['type' => 'text', 'props' => ['text' => 'Mixed up: 3, 1, 2']]))->toBeTrue();

    $screen->set('range', '7d')
        ->assertSee('Mixed up: 3, 1, 2')
        ->assertDontSee('Shuffle')
        ->assertDontSee('Retry picks');
});

it('drops a region answer that an action superseded', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::test(FragmentActionScreen::class);

    $source->respond('picks', pressableFragment(['call' => 'shuffle'], 'Shuffle'));

    $screen->instance()->refreshServerRegions('picks');
    $screen->set('range', '7d');
    $refresh = $source->lastRequest('picks');

    $screen->tap('Shuffle');

    expect($source->respond($refresh, ['type' => 'text', 'props' => ['text' => 'Old render']]))->toBeFalse();
    expect($source->respondToAction('picks', ['type' => 'text', 'props' => ['text' => 'After shuffle']]))->toBeTrue();

    $screen->set('range', '7d')->assertSee('After shuffle')->assertDontSee('Old render');
});

// ── Bad slots ───────────────────────────────────────

dataset('bad callback slots', [
    'a string expression' => ['shuffle'],
    'a string with args' => ["celebrate('ana')"],
    'an int' => [42],
    'an empty object' => [[]],
    'a list' => [['shuffle']],
    'no call' => [['args' => []]],
    'a call that is not a method name' => [['call' => 'celebrate()']],
    'a call with a namespace' => [['call' => 'App\\Foo::bar']],
    'a call over 100 characters' => [['call' => str_repeat('a', 101)]],
    'args that are not a list' => [['call' => 'shuffle', 'args' => ['a' => 1]]],
    'args as a string' => [['call' => 'shuffle', 'args' => 'x']],
    'too many args' => [['call' => 'shuffle', 'args' => range(1, 17)]],
    'an arg nested five deep' => [['call' => 'shuffle', 'args' => [[[[[[1]]]]]]]],
    'an object in args' => [['call' => 'shuffle', 'args' => [new stdClass]]],
    'a non-finite float in args' => [['call' => 'shuffle', 'args' => [INF]]],
    'an unknown target' => [['call' => 'shuffle', 'target' => 'elsewhere']],
    'an unknown key' => [['call' => 'shuffle', 'id' => 7]],
]);

it('drops a bad callback slot and renders the node without it', function (mixed $slot) {
    $source = Native::fakeFragmentSource();
    $screen = Native::test(FragmentActionScreen::class);

    paint($screen, 'picks', pressableFragment($slot, 'Broken'))->assertSee('Broken');

    expect(ServerRegion::registryFor('picks', $screen->instance())->expressions())->toBe([]);
    expect(fn () => $screen->tap('Broken'))->toThrow(PHPUnit\Framework\AssertionFailedError::class);

    expect($screen->get('ran'))->toBe([]);
    $source->assertNoActionsSent();
})->with('bad callback slots');

it('throws on a bad callback slot while debugging', function (mixed $slot) {
    config(['app.debug' => true]);

    expect(fn () => ServerRegion::applyFragment('picks', pressableFragment($slot)))
        ->toThrow(RuntimeException::class, "has a bad on_press on a 'pressable'");
})->with('bad callback slots');

it('accepts the limits exactly', function () {
    $screen = Native::test(FragmentActionScreen::class);
    $source = Native::fakeFragmentSource();

    // The first arg nests four lists deep, the most allowed.
    $args = [[[[[1]]]], null, true, 1.5, 'x', ['k' => ['v']], ...range(1, 10)];
    expect($args)->toHaveCount(16);

    paint($screen, 'picks', pressableFragment(['call' => str_repeat('a', 100), 'args' => $args], 'Limits'))
        ->tap('Limits');

    expect($source->lastAction('picks')->args)->toBe($args);
});

it('checks callback slots nested anywhere in the fragment', function () {
    $screen = Native::test(FragmentActionScreen::class);

    paint($screen, 'picks', [
        'type' => 'column',
        'children' => [
            ['type' => 'column', 'children' => [pressableFragment('celebrate', 'Deep')]],
        ],
    ]);

    expect(ServerRegion::registryFor('picks', $screen->instance())->expressions())->toBe([]);
    expect(fn () => $screen->tap('Deep'))->toThrow(PHPUnit\Framework\AssertionFailedError::class);
});

// ── Scopes ──────────────────────────────────────────

it('leaves placeholder callbacks working exactly as before', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(FragmentActionScreen::class)
        ->assertSee('Retry picks')
        ->tap('Retry picks');

    // An ordinary, unmarked client method, reached from client markup.
    expect($screen->get('ran'))->toBe(['retry']);
    $source->assertNoActionsSent();
});

it('keeps the same callback in two regions apart', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::test(FragmentActionScreen::class);

    ServerRegion::applyFragment('picks', pressableFragment(['call' => 'shuffle'], 'Shuffle picks'), $screen->instance());
    paint($screen, 'extras', pressableFragment(['call' => 'shuffle'], 'Shuffle extras'));

    $picks = ServerRegion::registryFor('picks', $screen->instance());
    $extras = ServerRegion::registryFor('extras', $screen->instance());

    expect(array_values($picks->expressions()))->not->toBe(array_values($extras->expressions()));

    $screen->tap('Shuffle extras');

    expect(array_map(fn (ActionRequest $a) => $a->region, $source->actions()))->toBe(['extras']);
});

it('derives a child region\'s ids in the child\'s scope', function () {
    $screen = Native::test(FragmentActionScreen::class)->set('withChild', true);

    $child = Closure::bind(
        fn () => $this->nativeChildComponents[array_key_first($this->nativeChildComponents)],
        $screen->instance(),
        NativeComponent::class,
    )();

    $slot = ['call' => 'celebrate', 'args' => ['x'], 'target' => 'client'];
    ServerRegion::applyFragment('picks', pressableFragment($slot, 'Screen party'), $screen->instance());
    paint($screen, 'picks', pressableFragment($slot, 'Child party'), $child);

    $screenScope = ServerRegion::registryFor('picks', $screen->instance());
    $childScope = ServerRegion::registryFor('picks', $child);

    expect($screenScope->scope())->toBe('server:picks');
    expect($childScope->scope())->toEndWith('>server:picks')->not->toBe('server:picks');
    expect(array_values($screenScope->expressions()))->not->toBe(array_values($childScope->expressions()));

    $screen->tap('Screen party');
    expect($screen->get('ran'))->toBe(['celebrate:x:1']);
    expect($child->ran)->toBe([]);
});

it('never resolves a fragment id in the screen registry, or a screen id in a fragment registry', function () {
    $screen = Native::test(FragmentActionScreen::class);

    paint($screen, 'picks', pressableFragment(['call' => 'unmarked', 'target' => 'client'], 'Fragment button'));

    $fragment = ServerRegion::registryFor('picks', $screen->instance());
    $screenRegistry = Closure::bind(fn () => $this->nativeCallbacks, $screen->instance(), NativeComponent::class)();

    $fragmentId = array_values($fragment->expressions())[0];
    $screenId = $screenRegistry->lookup('unmarked');

    expect($screenRegistry->resolve($fragmentId))->toBeNull();
    expect($fragment->resolve($screenId))->toBeNull();

    // A fragment naming the screen button's method gets nothing from it.
    $screen->tap('Fragment button');
    expect($screen->get('ran'))->toBe([]);

    // The screen's own button still works.
    $screen->tap('Screen button');
    expect($screen->get('ran'))->toBe(['unmarked']);
});

it('drops a tap on an id that both a fragment and the screen claim', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::test(FragmentActionScreen::class);

    paint($screen, 'picks', pressableFragment(['call' => 'shuffle'], 'Shuffle'));

    // A server that brute-forced an expression whose id equals the screen
    // button's would make the two collide. Stand that up directly.
    $fragmentId = array_values(ServerRegion::registryFor('picks', $screen->instance())->expressions())[0];
    $screenRegistry = Closure::bind(fn () => $this->nativeCallbacks, $screen->instance(), NativeComponent::class)();
    Closure::bind(fn () => $this->map[$fragmentId] = ['method' => 'unmarked', 'args' => []], $screenRegistry, CallbackRegistry::class)();

    $screen->tap('Shuffle');

    expect($screen->get('ran'))->toBe([]);
    $source->assertNoActionsSent();
});

it('forgets a replaced fragment\'s callbacks', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::test(FragmentActionScreen::class);

    paint($screen, 'picks', pressableFragment(['call' => 'shuffle'], 'Shuffle'));
    $old = array_values(ServerRegion::registryFor('picks', $screen->instance())->expressions())[0];

    paint($screen, 'picks', ['type' => 'text', 'props' => ['text' => 'No buttons now']]);

    Closure::bind(function () use ($old) {
        /** @var NativeComponent $this */
        $this->dispatchUiEvent(['type' => 0, 'callback_id' => $old]);
    }, $screen->instance(), NativeComponent::class)();

    $source->assertNoActionsSent();
});
