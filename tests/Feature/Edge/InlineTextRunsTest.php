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
