<?php

use Illuminate\Support\Facades\Event;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\TextInput;
use Native\Mobile\Edge\Exceptions\ComponentSlotNotSupportedException;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\System\AppearanceChanged;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\BadgeChild;
use Tests\Fixtures\Edge\NestedHostScreen;
use Tests\Fixtures\Edge\ProgrammaticChild;
use Tests\Fixtures\Edge\ProgrammaticHostScreen;
use Tests\Fixtures\Edge\SlotHostScreen;
use Tests\Fixtures\Edge\UnkeyedHostScreen;
use Tests\Fixtures\Edge\UnknownTagScreen;
use Tests\Fixtures\Edge\UserCardChild;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    // `text_input` normally comes from a UI plugin; the child fixture's
    // native:model coverage needs a change-capable element.
    ElementRegistry::register('text_input', TextInput::class);

    ComponentRegistry::reset();
    ComponentRegistry::components([
        'user-card-child' => UserCardChild::class,
        'badge-child' => BadgeChild::class,
        'programmatic-child' => ProgrammaticChild::class,
    ]);

    UserCardChild::$events = [];
});

afterEach(function () {
    ComponentRegistry::reset();
});

/** The live child instances of a component, keyed by identity. */
function nestedChildrenOf(NativeComponent $component): array
{
    return (fn () => $this->nativeChildComponents)->call($component);
}

// ── Mounting & props ────────────────────────────────

it('mounts a registered component tag with literal and bound props', function () {
    Native::test(NestedHostScreen::class)
        ->assertSee('Card: solo L3 C0')   // literal props ('3' coerced to int)
        ->assertSee('Card: a L0 C0')      // bound props from the @foreach
        ->assertSee('Card: b L1 C0');
});

it('calls mount() once per new child, after props are assigned', function () {
    Native::test(NestedHostScreen::class)
        ->set('title', 'Again'); // second frame — no re-mount

    $mounts = array_filter(UserCardChild::$events, fn ($e) => str_starts_with($e, 'mount:'));

    expect(array_values($mounts))->toBe(['mount:solo', 'mount:a', 'mount:b']);
});

it('does not treat unregistered tags as components', function () {
    Native::test(UnknownTagScreen::class);
})->throws(RuntimeException::class, 'Unknown native element type: totally_unregistered_thing');

it('throws a clear exception for slot content inside a component tag', function () {
    Native::test(SlotHostScreen::class);
})->throws(ComponentSlotNotSupportedException::class, 'do not support slot content');

// ── State & reactivity ──────────────────────────────

it('persists child state across parent re-renders while props update', function () {
    $screen = Native::test(NestedHostScreen::class)
        ->tap('bump-a')
        ->assertSee('Card: a L0 C1')
        ->set('title', 'Updated')          // parent state change → re-render
        ->assertSee('Updated')
        ->assertSee('Card: a L0 C1');      // child state survived

    // Reorder: the keyed card keeps its clicks but receives fresh props
    // (level now reflects its new position).
    $screen->set('names', ['b', 'a'])
        ->assertSee('Card: a L1 C1')
        ->assertSee('Card: b L0 C0');
});

it('dispatches @tap inside a child to the child method with the child as $this', function () {
    $screen = Native::test(NestedHostScreen::class)
        ->tap('bump-b')
        ->assertSee('Card: b L1 C1');

    $children = nestedChildrenOf($screen->instance());

    expect($children['user-card-child|key:card-b']->clicks)->toBe(1);
    expect($children['user-card-child|key:card-a']->clicks)->toBe(0);
});

it('syncs native:model inside a child to the child property and fires its hook', function () {
    $screen = Native::test(NestedHostScreen::class)
        ->input('note-solo', 'hello child');

    $child = nestedChildrenOf($screen->instance())['user-card-child|i:0'];

    expect($child->note)->toBe('hello child');
    expect($child->lastHook)->toBe('note:hello child');
    expect($screen->instance()->title)->toBe('Host'); // screen untouched
});

// ── Identity ────────────────────────────────────────

it('keeps state with the key when keyed children reorder', function () {
    $screen = Native::test(NestedHostScreen::class)
        ->tap('bump-a')
        ->set('names', ['b', 'a']);

    $children = nestedChildrenOf($screen->instance());

    expect($children['user-card-child|key:card-a']->clicks)->toBe(1);
    expect($children['user-card-child|key:card-a']->name)->toBe('a');
});

it('keeps state with the position when unkeyed children reorder', function () {
    $screen = Native::test(UnkeyedHostScreen::class)
        ->tap('bump-a')                      // occurrence 0 gets a click
        ->set('names', ['b', 'a']);

    $children = nestedChildrenOf($screen->instance());

    // Positional contract: occurrence 0 kept its instance (and click),
    // but now renders the reordered data — the click "moved" to b.
    expect($children['user-card-child|i:0']->clicks)->toBe(1);
    expect($children['user-card-child|i:0']->name)->toBe('b');
    expect($children['user-card-child|i:1']->clicks)->toBe(0);
    expect($children['user-card-child|i:1']->name)->toBe('a');
});

