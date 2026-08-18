<?php

use Illuminate\Support\Facades\Blade;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeTagPrecompiler;

/**
 * The whitespace-* class family governs how text whitespace is resolved
 * before the text prop is serialized, identically for slot-sourced and
 * attribute-sourced text. Without a class both sources collapse like
 * the browser does at paint time, so slot and attribute text can
 * never disagree; a class opts any element out of that default.
 */
beforeEach(function () {
    NativeElementCollector::reset();
    NativeTagPrecompiler::setActive(true);

    $testViewPath = __DIR__.'/views';
    if (! is_dir($testViewPath)) {
        mkdir($testViewPath, 0755, true);
    }
    app('view')->addLocation($testViewPath);
});

afterEach(function () {
    NativeTagPrecompiler::setActive(false);
    NativeElementCollector::reset();

    $testViewPath = __DIR__.'/views';
    if (is_dir($testViewPath)) {
        foreach (glob($testViewPath.'/*.php') as $file) {
            unlink($file);
        }
    }
});

// ── Slot-sourced text ────────────────────────────────

it('collapses slot text fully under whitespace-normal', function () {
    $tree = renderEdgeTree(
        '<native:column><native:text class="whitespace-normal">{{ $msg }}</native:text></native:column>',
        ['msg' => "  Line one \n   Line two  "]
    );

    expect($tree['children'][0]['props']['text'])->toBe('Line one Line two');
});

it('preserves newlines in slot text under whitespace-pre-line', function () {
    $tree = renderEdgeTree(
        '<native:column><native:text class="whitespace-pre-line">{{ $msg }}</native:text></native:column>',
        ['msg' => "  Line one   \n   Line  two  "]
    );

    // Newlines survive, horizontal runs collapse, edges trim.
    expect($tree['children'][0]['props']['text'])->toBe("Line one\nLine two");
});

it('keeps slot text verbatim under whitespace-pre-wrap', function () {
    $msg = "Line one\n    Line two\n";

    $tree = renderEdgeTree(
        '<native:column><native:text class="whitespace-pre-wrap">{{ $msg }}</native:text></native:column>',
        ['msg' => $msg]
    );

    expect($tree['children'][0]['props']['text'])->toBe($msg);
});

it('keeps the exact trim-and-collapse default for slot text without a class', function () {
    $tree = renderEdgeTree(
        '<native:column><native:text>{{ $msg }}</native:text></native:column>',
        ['msg' => "  Hello \n  World  "]
    );

    expect($tree['children'][0]['props']['text'])->toBe('Hello World');
});

// ── Attribute-sourced text ───────────────────────────

it('collapses attribute text under whitespace-normal on a self-closing tag', function () {
    $tree = renderEdgeTree(
        '<native:column><native:text class="whitespace-normal" :text="$msg" /></native:column>',
        ['msg' => "  Line one \n   Line two  "]
    );

    expect($tree['children'][0]['props']['text'])->toBe('Line one Line two');
});

it('collapses attribute text under whitespace-normal on a paired tag', function () {
    $tree = renderEdgeTree(
        '<native:column><native:text class="whitespace-normal" :text="$msg"></native:text></native:column>',
        ['msg' => "  Line one \n   Line two  "]
    );

    expect($tree['children'][0]['props']['text'])->toBe('Line one Line two');
});

it('preserves newlines in attribute text under whitespace-pre-line', function () {
    $tree = renderEdgeTree(
        '<native:column><native:text class="whitespace-pre-line" :text="$msg" /></native:column>',
        ['msg' => "  Line one   \n   Line  two  "]
    );

    expect($tree['children'][0]['props']['text'])->toBe("Line one\nLine two");
});

it('keeps attribute text verbatim under whitespace-pre-wrap', function () {
    $msg = "  Line one \n   Line two  ";

    $tree = renderEdgeTree(
        '<native:column><native:text class="whitespace-pre-wrap" :text="$msg" /></native:column>',
        ['msg' => $msg]
    );

    expect($tree['children'][0]['props']['text'])->toBe($msg);
});

