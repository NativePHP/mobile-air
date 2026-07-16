<?php

use Native\Mobile\Edge\NativeTagPrecompiler;

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

it('rewrites @press to _press', function () {
    $result = ($this->precompiler)('<native:button label="+" @press="increment" />');

    expect($result)->toContain("'_press' => 'increment'");
    expect($result)->not->toContain('@press');
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
    $input = '<native:column fill><native:text :fontSize="20">Hi</native:text><native:button label="OK" @press="ok" /></native:column>';
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
