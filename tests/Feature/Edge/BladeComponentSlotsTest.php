<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Compilers\BladeCompiler;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\TextInput;
use Native\Mobile\Edge\Exceptions\ComponentSlotNotSupportedException;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeTagPrecompiler;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\BadgeChild;
use Tests\Fixtures\Edge\HiddenBladeComponent;
use Tests\Fixtures\Edge\LayoutSlotScreen;
use Tests\Fixtures\Edge\SlotCallbacksScreen;
use Tests\Fixtures\Edge\UserCardChild;

/**
 * Ordinary Blade components (`<x-card>`) in native views. Blade renders
 * a component's slot content before the component's own view, so the
 * collector parks the slot's elements and places them where the view
 * echoes the slot. These pin where the elements end up.
 *
 * The component fixtures live in tests/Fixtures/views/components.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    NativeElementCollector::reset();
    NativeTagPrecompiler::setActive(true);

    // `button` and `text_input` normally come from a UI plugin. TestCase
    // resets the registry after each test.
    ElementRegistry::swap('button', Button::class);
    ElementRegistry::swap('text_input', TextInput::class);
});

afterEach(function () {
    NativeTagPrecompiler::setActive(false);
    NativeElementCollector::reset();
    ComponentRegistry::reset();
});

/**
 * A tree reduced to what placement tests care about: each node is its
 * type (plus its text or label), mapped to its children when it has any.
 */
function slotShape(array $node): array|string
{
    $name = $node['type'];

    foreach (['text', 'label', 'title'] as $prop) {
        if (isset($node['props'][$prop]) && $node['props'][$prop] !== '') {
            $name .= ':'.$node['props'][$prop];

            break;
        }
    }

    $children = array_map('slotShape', $node['children'] ?? []);

    return $children === [] ? $name : [$name => $children];
}

/** The collector's slot bookkeeping, which must be empty between renders. */
function slotState(): array
{
    return (fn () => [
        static::$slotFrames,
        static::$slotComponents,
        static::$slots,
        static::$echoableSlots,
    ])->bindTo(null, NativeElementCollector::class)();
}

// ── Default slot ─────────────────────────────────────

it('places the default slot inside the container that echoes it', function () {
    $tree = renderEdgeTree(<<<'BLADE'
        <native:column>
            <x-box.in-row>
                <native:text>One</native:text>
                <native:text>Two</native:text>
            </x-box.in-row>
        </native:column>
        BLADE);

    expect(slotShape($tree))->toBe(['column' => [['row' => ['text:One', 'text:Two']]]]);
});

it('places the default slot between the component\'s own root elements', function () {
    $tree = renderEdgeTree(<<<'BLADE'
        <native:column>
            <x-box.between>
                <native:text>Middle</native:text>
            </x-box.between>
        </native:column>
        BLADE);

    expect(slotShape($tree))->toBe(['column' => ['text:Before', 'text:Middle', 'text:After']]);
});

it('places a default slot echoed as the last thing in the component view', function () {
    $tree = renderEdgeTree(<<<'BLADE'
        <native:column>
            <x-box.last>
                <native:text>Body</native:text>
            </x-box.last>
            <native:text>Sibling</native:text>
        </native:column>
        BLADE);

    expect(slotShape($tree))->toBe(['column' => ['text:Header', 'text:Body', 'text:Sibling']]);
});

it('places a root-level slot when the component is the whole view', function () {
    $tree = renderEdgeTree('<x-box.last><native:text>Body</native:text></x-box.last>');

    // Two roots, so the collector wraps them in its implicit column.
    expect(slotShape($tree))->toBe(['column' => ['text:Header', 'text:Body']]);
});

it('keeps slot elements ahead of an include or component that follows the slot', function (string $component, string $after) {
    $tree = renderEdgeTree(
        "<native:column><x-box.{$component}><native:text>Body</native:text></x-box.{$component}></native:column>"
    );

    expect(slotShape($tree))->toBe(['column' => ['text:Body', $after]]);
})->with([
    'include' => ['then-include', 'text:Partial content'],
    'component' => ['then-component', 'text:Footer'],
]);

// ── Named slots ──────────────────────────────────────