it('collapses attribute text by default, matching slot text and the browser', function () {
    $msg = "  Line one \n   Line two  ";

    $selfClosing = renderEdgeTree(
        '<native:column><native:text :text="$msg" /></native:column>',
        ['msg' => $msg]
    );
    $paired = renderEdgeTree(
        '<native:column><native:text :text="$msg"></native:text></native:column>',
        ['msg' => $msg]
    );

    expect($selfClosing['children'][0]['props']['text'])->toBe('Line one Line two');
    expect($paired['children'][0]['props']['text'])->toBe('Line one Line two');
});

it('keeps explicit edge spaces on nested separator runs by default', function () {
    // The browser trims at block boundaries only, so an inline separator
    // like `:text="' / '"` must keep its spacing when the default
    // collapse applies. The raw runs around it stay intact too.
    $sep = ' / ';

    $tree = renderEdgeTree(
        '<native:column><native:text>Docs<native:text :text="$sep"></native:text>Guides</native:text></native:column>',
        ['sep' => $sep]
    );

    $runs = $tree['children'][0]['children'];
    expect($runs[0]['props']['text'])->toBe('Docs');
    expect($runs[1]['props']['text'])->toBe(' / ');
    expect($runs[2]['props']['text'])->toBe('Guides');
});

// ── Hand-wrapped template literals ───────────────────

it('unwraps indented multi-line template prose under whitespace-pre-line', function () {
    $tree = renderEdgeTree(<<<'BLADE'
<native:column>
    <native:text class="whitespace-pre-line">
        Hand wrapped prose line one
        and the second line here.
    </native:text>
</native:column>
BLADE);

    $text = collect($tree['children'])->firstWhere('type', 'text');

    // The literal newlines survive while the indentation collapses away.
    expect($text['props']['text'])->toBe("Hand wrapped prose line one\nand the second line here.");
});

// ── Nested runs under a classed parent ───────────────

it('applies the parent whitespace class to nested runs', function () {
    $tree = renderEdgeTree(<<<'BLADE'
<native:column><native:text class="whitespace-pre-line">First line
<native:text class="font-mono">chip</native:text> second line</native:text></native:column>
BLADE);

    $text = collect($tree['children'])->firstWhere('type', 'text');
    expect($text['children'])->toHaveCount(3);

    // The raw run keeps its newline; the chip inherits the parent mode;
    // the tail run keeps its meaningful leading space (runs never trim).
    expect($text['children'][0]['props']['text'])->toBe("First line\n");
    expect($text['children'][1]['props']['text'])->toBe('chip');
    expect($text['children'][1]['props']['font_family'])->toBe(2);
    expect($text['children'][2]['props']['text'])->toBe(' second line');
});

// ── <x-native-text> component parity ─────────────────

it('resolves x-native-text slot text identically to plain text', function () {
    $component = renderEdgeTree(<<<'BLADE'
<native:column><x-native-text class="whitespace-pre-line">
    Terms apply
    to everyone.
</x-native-text></native:column>
BLADE);
    $plain = renderEdgeTree(<<<'BLADE'
<native:column><native:text class="whitespace-pre-line">
    Terms apply
    to everyone.
</native:text></native:column>
BLADE);

    expect($component['children'][0]['props']['text'])->toBe("Terms apply\nto everyone.");
    expect($component['children'][0]['props']['text'])->toBe($plain['children'][0]['props']['text']);
});

it('applies whitespace-normal to x-native-text attribute text', function () {
    $tree = renderEdgeTree(
        '<native:column><x-native-text class="whitespace-normal" :text="$msg" /></native:column>',
        ['msg' => "  Line one \n   Line two  "]
    );

    expect($tree['children'][0]['props']['text'])->toBe('Line one Line two');
});

it('treats a nested self-closing text as a run in document order', function () {
    // Used to escape the frame system and emit as a sibling BEFORE its
    // parent with leaf-trimmed text. It is a run like the paired form
    // now, so the separator keeps its explicit edge spaces too.
    $sep = ' / ';

    $tree = renderEdgeTree(
        '<native:column><native:text>Docs<native:text :text="$sep" />Guides</native:text></native:column>',
        ['sep' => $sep]
    );

    expect($tree['children'])->toHaveCount(1);

    $runs = $tree['children'][0]['children'];
    expect($runs[0]['props']['text'])->toBe('Docs');
    expect($runs[1]['props']['text'])->toBe(' / ');
    expect($runs[2]['props']['text'])->toBe('Guides');
});

