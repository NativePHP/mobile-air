<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeElementCollector;

/**
 * Collector machinery tests — element types used here are the ones core
 * registers itself (column, row, text, image, spacer, divider, ...).
 * Behavior of plugin-owned element types (button, toggle, checkbox, …)
 * is tested in the nativephp/native-ui repo, next to the elements.
 */
beforeEach(function () {
    NativeElementCollector::reset();
});

afterEach(function () {
    NativeElementCollector::reset();
});

it('builds a single leaf element', function () {
    NativeElementCollector::leaf('spacer', []);

    $element = NativeElementCollector::collect();
    $tree = $element->toArray(new CallbackRegistry);

    // Every node carries an id and content hash (subtree-memo wire format).
    expect($tree['type'])->toBe('spacer');
    expect($tree['id'])->toBeInt();
    expect($tree)->toHaveKey('_hash');
    expect($tree)->not->toHaveKey('children');
});

it('builds a single container with no children', function () {
    NativeElementCollector::open('column', []);
    NativeElementCollector::close();

    $element = NativeElementCollector::collect();
    $tree = $element->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('column');
    expect($tree['id'])->toBeInt();
    expect($tree)->not->toHaveKey('children');
});

it('builds a container with leaf children', function () {
    NativeElementCollector::open('column', ['fill' => true]);
    NativeElementCollector::leaf('text', ['text' => 'Hello', 'fontSize' => 20]);
    NativeElementCollector::leaf('spacer', []);
    NativeElementCollector::leaf('divider', []);
    NativeElementCollector::close();

    $element = NativeElementCollector::collect();
    $tree = $element->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('column');
    expect($tree['layout'])->toBe(['width' => 'fill', 'height' => 'fill']);
    expect($tree['children'])->toHaveCount(3);
    expect($tree['children'][0]['type'])->toBe('text');
    expect($tree['children'][0]['props']['text'])->toBe('Hello');
    expect($tree['children'][0]['props']['font_size'])->toBe(20.0);
    expect($tree['children'][1]['type'])->toBe('spacer');
    expect($tree['children'][2]['type'])->toBe('divider');
});

it('builds nested containers with node-level callbacks', function () {
    NativeElementCollector::open('column', ['fill' => true, 'center' => true]);
    NativeElementCollector::open('row', ['gap' => '16']);
    NativeElementCollector::leaf('text', ['text' => 'A', '_press' => 'handleA']);
    NativeElementCollector::leaf('text', ['text' => 'B', '_press' => 'handleB']);
    NativeElementCollector::close(); // close row
    NativeElementCollector::close(); // close column

    $registry = new CallbackRegistry;
    $element = NativeElementCollector::collect();
    $tree = $element->toArray($registry);

    expect($tree['type'])->toBe('column');
    expect($tree['layout']['align_items'])->toBe(1);
    expect($tree['layout']['justify_content'])->toBe(1);
    expect($tree['children'])->toHaveCount(1);

    $row = $tree['children'][0];
    expect($row['type'])->toBe('row');
    expect($row['layout']['gap'])->toBe(16.0);
    expect($row['children'])->toHaveCount(2);

    expect($row['children'][0]['props']['text'])->toBe('A');
    expect($row['children'][0]['on_press'])->toBeInt();
    expect($row['children'][1]['props']['text'])->toBe('B');
    expect($row['children'][1]['on_press'])->toBeInt();

    // Callbacks should resolve
    expect($registry->resolve($row['children'][0]['on_press']))->toBe(['method' => 'handleA', 'args' => []]);
    expect($registry->resolve($row['children'][1]['on_press']))->toBe(['method' => 'handleB', 'args' => []]);
});

it('applies layout attributes correctly', function () {
    NativeElementCollector::leaf('column', [
        'fillWidth' => true,
        'padding' => '16',
        'margin' => '8',
        'gap' => '12',
        'safeArea' => true,
        'alignItems' => '1',
        'justifyContent' => '2',
    ]);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['layout'])->toBe([
        'width' => 'fill',
        'padding' => 16.0,
        'margin' => 8.0,
        'gap' => 12.0,
        'safe_area' => 1,
        'align_items' => 1,
        'justify_content' => 2,
    ]);
});

it('applies style attributes correctly', function () {
    NativeElementCollector::leaf('column', [
        'bg' => '#FF0000',
        'borderRadius' => '12',
        'borderWidth' => '2',
        'borderColor' => '#000000',
        'opacity' => '0.8',
        'elevation' => '4',
    ]);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['style'])->toBe([
        'bg_color' => '#FF0000',
        'border_radius' => 12.0,
        'border_width' => 2.0,
        'border_color' => '#000000',
        'opacity' => 0.8,
        'elevation' => 4.0,
    ]);
});

it('applies text-specific props', function () {
    NativeElementCollector::leaf('text', [
        'text' => 'Hello World',
        'fontSize' => '24',
        'fontWeight' => '7',
        'color' => '#1a1a2e',
        'textAlign' => '1',
        'maxLines' => '2',
    ]);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('text');
    expect($tree['props']['text'])->toBe('Hello World');
    expect($tree['props']['font_size'])->toBe(24.0);
    expect($tree['props']['font_weight'])->toBe(7);
    expect($tree['props']['color'])->toBe('#1a1a2e');
    expect($tree['props']['text_align'])->toBe(1);
    expect($tree['props']['max_lines'])->toBe(2);
});

it('applies image props', function () {
    NativeElementCollector::leaf('image', [
        'src' => 'https://example.com/img.png',
        'fit' => '2',
        'tintColor' => '#FF0000',
    ]);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('image');
    expect($tree['props']['src'])->toBe('https://example.com/img.png');
    expect($tree['props']['fit'])->toBe(2);
    expect($tree['props']['tint_color'])->toBe('#FF0000');
});

it('applies scroll view props', function () {
    NativeElementCollector::open('scroll_view', [
        'horizontal' => true,
        'showsIndicators' => false,
    ]);
    NativeElementCollector::leaf('text', ['text' => 'Scrollable']);
    NativeElementCollector::close();

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('scroll_view');
    expect($tree['props']['horizontal'])->toBeTrue();
    expect($tree['props']['shows_indicators'])->toBeFalse();
    expect($tree['children'])->toHaveCount(1);
});

it('applies node-level onPress and onLongPress', function () {
    NativeElementCollector::open('column', [
        '_press' => 'tapColumn',
        '_longPress' => 'longPressColumn',
    ]);
    NativeElementCollector::close();

    $registry = new CallbackRegistry;
    $tree = NativeElementCollector::collect()->toArray($registry);

    expect($tree['on_press'])->toBeInt();
    expect($tree['on_long_press'])->toBeInt();
    expect($registry->resolve($tree['on_press']))->toBe(['method' => 'tapColumn', 'args' => []]);
    expect($registry->resolve($tree['on_long_press']))->toBe(['method' => 'longPressColumn', 'args' => []]);
});

it('throws when collecting without any elements', function () {
    NativeElementCollector::collect();
})->throws(RuntimeException::class, 'No root element was built');

it('throws on unknown element type', function () {
    NativeElementCollector::leaf('unknown_widget', []);
})->throws(RuntimeException::class, 'Unknown native element type: unknown_widget');

it('resets state between collections', function () {
    NativeElementCollector::leaf('spacer', []);
    NativeElementCollector::collect();

    NativeElementCollector::leaf('divider', []);
    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('divider');
});
