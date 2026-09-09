<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeTagPrecompiler;

/**
 * A `<text>` containing child `<text>` emits ordered inline run-nodes (not
 * flattened siblings), preserving inter-run whitespace, so the native renderer
 * composes them into one wrapping attributed string. Leaf `<text>` keeps its
 * flat trimmed-string behavior.
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
function renderInlineTree(string $blade): array
{
    $viewPath = __DIR__.'/views/inline-text.blade.php';
    file_put_contents($viewPath, $blade);

    NativeElementCollector::reset();
    view('inline-text')->render();

    return NativeElementCollector::collect()->toArray(new CallbackRegistry);
}

it('emits three ordered run-nodes with whitespace intact', function () {
    // Single line, no inter-tag whitespace — the canonical verify case.
    $tree = renderInlineTree(
        '<native:column><native:text><native:text>A </native:text><native:text class="font-mono">B</native:text><native:text> C</native:text></native:text></native:column>'
    );

    $text = $tree['children'][0];
    expect($text['type'])->toBe('text');
    expect($text['children'])->toHaveCount(3);
    expect(collect($text['children'])->pluck('type')->all())->toBe(['text', 'text', 'text']);

    // Spaces preserved exactly — no trim, no collapse of meaningful edges.
    expect($text['children'][0]['props']['text'])->toBe('A ');
    expect($text['children'][1]['props']['text'])->toBe('B');
    expect($text['children'][2]['props']['text'])->toBe(' C');

    // Per-run styling flows: the middle run is monospaced.
    expect($text['children'][1]['props']['font_family'])->toBe(2);

    // The parent container run itself carries no own text.
    expect($text['props']['text'] ?? null)->toBeNull();
});

it('captures leading, interspersed, and trailing raw text as ordered runs', function () {
    $tree = renderInlineTree(
        '<native:column><native:text>Use <native:text class="font-mono">code</native:text> here</native:text></native:column>'
    );

    $text = $tree['children'][0];
    expect($text['children'])->toHaveCount(3);
    expect($text['children'][0]['props']['text'])->toBe('Use ');
    expect($text['children'][1]['props']['text'])->toBe('code');
    expect($text['children'][2]['props']['text'])->toBe(' here');
});

it('drops inter-tag indentation whitespace in multiline markup', function () {
    // Newlines + indentation between run tags are formatting, not content.
    $tree = renderInlineTree(<<<'BLADE'
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

it('keeps leaf text on the trimmed-string path (no regression)', function () {
    $tree = renderInlineTree(
        '<native:column><native:text :fontSize="24">  Hello   World  </native:text></native:column>'
    );

    $text = $tree['children'][0];
    expect($text['type'])->toBe('text');
    expect($text['props']['text'])->toBe('Hello World'); // trimmed + collapsed
    expect($text['props']['font_size'])->toBe(24.0);
    expect($text['children'] ?? [])->toBe([]); // leaf, no run children
});

// ── Whitespace policy (`whitespace-*`) ─────────────────

it('collapses newlines in leaf slot text by default', function () {
    $tree = renderInlineTree(<<<'BLADE'
<native:column>
    <native:text>
        First paragraph.

        Second paragraph.
    </native:text>
</native:column>
BLADE);

    expect($tree['children'][0]['props']['text'])->toBe('First paragraph. Second paragraph.');
    expect($tree['children'][0]['props'])->not->toHaveKey('white_space');
});

it('keeps line breaks in slot text under whitespace-pre-line', function () {
    $tree = renderInlineTree(<<<'BLADE'
<native:column>
    <native:text class="whitespace-pre-line">
        First   paragraph.

        Second paragraph.
    </native:text>
</native:column>
BLADE);

    $text = $tree['children'][0];
    // Edges trimmed (template formatting), inner newlines kept, spaces collapsed.
    expect($text['props']['text'])->toBe("First paragraph.\n\nSecond paragraph.");
    expect($text['props']['white_space'])->toBe(3);
});

it('keeps every byte of slot text under whitespace-pre', function () {
    $tree = renderInlineTree(
        "<native:column><native:text class=\"whitespace-pre\">let x = 1;\n    return x;</native:text></native:column>"
    );

    expect($tree['children'][0]['props']['text'])->toBe("let x = 1;\n    return x;");
    expect($tree['children'][0]['props']['white_space'])->toBe(2);
});

it('preserves newlines from an echoed value under whitespace-pre-line', function () {
    // The reported case: user-written paragraphs rendered through the slot.
    $tree = renderInlineTree(<<<'BLADE'
@php($message = "First paragraph.\n\nSecond paragraph.")
<native:column>
    <native:text class="whitespace-pre-line">{{ $message }}</native:text>
    <native:text>{{ $message }}</native:text>
</native:column>
BLADE);

    expect($tree['children'][0]['props']['text'])->toBe("First paragraph.\n\nSecond paragraph.");
    expect($tree['children'][1]['props']['text'])->toBe('First paragraph. Second paragraph.');
});

it('leaves a :text attribute untouched by default and normalizes it under an explicit policy', function () {
    $tree = renderInlineTree(<<<'BLADE'
@php($message = "  First   paragraph.\n\nSecond paragraph.  ")
<native:column>
    <native:text :text="$message" />
    <native:text :text="$message" class="whitespace-pre-line" />
    <native:text :text="$message" class="whitespace-normal" />
</native:column>
BLADE);

    expect($tree['children'][0]['props']['text'])->toBe("  First   paragraph.\n\nSecond paragraph.  ");
    expect($tree['children'][1]['props']['text'])->toBe("First paragraph.\n\nSecond paragraph.");
    expect($tree['children'][2]['props']['text'])->toBe('First paragraph. Second paragraph.');
});

it('applies the parent policy to raw runs and keeps run edge spaces', function () {
    $tree = renderInlineTree(
        "<native:column><native:text class=\"whitespace-pre-line\">Line one\nline two <native:text class=\"font-mono\"> B </native:text>tail</native:text></native:column>"
    );

    $text = $tree['children'][0];
    expect($text['children'])->toHaveCount(3);
    expect($text['children'][0]['props']['text'])->toBe("Line one\nline two ");
    // The nested run has no policy of its own: default collapse, edges kept.
    expect($text['children'][1]['props']['text'])->toBe(' B ');
    expect($text['children'][2]['props']['text'])->toBe('tail');
});

it('keeps edge spaces of a nested run that declares its own policy', function () {
    $tree = renderInlineTree(
        '<native:column><native:text>A<native:text class="whitespace-pre"> B </native:text>C</native:text></native:column>'
    );

    expect($tree['children'][0]['children'][1]['props']['text'])->toBe(' B ');
});
