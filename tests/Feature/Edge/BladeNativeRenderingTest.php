<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeTagPrecompiler;

/**
 * Blade → precompiler → collector integration, on element types core
 * registers itself. Native-tag transformation only happens while the
 * precompiler is ACTIVE (it is registered globally on the Blade compiler
 * but must not rewrite `<button>`/`<text>` in ordinary web views), so
 * these tests activate it the same way NativeComponent's render path
 * does. Plugin-owned tags (button, toggle, text-input, …) are covered
 * in the nativephp/native-ui repo and end-to-end in the kitchen-sink app.
 */
beforeEach(function () {
    NativeElementCollector::reset();
    NativeTagPrecompiler::setActive(true);

    $testViewPath = __DIR__.'/views';
    if (! is_dir($testViewPath)) {
        mkdir($testViewPath, 0755, true);
    }

    $componentsPath = $testViewPath.'/components';
    if (! is_dir($componentsPath)) {
        mkdir($componentsPath, 0755, true);
    }

    app('view')->addLocation($testViewPath);
});

afterEach(function () {
    NativeTagPrecompiler::setActive(false);
    NativeElementCollector::reset();

    // Clean up test views
    $testViewPath = __DIR__.'/views';
    if (is_dir($testViewPath)) {
        $files = glob($testViewPath.'/*.php');
        foreach ($files as $file) {
            unlink($file);
        }

        $componentsPath = $testViewPath.'/components';
        if (is_dir($componentsPath)) {
            $files = glob($componentsPath.'/*.php');
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }
});

it('renders a simple column with text via Blade', function () {
    $viewPath = __DIR__.'/views/test-simple.blade.php';
    file_put_contents($viewPath, '<native:column fill center><native:text :fontSize="24">Hello World</native:text></native:column>');

    NativeElementCollector::reset();
    view('test-simple')->render();
    $element = NativeElementCollector::collect();

    $tree = $element->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('column');
    expect($tree['layout']['width'])->toBe('fill');
    expect($tree['layout']['height'])->toBe('fill');
    expect($tree['layout']['align_items'])->toBe(1);
    expect($tree['layout']['justify_content'])->toBe(1);
    expect($tree['children'])->toHaveCount(1);
    expect($tree['children'][0]['type'])->toBe('text');
    expect($tree['children'][0]['props']['text'])->toBe('Hello World');
    expect($tree['children'][0]['props']['font_size'])->toBe(24.0);
});

it('renders press callbacks from Blade attributes', function () {
    $viewPath = __DIR__.'/views/test-press.blade.php';
    file_put_contents($viewPath, '<native:row gap="16"><native:text @tap="confirm">OK</native:text><native:text @tap="cancel">Cancel</native:text></native:row>');

    NativeElementCollector::reset();
    view('test-press')->render();
    $element = NativeElementCollector::collect();

    $registry = new CallbackRegistry;
    $tree = $element->toArray($registry);

    expect($tree['type'])->toBe('row');
    expect($tree['layout']['gap'])->toBe(16.0);
    expect($tree['children'])->toHaveCount(2);

    expect($tree['children'][0]['props']['text'])->toBe('OK');
    expect($registry->resolve($tree['children'][0]['on_press']))->toBe(['method' => 'confirm', 'args' => []]);

    expect($tree['children'][1]['props']['text'])->toBe('Cancel');
    expect($registry->resolve($tree['children'][1]['on_press']))->toBe(['method' => 'cancel', 'args' => []]);
});

it('renders nested containers', function () {
    $viewPath = __DIR__.'/views/test-nested.blade.php';
    file_put_contents($viewPath, '<native:column fill><native:row fillWidth padding="16"><native:text :fontSize="16">Nested</native:text></native:row><native:spacer /><native:divider /></native:column>');

    NativeElementCollector::reset();
    view('test-nested')->render();
    $element = NativeElementCollector::collect();

    $tree = $element->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('column');
    expect($tree['children'])->toHaveCount(3);
    expect($tree['children'][0]['type'])->toBe('row');
    expect($tree['children'][0]['children'][0]['type'])->toBe('text');
    expect($tree['children'][1]['type'])->toBe('spacer');
    expect($tree['children'][2]['type'])->toBe('divider');
});

it('renders text with dynamic interpolation', function () {
    $viewPath = __DIR__.'/views/test-dynamic.blade.php';
    file_put_contents($viewPath, '<native:column><native:text :fontSize="32">Count: {{ $count }}</native:text></native:column>');

    NativeElementCollector::reset();
    view('test-dynamic', ['count' => 42])->render();
    $element = NativeElementCollector::collect();

    $tree = $element->toArray(new CallbackRegistry);

    expect($tree['children'][0]['props']['text'])->toBe('Count: 42');
});

it('renders foreach loops', function () {
    $viewPath = __DIR__.'/views/test-foreach.blade.php';
    file_put_contents($viewPath, '<native:column fillWidth>@foreach($items as $item)<native:text>{{ $item }}</native:text>@endforeach</native:column>');

    NativeElementCollector::reset();
    view('test-foreach', ['items' => ['Apple', 'Banana', 'Cherry']])->render();
    $element = NativeElementCollector::collect();

    $tree = $element->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('column');
    expect($tree['children'])->toHaveCount(3);
    expect($tree['children'][0]['props']['text'])->toBe('Apple');
    expect($tree['children'][1]['props']['text'])->toBe('Banana');
    expect($tree['children'][2]['props']['text'])->toBe('Cherry');
});

it('renders conditionals', function () {
    $viewPath = __DIR__.'/views/test-conditional.blade.php';
    file_put_contents($viewPath, '<native:column>@if($showText)<native:text>Visible</native:text>@endif<native:text>Always</native:text></native:column>');

    // With condition true
    NativeElementCollector::reset();
    view('test-conditional', ['showText' => true])->render();
    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);
    expect($tree['children'])->toHaveCount(2);

    // With condition false
    NativeElementCollector::reset();
    view('test-conditional', ['showText' => false])->render();
    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);
    expect($tree['children'])->toHaveCount(1);
    expect($tree['children'][0]['props']['text'])->toBe('Always');
});

