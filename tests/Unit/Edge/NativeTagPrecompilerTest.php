<?php

use Native\Mobile\Edge\Components\EdgeComponent;
use Native\Mobile\Edge\Components\Navigation\TopBar;
use Native\Mobile\Edge\Edge;
use Native\Mobile\Edge\NativeTagPrecompiler;
use Native\Mobile\Http\Middleware\RenderEdgeComponents;

beforeEach(function () {
    $this->precompiler = new NativeTagPrecompiler;
    // The precompiler only transforms while a native view is being compiled;
    // enable it for these unit tests, which exercise that transformation.
    NativeTagPrecompiler::setActive(true);
});

afterEach(function () {
    NativeTagPrecompiler::setActive(false);
});

it('is a no-op unless native compilation is active', function () {
    NativeTagPrecompiler::setActive(false);

    $input = '<button :class="{ \'text-blue-500\': expanded }">Toggle</button>';

    expect(($this->precompiler)($input))->toBe($input);
});

$collector = '\\Native\\Mobile\\Edge\\NativeElementCollector';

// Every native compile is prefixed with the compiled-view marker, so the
// render path can detect (and recompile) views cached by a web compile.
$marker = '<?php '.NativeTagPrecompiler::COMPILED_MARKER.' ?>';

it('compiles self-closing leaf elements', function () use ($collector, $marker) {
    $result = ($this->precompiler)('<native:spacer class="h-4" />');

    expect($result)->toBe($marker."<?php {$collector}::leaf('spacer', ['class' => 'h-4']); ?>");
});

it('compiles container open and close tags', function () use ($collector, $marker) {
    $result = ($this->precompiler)('<native:column fill class="p-4">content</native:column>');

    expect($result)->toBe(
        $marker
        ."<?php {$collector}::open('column', ['fill' => true, 'class' => 'p-4']); ?>"
        .'content'
        ."<?php {$collector}::close(); ?>"
    );
});

it('compiles text elements to the inline-run capture calls', function () {
    // <text> routes through textOpen/textClose so nested <text> can emit as
    // ordered inline runs (button still uses the flat slot-capture path below).
    $result = ($this->precompiler)('<native:text class="text-lg">Hello World</native:text>');

    expect($result)->toContain('::textOpen(');
    expect($result)->toContain('::textClose()');
});

it('compiles button elements with label slot capture', function () {
    $result = ($this->precompiler)('<native:button _press="doIt">Click me</native:button>');

    expect($result)->toContain('ob_start();');
    expect($result)->toContain("'_press' => 'doIt'");
    expect($result)->toContain("::leaf('button',");
    expect($result)->toContain("'label'");
});

it('compiles self-closing webview to a leaf', function () {
    $result = ($this->precompiler)('<native:webview src="https://example.com" @navigated="urlChanged" />');

    expect($result)->toContain("::leaf('webview',");
    expect($result)->toContain("'src' => 'https://example.com'");
    expect($result)->toContain("'_navigated' => 'urlChanged'");
    expect($result)->not->toContain('@navigated');
});

it('captures webview slot markup verbatim into the html prop', function () {
    $result = ($this->precompiler)('<native:webview><h1>Hi</h1><p>Hello <em>world</em></p></native:webview>');

    expect($result)->toContain('ob_start();');
    expect($result)->toContain("::leaf('webview',");
    expect($result)->toContain("\$__nativeSlotAttrs['html']");
    // The slot is an HTML document, not display text — it must never go
    // through the strip_tags/entity-decode path text elements use.
    expect($result)->not->toContain('strip_tags');
});

it('lets an explicit :html attribute win over webview slot content', function () {
    $result = ($this->precompiler)('<native:webview :html="$doc"><h1>fallback</h1></native:webview>');

    expect($result)->toContain("'html' => (\$doc)");
    expect($result)->toContain("!isset(\$__nativeSlotAttrs['html'])");
});

it('rewrites @tap to _press', function () {
    $result = ($this->precompiler)('<native:button label="+" @tap="increment" />');

    expect($result)->toContain("'_press' => 'increment'");
    expect($result)->not->toContain('@tap');
});

it('rewrites @longPress to _longPress', function () {
    $result = ($this->precompiler)('<native:column @longPress="handleLong">x</native:column>');

    expect($result)->toContain("'_longPress' => 'handleLong'");
    expect($result)->not->toContain('@longPress');
});

it('rewrites @doubleTap to _doubleTap', function () {
    $result = ($this->precompiler)('<native:column @doubleTap="handleDouble">x</native:column>');

    expect($result)->toContain("'_doubleTap' => 'handleDouble'");
    expect($result)->not->toContain('@doubleTap');
});