it('gives unkeyed same-tag occurrences independent state', function () {
    $screen = Native::test(UnkeyedHostScreen::class)
        ->tap('bump-a')
        ->tap('bump-a')
        ->assertSee('Card: a L0 C2')
        ->assertSee('Card: b L0 C0');

    $children = nestedChildrenOf($screen->instance());

    expect($children['user-card-child|i:0']->clicks)->toBe(2);
    expect($children['user-card-child|i:1']->clicks)->toBe(0);
});

// ── Unmount ─────────────────────────────────────────

it('unmounts a child whose tag disappears from the render', function () {
    $screen = Native::test(NestedHostScreen::class)
        ->set('showCard', false)
        ->assertDontSee('Card: solo');

    expect(UserCardChild::$events)->toContain('unmount:solo');
    expect(nestedChildrenOf($screen->instance()))->not->toHaveKey('user-card-child|i:0');
});

it('remounts a returning identity as a fresh instance', function () {
    Native::test(NestedHostScreen::class)
        ->tap('bump-solo')
        ->assertSee('Card: solo L3 C1')
        ->set('showCard', false)
        ->set('showCard', true)
        ->assertSee('Card: solo L3 C0'); // state gone — new instance

    $mounts = array_filter(UserCardChild::$events, fn ($e) => $e === 'mount:solo');

    expect($mounts)->toHaveCount(2);
});

// ── Nesting ─────────────────────────────────────────

it('renders child-in-child components', function () {
    Native::test(NestedHostScreen::class)
        ->assertSee('Poke solo')
        ->assertSee('Poke a')
        ->assertSee('Poke b');
});

it('routes callbacks from a programmatic child render to the child instance', function () {
    $screen = Native::test(ProgrammaticHostScreen::class)
        ->assertSee('Programmatic taps: 0')
        ->tap('prog-tap')
        ->assertSee('Programmatic taps: 1');

    // Same method name exists on the screen — ownership is by registry.
    expect($screen->instance()->screenTaps)->toBe(0);
    expect(nestedChildrenOf($screen->instance())['programmatic-child|i:0']->taps)->toBe(1);
});

// ── Events up ───────────────────────────────────────

it('delivers emit() to the parent through the @event tag binding', function () {
    $screen = Native::test(NestedHostScreen::class)
        ->tap('bump-solo')
        ->tap('save-solo');

    expect($screen->instance()->savedEvents)->toBe(['tag:solo:1']);
});

it('keeps direct emit callbacks working for child templates', function () {
    $screen = Native::test(NestedHostScreen::class)
        ->tap('emit-solo');

    expect($screen->instance()->savedEvents)->toBe(['tag:direct:9']);
});

it('bubbles a grandchild emit() past its parent to the screen #[On] listener', function () {
    Native::test(NestedHostScreen::class)
        ->tap('poke-a')
        ->assertSet('pokes', ['badge-of-a'])
        ->assertSee('Pokes: badge-of-a')
        ->assertRerendered();
});

it('re-renders after an emit so handler state changes paint', function () {
    Native::test(NestedHostScreen::class)
        ->tap('save-solo')
        ->assertRerendered();
});

// ── Events down ─────────────────────────────────────

it('delivers a native event to child and grandchild #[On] listeners', function () {
    $screen = Native::test(NestedHostScreen::class)
        ->emitNative(AppearanceChanged::class, ['mode' => 'dark']);

    $children = nestedChildrenOf($screen->instance());
    $child = $children['user-card-child|i:0'];

    // The screen still receives it (parent and child both listen — no
    // stopPropagation), and it reaches a child and its grandchild.
    expect($screen->instance()->appearance)->toBe('dark');
    expect($child->appearance)->toBe('dark');
    expect(nestedChildrenOf($child)['badge-child|i:0']->appearance)->toBe('dark');
});

it('delivers a native event to every child in a keyed list', function () {
    $screen = Native::test(NestedHostScreen::class)
        ->emitNative(AppearanceChanged::class, ['mode' => 'dark']);

    $appearances = array_map(
        fn ($child) => $child->appearance,
        array_values(nestedChildrenOf($screen->instance())),
    );

    expect($appearances)->toBe(['dark', 'dark', 'dark']);
});

it('dispatches a globally-marked native event once, not once per component', function () {
    $seen = [];
    Event::listen(AppearanceChanged::class, function ($e) use (&$seen) {
        $seen[] = $e->mode;
    });

    Native::test(NestedHostScreen::class)
        ->emitNative(AppearanceChanged::class, ['mode' => 'dark']);

    expect($seen)->toBe(['dark']);
});