it('renders all core self-closing element types', function () {
    $viewPath = __DIR__.'/views/test-all-leaf.blade.php';
    file_put_contents($viewPath, <<<'BLADE'
<native:column>
    <native:text>Hello</native:text>
    <native:image src="test.png" />
    <native:icon name="star" />
    <native:spacer />
    <native:divider />
</native:column>
BLADE);

    NativeElementCollector::reset();
    view('test-all-leaf')->render();
    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('column');
    expect($tree['children'])->toHaveCount(5);
    expect($tree['children'][0]['type'])->toBe('text');
    expect($tree['children'][1]['type'])->toBe('image');
    expect($tree['children'][2]['type'])->toBe('icon');
    expect($tree['children'][3]['type'])->toBe('spacer');
    expect($tree['children'][4]['type'])->toBe('divider');
});

it('renders all container element types', function () {
    $viewPath = __DIR__.'/views/test-all-container.blade.php';
    file_put_contents($viewPath, <<<'BLADE'
<native:column>
    <native:row><native:text>In row</native:text></native:row>
    <native:stack><native:text>In stack</native:text></native:stack>
    <native:scroll-view><native:text>In scroll</native:text></native:scroll-view>
</native:column>
BLADE);

    NativeElementCollector::reset();
    view('test-all-container')->render();
    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('column');
    expect($tree['children'])->toHaveCount(3);
    expect($tree['children'][0]['type'])->toBe('row');
    expect($tree['children'][1]['type'])->toBe('stack');
    expect($tree['children'][2]['type'])->toBe('scroll_view');
});

// ── Anonymous Blade component tests ─────────────────────────────────

it('renders native elements from an anonymous Blade component without slots', function () {
    // Anonymous components that render native elements directly (no slot) work fine
    $componentPath = __DIR__.'/views/components/status-badge.blade.php';
    file_put_contents($componentPath, <<<'BLADE'
@props([
    'label' => '',
    'color' => '#4CAF50',
])

<native:row :gap="4" :alignItems="1">
    <native:column :width="8" :height="8" :bg="$color" :borderRadius="4" />
    <native:text :fontSize="12" :color="$color">{{ $label }}</native:text>
</native:row>
BLADE);

    $viewPath = __DIR__.'/views/test-anon-no-slot.blade.php';
    file_put_contents($viewPath, <<<'BLADE'
<native:column fill>
    <x-status-badge label="Online" color="#4CAF50" />
    <x-status-badge label="Offline" color="#FF0000" />
</native:column>
BLADE);

    NativeElementCollector::reset();
    view('test-anon-no-slot')->render();
    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('column');
    expect($tree['children'])->toHaveCount(2);

    // First badge
    $badge1 = $tree['children'][0];
    expect($badge1['type'])->toBe('row');
    expect($badge1['children'])->toHaveCount(2);
    expect($badge1['children'][0]['type'])->toBe('column'); // dot
    expect($badge1['children'][0]['style']['bg_color'])->toBe('#4CAF50');
    expect($badge1['children'][1]['type'])->toBe('text');
    expect($badge1['children'][1]['props']['text'])->toBe('Online');

    // Second badge
    $badge2 = $tree['children'][1];
    expect($badge2['type'])->toBe('row');
    expect($badge2['children'][1]['props']['text'])->toBe('Offline');
    expect($badge2['children'][0]['style']['bg_color'])->toBe('#FF0000');
});