it('rewrites @tapDown and @tapUp to underscored versions', function () {
    $result = ($this->precompiler)('<native:pressable @tapDown="startLeft" @tapUp="stopLeft">x</native:pressable>');

    expect($result)->toContain("'_pressDown' => 'startLeft'");
    expect($result)->toContain("'_pressUp' => 'stopLeft'");
    expect($result)->not->toContain('@tapDown');
    expect($result)->not->toContain('@tapUp');
});

it('rewrites @selectionChange to _selectionChange', function () {
    $result = ($this->precompiler)('<native:column @selectionChange="caretMoved">x</native:column>');

    expect($result)->toContain("'_selectionChange' => 'caretMoved'");
    expect($result)->not->toContain('@selectionChange');
});

it('rewrites @selectionChange and @change independently on the same tag', function () {
    // `change` is a suffix of `selectionChange` — the @-anchored alternation
    // must never rewrite one into the other.
    $result = ($this->precompiler)('<native:column @change="updateDraft" @selectionChange="caretMoved">x</native:column>');

    expect($result)->toContain("'_change' => 'updateDraft'");
    expect($result)->toContain("'_selectionChange' => 'caretMoved'");
    expect($result)->not->toContain('@change');
    expect($result)->not->toContain('@selectionChange');
});

it('keeps @tap and @tapDown distinct on the same tag', function () {
    // `pressDown` precedes `press` in the alternation — a plain @tap must
    // still rewrite to _press, never swallow the Down/Up suffix.
    $result = ($this->precompiler)('<native:pressable @tap="fire" @tapDown="charge">x</native:pressable>');

    expect($result)->toContain("'_press' => 'fire'");
    expect($result)->toContain("'_pressDown' => 'charge'");
});

it('rewrites the @press alias to _press', function () {
    $result = ($this->precompiler)('<native:button label="+" @press="increment" />');

    expect($result)->toContain("'_press' => 'increment'");
    expect($result)->not->toContain('@press');
});

it('rewrites the rest of the press-family aliases onto the same wire attrs', function () {
    $result = ($this->precompiler)(
        '<native:pressable @longTap="hold" @pressDown="charge" @pressUp="release">x</native:pressable>'
    );

    expect($result)->toContain("'_longPress' => 'hold'");
    expect($result)->toContain("'_pressDown' => 'charge'");
    expect($result)->toContain("'_pressUp' => 'release'");
});

it('leaves @doubleTap alone when aliasing @tap', function () {
    // The alias alternation is anchored at `@`, so `tap` can never match the
    // tail of `doubleTap`.
    $result = ($this->precompiler)('<native:column @doubleTap="two" @tap="one">x</native:column>');

    expect($result)->toContain("'_doubleTap' => 'two'");
    expect($result)->toContain("'_press' => 'one'");
});

it('accepts @tap and @tap side by side on the same screen', function () {
    $result = ($this->precompiler)(
        '<native:column><native:button label="a" @tap="old" /><native:button label="b" @tap="new" /></native:column>'
    );

    expect($result)->toContain("'_press' => 'old'");
    expect($result)->toContain("'_press' => 'new'");
});

it('rewrites @change and @submit', function () {
    $result = ($this->precompiler)('<native:text-input @change="onTextChange" @submit="onTextSubmit" />');

    expect($result)->toContain("'_change' => 'onTextChange'");
    expect($result)->toContain("'_submit' => 'onTextSubmit'");
});

it('handles hyphenated component names like scroll-view', function () {
    $result = ($this->precompiler)('<native:scroll-view fillWidth>content</native:scroll-view>');

    expect($result)->toContain("::open('scroll_view', ['fillWidth' => true])");
    expect($result)->toContain('::close()');
});

it('handles text-input hyphenated name', function () {
    $result = ($this->precompiler)('<native:text-input placeholder="Search..." />');

    expect($result)->toContain("::leaf('text_input', ['placeholder' => 'Search...'])");
});

it('compiles dotted native model paths through the bound component', function () {
    $result = ($this->precompiler)('<native:text-input native:model="profile.name" />');

    expect($result)
        ->toContain("'value' => (data_get(\$this, 'profile.name'))")
        ->toContain("'_change' => '__syncProperty(\\'profile.name\\')'")
        ->toContain("'sync-mode' => 'live'");
});