it('lets a run class override the parent whitespace class', function () {
    $msg = "keep\nme";

    $tree = renderEdgeTree(
        '<native:column><native:text class="whitespace-pre-line">head<native:text class="whitespace-normal" :text="$msg"></native:text></native:text></native:column>',
        ['msg' => $msg]
    );

    $runs = $tree['children'][0]['children'];
    expect($runs[1]['props']['text'])->toBe('keep me');
});

it('never collapses non-breaking spaces, matching the browser', function () {
    $msg = "a\u{00A0}\u{00A0}b   c";

    $tree = renderEdgeTree(
        '<native:column><native:text :text="$msg" /></native:column>',
        ['msg' => $msg]
    );

    expect($tree['children'][0]['props']['text'])->toBe("a\u{00A0}\u{00A0}b c");
});

// Blade captures slot content before the component template renders, so
// EDGE elements inside a component slot emit ahead of the component's
// own wrapper (a pre-existing placement quirk, identical on main).
// The whitespace policy is what these pin: it resolves the same
// through a slot as anywhere else, class and default alike.
it('applies whitespace classes to text rendered through a blade component slot', function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    $msg = "Line one\n   Line two";

    $tree = renderEdgeTree(
        '<native:column><x-ws-panel><native:text class="whitespace-pre-line">{{ $msg }}</native:text></x-ws-panel></native:column>',
        ['msg' => $msg]
    );

    // The slot text emits as a sibling BEFORE the panel today; asserting
    // that placement makes a future capture-order fix flip this test
    // consciously instead of dying on a null lookup.
    expect($tree['children'][0]['type'])->toBe('text');
    expect($tree['children'][0]['props']['text'])->toBe("Line one\nLine two");
});

it('collapses slot-delivered text by default inside a blade component slot', function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    $msg = "Line one\n   Line two";

    $tree = renderEdgeTree(
        '<native:column><x-ws-panel><native:text>{{ $msg }}</native:text></x-ws-panel></native:column>',
        ['msg' => $msg]
    );

    expect($tree['children'][0]['type'])->toBe('text');
    expect($tree['children'][0]['props']['text'])->toBe('Line one Line two');
});

// ── Authoring-form parity ────────────────────────────

it('resolves text identically across every form, source and class at top level', function () {
    $msg = "Line one\n   Line two";
    $forms = [
        '<text%s>{{ $msg }}</text>',
        '<text%s :text="$msg" />',
        '<native:text%s>{{ $msg }}</native:text>',
        '<native:text%s :text="$msg" />',
        '<x-native-text%s>{{ $msg }}</x-native-text>',
        '<x-native-text%s :text="$msg" />',
    ];

    foreach (['', ' class="whitespace-pre-line"', ' class="whitespace-pre-wrap"'] as $cls) {
        $baseline = null;
        foreach ($forms as $tpl) {
            $tree = renderEdgeTree('<native:column>'.sprintf($tpl, $cls).'</native:column>', ['msg' => $msg]);
            $text = collect($tree['children'])->firstWhere('type', 'text')['props']['text'];

            $baseline ??= $text;
            expect($text)->toBe($baseline, "{$tpl} diverges under class [{$cls}]");
        }
    }
});

it('resolves nested runs identically across every nested form', function () {
    $msg = "Line one\n   Line two";
    $nested = [
        '<text>{{ $msg }}</text>',
        '<text :text="$msg" />',
        '<text :text="$msg"></text>',
        '<native:text>{{ $msg }}</native:text>',
        '<native:text :text="$msg" />',
        '<x-native-text>{{ $msg }}</x-native-text>',
        '<x-native-text :text="$msg" />',
    ];

    foreach (['', ' class="whitespace-pre-line"', ' class="whitespace-pre-wrap"'] as $cls) {
        $baseline = null;
        foreach ($nested as $inner) {
            $tree = renderEdgeTree(
                '<native:column><text'.$cls.'>head '.$inner.' tail</text></native:column>',
                ['msg' => $msg]
            );
            $text = collect($tree['children'])->firstWhere('type', 'text');
            $shape = json_encode(collect($text['children'])->map(fn ($r) => $r['props']['text'] ?? '')->all());

            $baseline ??= $shape;
            expect($shape)->toBe($baseline, "{$inner} diverges under class [{$cls}]");
        }
    }
});