it('places named and default slots where each is echoed', function (string $open, string $close) {
    $tree = renderEdgeTree(<<<BLADE
        <native:column>
            <x-box.named>
                {$open}
                    <native:text>Action</native:text>
                {$close}

                <native:text>Body</native:text>
            </x-box.named>
        </native:column>
        BLADE);

    expect(slotShape($tree))->toBe(['column' => [
        ['row' => ['text:Title', ['stack' => ['text:Action']]]],
        ['column' => ['text:Body']],
    ]]);
})->with([
    'x-slot:name' => ['<x-slot:actions>', '</x-slot:actions>'],
    'x-slot name=""' => ['<x-slot name="actions">', '</x-slot>'],
]);

it('drops a slot the component never echoes', function () {
    $tree = renderEdgeTree(<<<'BLADE'
        <native:column>
            <x-box.in-row>
                <x-slot:unused>
                    <native:text>Never shown</native:text>
                </x-slot:unused>

                <native:text>Body</native:text>
            </x-box.in-row>
        </native:column>
        BLADE);

    expect(slotShape($tree))->toBe(['column' => [['row' => ['text:Body']]]]);

    // Nothing of the dropped slot is left to leak into the next render.
    expect(slotState())->toBe([[], [], [], []]);

    $next = renderEdgeTree('<native:column><native:text>Next</native:text></native:column>');

    expect(slotShape($next))->toBe(['column' => ['text:Next']]);
});

it('leaves a string slot passed through the @slot directive alone', function () {
    $tree = renderEdgeTree(<<<'BLADE'
        <native:column>
            <x-box.titled>
                @slot('title', 'Short title')

                <native:text>Body</native:text>
            </x-box.titled>
        </native:column>
        BLADE);

    expect(slotShape($tree))->toBe(['column' => ['text:Short title', ['column' => ['text:Body']]]]);
});

// ── Echo forms ───────────────────────────────────────

it('places a slot echoed unescaped', function () {
    $tree = renderEdgeTree('<x-box.raw><native:text>Body</native:text></x-box.raw>');

    expect(slotShape($tree))->toBe(['column' => ['text:Body']]);
});

it('supports isset, isNotEmpty and null-coalescing on slots', function () {
    $both = renderEdgeTree(<<<'BLADE'
        <native:stack>
            <x-box.optional>
                <x-slot:actions><native:text>Action</native:text></x-slot:actions>
                <native:text>Body</native:text>
            </x-box.optional>
        </native:stack>
        BLADE);

    expect(slotShape($both))->toBe(['stack' => [
        ['row' => ['text:Action']],
        ['column' => ['text:Body']],
    ]]);

    // No actions slot and nothing in the default one: neither wrapper renders.
    $neither = renderEdgeTree('<native:stack><x-box.optional></x-box.optional></native:stack>');

    expect(slotShape($neither))->toBe('stack');
});

it('places a slot once when the component echoes it twice', function () {
    $tree = renderEdgeTree('<native:stack><x-box.twice><native:text>Body</native:text></x-box.twice></native:stack>');

    expect(slotShape($tree))->toBe(['stack' => [['row' => ['text:Body']], 'column']]);
});

// ── Text ─────────────────────────────────────────────

it('keeps a text-only slot as the text of the element that echoes it', function () {
    $tree = renderEdgeTree('<native:column><x-box.heading>Hello &amp; welcome</x-box.heading></native:column>');

    expect(slotShape($tree))->toBe(['column' => ['text:Hello & welcome']]);
    expect($tree['children'][0]['props']['font_size'] ?? null)->not->toBeNull();
});

it('never leaks a slot marker into text when elements are echoed inside a text element', function (string $component, string $type) {
    $tree = renderEdgeTree(
        "<native:column><x-box.{$component}><native:spacer /> Save</x-box.{$component}></native:column>"
    );

    // The element cannot live inside the string, so it sits ahead of it.
    expect(slotShape($tree))->toBe(['column' => ['spacer', "{$type}:Save"]]);
})->with([
    'text' => ['heading', 'text'],
    'button' => ['button', 'button'],
]);

// ── Nesting ──────────────────────────────────────────

it('places a component used inside another component\'s slot', function () {
    $tree = renderEdgeTree(<<<'BLADE'
        <native:column>
            <x-box.between>
                <x-box.in-row>
                    <native:text>Deep</native:text>
                </x-box.in-row>
            </x-box.between>
        </native:column>
        BLADE);

    expect(slotShape($tree))->toBe(['column' => [
        'text:Before',
        ['row' => ['text:Deep']],
        'text:After',
    ]]);
});

it('places a slot that a component hands on to another component', function () {
    $tree = renderEdgeTree(<<<'BLADE'
        <native:column>
            <x-box.pass-on>
                <native:text>Handed on</native:text>
            </x-box.pass-on>
        </native:column>
        BLADE);

    expect(slotShape($tree))->toBe(['column' => [
        'text:Outer',
        ['row' => ['text:Handed on']],
    ]]);
});

