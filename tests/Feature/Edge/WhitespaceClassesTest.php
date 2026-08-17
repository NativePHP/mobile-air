<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeTagPrecompiler;

/**
 * The whitespace-* class family governs how text whitespace is resolved
 * before the text prop is serialized, identically for slot-sourced and
 * attribute-sourced text. Without a class the per-source defaults hold:
 * slot text gets the historical trim-and-collapse, attribute text
 * passes through byte-for-byte untouched.
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

/** Render a Blade string through the native pipeline and return the tree array. */
function renderWhitespaceTree(string $blade, array $data = []): array
{
    $viewPath = __DIR__.'/views/whitespace-classes.blade.php';
    file_put_contents($viewPath, $blade);

    NativeElementCollector::reset();
    view('whitespace-classes', $data)->render();

    return NativeElementCollector::collect()->toArray(new CallbackRegistry);
}

// ── Slot-sourced text ────────────────────────────────

it('collapses slot text fully under whitespace-normal', function () {
    $tree = renderWhitespaceTree(
        '<native:column><native:text class="whitespace-normal">{{ $msg }}</native:text></native:column>',
        ['msg' => "  Line one \n   Line two  "]
    );

    expect($tree['children'][0]['props']['text'])->toBe('Line one Line two');
});

it('preserves newlines in slot text under whitespace-pre-line', function () {
    $tree = renderWhitespaceTree(
        '<native:column><native:text class="whitespace-pre-line">{{ $msg }}</native:text></native:column>',
        ['msg' => "  Line one   \n   Line  two  "]
    );

    // Newlines survive, horizontal runs collapse, edges trim.
    expect($tree['children'][0]['props']['text'])->toBe("Line one\nLine two");
});

it('keeps slot text verbatim under whitespace-pre', function () {
    $msg = "Line one\n    Line two\n";

    $tree = renderWhitespaceTree(
        '<native:column><native:text class="whitespace-pre">{{ $msg }}</native:text></native:column>',
        ['msg' => $msg]
    );

    expect($tree['children'][0]['props']['text'])->toBe($msg);
});

it('keeps the exact trim-and-collapse default for slot text without a class', function () {
    $tree = renderWhitespaceTree(
        '<native:column><native:text>{{ $msg }}</native:text></native:column>',
        ['msg' => "  Hello \n  World  "]
    );

    expect($tree['children'][0]['props']['text'])->toBe('Hello World');
});

// ── Attribute-sourced text ───────────────────────────

it('collapses attribute text under whitespace-normal on a self-closing tag', function () {
    $tree = renderWhitespaceTree(
        '<native:column><native:text class="whitespace-normal" :text="$msg" /></native:column>',
        ['msg' => "  Line one \n   Line two  "]
    );

    expect($tree['children'][0]['props']['text'])->toBe('Line one Line two');
});

it('collapses attribute text under whitespace-normal on a paired tag', function () {
    $tree = renderWhitespaceTree(
        '<native:column><native:text class="whitespace-normal" :text="$msg"></native:text></native:column>',
        ['msg' => "  Line one \n   Line two  "]
    );

    expect($tree['children'][0]['props']['text'])->toBe('Line one Line two');
});

it('preserves newlines in attribute text under whitespace-pre-line', function () {
    $tree = renderWhitespaceTree(
        '<native:column><native:text class="whitespace-pre-line" :text="$msg" /></native:column>',
        ['msg' => "  Line one   \n   Line  two  "]
    );

    expect($tree['children'][0]['props']['text'])->toBe("Line one\nLine two");
});

it('keeps attribute text verbatim under whitespace-pre', function () {
    $msg = "  Line one \n   Line two  ";

    $tree = renderWhitespaceTree(
        '<native:column><native:text class="whitespace-pre" :text="$msg" /></native:column>',
        ['msg' => $msg]
    );

    expect($tree['children'][0]['props']['text'])->toBe($msg);
});

it('keeps attribute text byte-for-byte untouched without a class', function () {
    $msg = "  Line one \n   Line two  ";

    $selfClosing = renderWhitespaceTree(
        '<native:column><native:text :text="$msg" /></native:column>',
        ['msg' => $msg]
    );
    $paired = renderWhitespaceTree(
        '<native:column><native:text :text="$msg"></native:text></native:column>',
        ['msg' => $msg]
    );

    expect($selfClosing['children'][0]['props']['text'])->toBe($msg);
    expect($paired['children'][0]['props']['text'])->toBe($msg);
});

// ── Hand-wrapped template literals ───────────────────

it('unwraps indented multi-line template prose under whitespace-pre-line', function () {
    $tree = renderWhitespaceTree(<<<'BLADE'
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
    $tree = renderWhitespaceTree(<<<'BLADE'
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

it('keeps default run boundaries and spacing unchanged without a class', function () {
    $tree = renderWhitespaceTree(<<<'BLADE'
<native:column>
    <native:text>
        <native:text>A </native:text>
        <native:text class="font-mono">B</native:text>
        <native:text> C</native:text>
    </native:text>
</native:column>
BLADE);

    $text = collect($tree['children'])->firstWhere('type', 'text');
    expect($text['children'])->toHaveCount(3);
    expect($text['children'][0]['props']['text'])->toBe('A ');
    expect($text['children'][1]['props']['text'])->toBe('B');
    expect($text['children'][2]['props']['text'])->toBe(' C');
});

// ── <x-native-text> component parity ─────────────────

it('resolves x-native-text slot text identically to plain text', function () {
    $component = renderWhitespaceTree(<<<'BLADE'
<native:column><x-native-text class="whitespace-pre-line">
    Terms apply
    to everyone.
</x-native-text></native:column>
BLADE);
    $plain = renderWhitespaceTree(<<<'BLADE'
<native:column><native:text class="whitespace-pre-line">
    Terms apply
    to everyone.
</native:text></native:column>
BLADE);

    expect($component['children'][0]['props']['text'])->toBe("Terms apply\nto everyone.");
    expect($component['children'][0]['props']['text'])->toBe($plain['children'][0]['props']['text']);
});

it('applies whitespace-normal to x-native-text attribute text', function () {
    $tree = renderWhitespaceTree(
        '<native:column><x-native-text class="whitespace-normal" :text="$msg" /></native:column>',
        ['msg' => "  Line one \n   Line two  "]
    );

    expect($tree['children'][0]['props']['text'])->toBe('Line one Line two');
});