it('renders @include partials with native elements', function () {
    // @include renders inline (no slot capture), so the collector stack stays balanced
    $partialPath = __DIR__.'/views/card-header.blade.php';
    file_put_contents($partialPath, <<<'BLADE'
<native:text :fontSize="18" :fontWeight="6" :color="$fg">{{ $title }}</native:text>
<native:text :fontSize="12" :color="$muted">{{ $subtitle }}</native:text>
<native:spacer :height="8" />
BLADE);

    $viewPath = __DIR__.'/views/test-include.blade.php';
    file_put_contents($viewPath, <<<'BLADE'
<native:column fill>
    <native:column fillWidth :padding="16" bg="#FFFFFF" :borderRadius="12" :gap="10">
        @include('card-header', ['title' => 'Section 1', 'subtitle' => 'Description', 'fg' => '#000000', 'muted' => '#666666'])
        <native:text>Content here</native:text>
    </native:column>
</native:column>
BLADE);

    NativeElementCollector::reset();
    view('test-include')->render();
    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('column');
    expect($tree['children'])->toHaveCount(1);

    $card = $tree['children'][0];
    expect($card['type'])->toBe('column');
    expect($card['style']['bg_color'])->toBe('#FFFFFF');
    expect($card['children'])->toHaveCount(4); // title, subtitle, spacer, content
    expect($card['children'][0]['props']['text'])->toBe('Section 1');
    expect($card['children'][1]['props']['text'])->toBe('Description');
    expect($card['children'][2]['type'])->toBe('spacer');
    expect($card['children'][3]['props']['text'])->toBe('Content here');
});

// ── @nativeError directive tests ────────────────────────────────────

it('renders nothing when $errors is empty', function () {
    $viewPath = __DIR__.'/views/test-error-empty.blade.php';
    file_put_contents($viewPath, <<<'BLADE'
<native:column>
    <native:text>Name</native:text>
    @nativeError('name')
</native:column>
BLADE);

    NativeElementCollector::reset();
    view('test-error-empty', ['errors' => []])->render();
    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('column');
    expect($tree['children'])->toHaveCount(1);
    expect($tree['children'][0]['props']['text'])->toBe('Name');
});

it('renders nothing when field has no error', function () {
    $viewPath = __DIR__.'/views/test-error-no-field.blade.php';
    file_put_contents($viewPath, <<<'BLADE'
<native:column>
    <native:text>Name</native:text>
    @nativeError('name')
</native:column>
BLADE);

    NativeElementCollector::reset();
    view('test-error-no-field', ['errors' => ['email' => 'Email is required']])->render();
    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('column');
    expect($tree['children'])->toHaveCount(1);
});

it('renders error text when field has an error', function () {
    $viewPath = __DIR__.'/views/test-error-present.blade.php';
    file_put_contents($viewPath, <<<'BLADE'
<native:column>
    <native:text>Name</native:text>
    @nativeError('name')
</native:column>
BLADE);

    NativeElementCollector::reset();
    view('test-error-present', ['errors' => ['name' => 'Name is required']])->render();
    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('column');
    expect($tree['children'])->toHaveCount(2);

    $errorNode = $tree['children'][1];
    expect($errorNode['type'])->toBe('text');
    expect($errorNode['props']['text'])->toBe('Name is required');
    expect($errorNode['props']['color'])->toBe('#FF0000');
    expect($errorNode['props']['font_size'])->toBe(12.0);
});

it('renders error text with custom color', function () {
    $viewPath = __DIR__.'/views/test-error-custom-color.blade.php';
    file_put_contents($viewPath, <<<'BLADE'
<native:column>
    <native:text>Email</native:text>
    @nativeError('email', '#E91E63')
</native:column>
BLADE);

    NativeElementCollector::reset();
    view('test-error-custom-color', ['errors' => ['email' => 'Invalid email']])->render();
    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['type'])->toBe('column');
    expect($tree['children'])->toHaveCount(2);

    $errorNode = $tree['children'][1];
    expect($errorNode['type'])->toBe('text');
    expect($errorNode['props']['text'])->toBe('Invalid email');
    expect($errorNode['props']['color'])->toBe('#E91E63');
    expect($errorNode['props']['font_size'])->toBe(12.0);
});