it('preserves Blade directives like @foreach', function () {
    $input = '@foreach($items as $item) <native:text>{{ $item }}</native:text> @endforeach';
    $result = ($this->precompiler)($input);

    expect($result)->toContain('@foreach');
    expect($result)->toContain('@endforeach');
});

it('handles dynamic attributes with colon prefix', function () {
    $result = ($this->precompiler)('<native:text :fontSize="$size" :color="$theme->color" />');

    expect($result)->toContain("'fontSize' => (\$size)");
    expect($result)->toContain("'color' => (\$theme->color)");
});

it('handles multiple native tags in one template', function () {
    $input = '<native:column fill><native:text :fontSize="20">Hi</native:text><native:button label="OK" @tap="ok" /></native:column>';
    $result = ($this->precompiler)($input);

    expect($result)->toContain("::open('column', ['fill' => true])");
    expect($result)->toContain("::leaf('button',");
    expect($result)->toContain("'label' => 'OK'");
    expect($result)->toContain("'_press' => 'ok'");
    expect($result)->toContain('::close()');
});

it('handles self-closing tags without attributes', function () use ($collector, $marker) {
    $result = ($this->precompiler)('<native:divider />');

    expect($result)->toBe($marker."<?php {$collector}::leaf('divider', []); ?>");
});

it('handles boolean attributes', function () {
    $result = ($this->precompiler)('<native:column fill center safeArea />');

    expect($result)->toContain("'fill' => true");
    expect($result)->toContain("'center' => true");
    expect($result)->toContain("'safeArea' => true");
});

it('does not rewrite @dismiss as event callback when not on attribute', function () {
    $result = ($this->precompiler)('<native:bottom-sheet @dismiss="onClose">x</native:bottom-sheet>');

    expect($result)->toContain("'_dismiss' => 'onClose'");
});

it('interpolates Blade {{ }} syntax in static attribute values', function () {
    $result = ($this->precompiler)('<native:text text="{{ $category }}" />');

    expect($result)->toContain("'text' => (\$category)");
    expect($result)->not->toContain('{{');
});

it('interpolates mixed text and Blade {{ }} in attribute values', function () {
    $result = ($this->precompiler)('<native:text text="Price: {{ $price }}/night" />');

    expect($result)->toContain("'Price: ' . (\$price) . '/night'");
});

it('interpolates {!! !!} unescaped echo in attribute values', function () {
    $result = ($this->precompiler)('<native:text text="{!! $raw !!}" />');

    expect($result)->toContain("'text' => (\$raw)");
});

it('interpolates array access inside {{ }} in attribute values', function () {
    $result = ($this->precompiler)('<native:image :src="$listing[\'imageUrl\']" />');

    expect($result)->toContain("'src' => (\$listing['imageUrl'])");
});

// ── Chrome tags compile through the collector (Gen-B Edge bridge is gone) ──

it('compiles chrome container tags into collector open/close calls', function () use ($collector) {
    foreach (['top-bar', 'bottom-nav', 'side-nav', 'side-nav-group'] as $tag) {
        $type = str_replace('-', '_', $tag);
        $result = ($this->precompiler)("<native:{$tag} title=\"X\">body</native:{$tag}>");

        expect($result)->toContain("{$collector}::open('{$type}',")
            ->toContain("{$collector}::close();")
            ->not->toContain('Edge::');
    }
});

it('compiles chrome leaf tags into collector leaf calls', function () use ($collector) {
    foreach (['top-bar-action', 'bottom-nav-item', 'side-nav-item', 'side-nav-header'] as $tag) {
        $type = str_replace('-', '_', $tag);
        $result = ($this->precompiler)("<native:{$tag} id=\"x\" />");

        expect($result)->toContain("{$collector}::leaf('{$type}',")
            ->not->toContain('Edge::');
    }
});

it('compiles a self-closing fab into a collector leaf', function () use ($collector) {
    $result = ($this->precompiler)('<native:fab icon="add" @tap="create" />');

    expect($result)->toContain("{$collector}::leaf('fab',")
        ->toContain("'_press' => 'create'");
});

it('preserves the boolean custom attribute on chrome tags', function () {
    $result = ($this->precompiler)('<native:top-bar custom title="Drawn">x</native:top-bar>');

    expect($result)->toContain("'custom' => true");
});

it('has fully removed the Gen-B Edge bridge classes', function () {
    expect(class_exists(Edge::class))->toBeFalse()
        ->and(class_exists(EdgeComponent::class))->toBeFalse()
        ->and(class_exists(TopBar::class))->toBeFalse()
        ->and(class_exists(RenderEdgeComponents::class))->toBeFalse();
});