it('keeps every component in a loop separate', function () {
    $tree = renderEdgeTree(<<<'BLADE'
        <native:column>
            @foreach (['a', 'b', 'c'] as $letter)
                <x-box.in-row>
                    <native:text>{{ $letter }}</native:text>
                </x-box.in-row>
            @endforeach
        </native:column>
        BLADE);

    expect(slotShape($tree))->toBe(['column' => [
        ['row' => ['text:a']],
        ['row' => ['text:b']],
        ['row' => ['text:c']],
    ]]);
});

it('skips a component that declines to render without disturbing its neighbours', function () {
    Blade::component('hidden-thing', HiddenBladeComponent::class);

    $tree = renderEdgeTree(<<<'BLADE'
        <native:column>
            <x-box.in-row>
                <x-hidden-thing>
                    <native:text>Never</native:text>
                </x-hidden-thing>
                <native:text>Shown</native:text>
            </x-box.in-row>
            <x-hidden-thing />
            <x-box.in-row><native:text>Also shown</native:text></x-box.in-row>
        </native:column>
        BLADE);

    expect(slotShape($tree))->toBe(['column' => [
        ['row' => ['text:Shown']],
        ['row' => ['text:Also shown']],
    ]]);
    expect(slotState())->toBe([[], [], [], []]);
});

it('still nests the children of legacy x-native element components', function () {
    $tree = renderEdgeTree(<<<'BLADE'
        <x-native-column>
            <x-native-row>
                <native:text>A</native:text>
                <x-native-spacer />
            </x-native-row>
            <x-box.in-row><x-native-text>B</x-native-text></x-box.in-row>
        </x-native-column>
        BLADE);

    expect(slotShape($tree))->toBe(['column' => [
        ['row' => ['text:A', 'spacer']],
        ['row' => ['text:B']],
    ]]);
});

it('leaves a hand-written @component collecting in place', function () {
    $tree = renderEdgeTree(<<<'BLADE'
        <native:column>
            @component('components.box.last')
                <native:text>Body</native:text>
            @endcomponent
        </native:column>
        BLADE);

    // Not a component tag, so nothing is captured: the old order stands.
    expect(slotShape($tree))->toBe(['column' => ['text:Body', 'text:Header']]);
});

// ── What can go inside a slot ────────────────────────

it('collects includes, loops, text and buttons written inside a slot', function () {
    $tree = renderEdgeTree(<<<'BLADE'
        <native:column>
            <x-box.in-row>
                @include('marker-partial')
                @foreach (['x', 'y'] as $item)
                    <native:text>Item {{ $item }}</native:text>
                @endforeach
                <native:button @tap="go">Go</native:button>
            </x-box.in-row>
        </native:column>
        BLADE);

    expect(slotShape($tree))->toBe(['column' => [['row' => [
        'text:Partial content',
        'text:Item x',
        'text:Item y',
        'button:Go',
    ]]]]);
});

it('mounts native child components written inside a slot', function () {
    ComponentRegistry::components([
        'user-card-child' => UserCardChild::class,
        'badge-child' => BadgeChild::class,
    ]);

    $screen = Native::test(SlotCallbacksScreen::class)
        ->assertSee('Card: slotted L0 C0');

    // The child's handlers still dispatch to the child, not the screen.
    $screen->tap('bump-slotted')->assertSee('Card: slotted L0 C1');
});

it('rejects a slot echoed between a native child component\'s tags', function () {
    ComponentRegistry::components(['user-card-child' => UserCardChild::class]);
    NativeElementCollector::setOwner(new SlotCallbacksScreen);

    // What a compiled view does for a component whose view is
    // `<native:user-card-child>{{ $slot }}</native:user-card-child>`.
    ob_start();

    try {
        NativeElementCollector::beginComponent();
        NativeElementCollector::leaf('spacer', []);
        $marker = NativeElementCollector::endSlot();

        NativeElementCollector::open('user_card_child', ['name' => 'x']);
        echo $marker;

        expect(fn () => NativeElementCollector::close())
            ->toThrow(ComponentSlotNotSupportedException::class, 'do not support slot content');
    } finally {
        ob_end_clean();
    }
});

// ── Callbacks ────────────────────────────────────────

it('dispatches taps from the slot and from the component view to the screen', function () {
    ComponentRegistry::components([
        'user-card-child' => UserCardChild::class,
        'badge-child' => BadgeChild::class,
    ]);

    Native::test(SlotCallbacksScreen::class)
        ->tap('inner')
        ->assertSet('innerTaps', 1)
        ->tap('wrapper')
        ->assertSet('wrapperTaps', 1);
});

// ── A Blade component as the screen layout ───────────

it('builds the layout tree in document order', function (string $layout, string $prefix) {
    $tree = renderEdgeTree(<<<BLADE
        <x-{$layout} title="Chat">
            <x-slot:actions>
                <{$prefix}top-bar-action id="info" icon="info" label="Info" @tap="showInfo" />
            </x-slot:actions>

            <{$prefix}column class="flex-1">
                <{$prefix}text>Chat detail</{$prefix}text>
            </{$prefix}column>
        </x-{$layout}>
        BLADE);

    expect(slotShape($tree))->toBe(['column' => [
        ['top_bar:Chat' => ['top_bar_action:Info']],
        ['column' => ['text:Chat detail']],
        ['bottom_nav' => ['bottom_nav_item:Chats', 'bottom_nav_item:Friends']],
    ]]);
})->with([
    'native: tags' => ['layout', 'native:'],
    'short tags' => ['short-layout', ''],
]);

it('hoists the bars of a Blade component layout into the screen chrome', function () {
    $screen = Native::test(LayoutSlotScreen::class)
        ->assertNavTitle('Chat')
        ->assertElement('top_bar_action', function (array $n) {
            return ($n['props']['id'] ?? null) === 'info' && isset($n['on_press']);
        })
        ->assertHasTabBar()
        ->assertHasTab('Chats')
        ->assertHasTab('Friends')
        // Both bars were hoisted, so neither is also drawn in the content.
        ->assertMissingElement('top_bar')
        ->assertMissingElement('bottom_nav')
        ->assertSee('Chat detail');

    $screen->tap('Info')->assertSet('infoShown', 1);
});

// ── Robustness ───────────────────────────────────────

it('recovers after a slot throws part way through', function () {
    try {
        renderEdgeTree(<<<'BLADE'
            <native:column>
                <x-box.in-row>
                    <x-slot:actions><native:text>Action</native:text></x-slot:actions>
                    <native:text>Body</native:text>
                    @php throw new RuntimeException('boom'); @endphp
                </x-box.in-row>
            </native:column>
            BLADE);
    } catch (Throwable $e) {
        expect($e->getMessage())->toContain('boom');
    }

    $tree = renderEdgeTree('<native:column><x-box.in-row><native:text>Fine</native:text></x-box.in-row></native:column>');

    expect(slotShape($tree))->toBe(['column' => [['row' => ['text:Fine']]]]);
});

it('restores slot captures around a detached capture that throws', function () {
    // An open component, as a compiled view would leave it mid-slot.
    ob_start();
    NativeElementCollector::beginComponent();
    $before = slotState();

    try {
        NativeElementCollector::capture(function () {
            ob_start();
            NativeElementCollector::beginComponent();
            ob_end_clean();

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        //
    }

    expect(slotState())->toBe($before);
    expect(NativeElementCollector::endSlot())->toBe('');

    ob_end_clean();
});

it('does nothing in streaming mode', function () {
    NativeElementCollector::setStreaming(true);

    ob_start();
    NativeElementCollector::beginComponent();
    NativeElementCollector::beginSlot();

    expect(NativeElementCollector::endSlot())->toBe('');

    NativeElementCollector::endComponent();
    NativeElementCollector::flushSlots();
    ob_end_clean();

    expect(slotState())->toBe([[], [], [], []]);
});

// ── Web views ────────────────────────────────────────

it('compiles and renders a web view with components exactly as plain Blade does', function () {
    NativeTagPrecompiler::setActive(false);

    $source = <<<'BLADE'
        <main>
            <x-web.card title="Hello">
                <x-slot:footer><button>OK</button></x-slot:footer>
                <text>Body</text>
                @include('web-partial')
            </x-web.card>
        </main>
        BLADE;

    $plain = new BladeCompiler(new Filesystem, sys_get_temp_dir());

    expect(Blade::compileString($source))->toBe($plain->compileString($source));
    expect(Blade::compileString($source))->not->toContain('NativeElementCollector');

    expect(Blade::render($source))->toBe(<<<'HTML'
        <main>
            <div class="card"><h2>Hello</h2><text>Body</text>
                <p>Partial</p><footer><button>OK</button></footer></div>
        </main>
        HTML);
});
