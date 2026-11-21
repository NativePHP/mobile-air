<?php

use Illuminate\Support\Facades\Blade;
use Native\Mobile\Edge\NativeTagPrecompiler;

/**
 * Blade compiles `<x-*>` component tags before any precompiler runs, so
 * the precompiler sees Blade's intermediate form. These pin the collector
 * calls it adds around that form (see compileComponentSlots()).
 */
beforeEach(function () {
    $this->precompiler = new NativeTagPrecompiler;
    NativeTagPrecompiler::setActive(true);
});

afterEach(function () {
    NativeTagPrecompiler::setActive(false);
});

$collector = '\\Native\\Mobile\\Edge\\NativeElementCollector';
$marker = '<?php '.NativeTagPrecompiler::COMPILED_MARKER.' ?>';

// What Blade hands over for `<x-card>`: the opening directive, then the
// attribute code that ends in the withAttributes() block.
$componentOpen = "##BEGIN-COMPONENT-CLASS##@component('Illuminate\\View\\AnonymousComponent', 'card', ['view' => 'components.card','data' => []])\n"
    ."<?php if (isset(\$attributes) && \$attributes instanceof Illuminate\\View\\ComponentAttributeBag): ?>\n"
    ."<?php \$attributes = \$attributes->except(\\Illuminate\\View\\AnonymousComponent::ignoredParameterNames()); ?>\n"
    ."<?php endif; ?>\n";

// And for `</x-card>`.
$componentClose = ' @endComponentClass##END-COMPONENT-CLASS##';

it('brackets a component with slot capture calls', function () use ($collector, $marker, $componentOpen, $componentClose) {
    $withAttributes = "<?php \$component->withAttributes(['class' => 'p-4']); ?>\n";

    $result = ($this->precompiler)($componentOpen.$withAttributes.'<native:spacer />'.$componentClose);

    expect($result)->toBe(
        $marker
        ."<?php {$collector}::flushSlots(); ?>"
        .$componentOpen
        .$withAttributes
        ."<?php {$collector}::beginComponent(); ?>"
        ."<?php {$collector}::leaf('spacer', []); ?>"
        ." <?php echo {$collector}::endSlot(); ?>@endComponentClass##END-COMPONENT-CLASS##"
        ."<?php {$collector}::endComponent(); ?>"
    );
});

it('begins the capture after a withAttributes block that contains a closing tag', function () use ($collector, $componentOpen, $componentClose) {
    $withAttributes = "<?php \$component->withAttributes(['hint' => 'ends with ?>']); ?>\n";

    $result = ($this->precompiler)($componentOpen.$withAttributes.'body'.$componentClose);

    expect($result)->toContain($withAttributes."<?php {$collector}::beginComponent(); ?>body");
    expect(substr_count($result, '::beginComponent()'))->toBe(1);
});

it('brackets each of two components that follow one another', function () use ($componentOpen) {
    $selfClosing = $componentOpen."<?php \$component->withAttributes([]); ?>\n@endComponentClass##END-COMPONENT-CLASS##";

    $result = ($this->precompiler)($selfClosing.$selfClosing);

    foreach (['::flushSlots()', '::beginComponent()', '::endSlot()', '::endComponent()'] as $call) {
        expect(substr_count($result, $call))->toBe(2, $call);
    }
});

it('captures a named slot', function () use ($collector, $marker) {
    $result = ($this->precompiler)(" @slot('actions', null, ['class' => 'gap-2']) <native:spacer /> @endslot");

    expect($result)->toBe(
        $marker
        ." <?php {$collector}::beginSlot(); ?>@slot('actions', null, ['class' => 'gap-2']) "
        ."<?php {$collector}::leaf('spacer', []); ?>"
        ." <?php echo {$collector}::endSlot(); ?>@endslot"
    );
});

it('does not start a capture for a slot given as a string', function () use ($marker) {
    // No buffer and no @endslot follow this form, so a capture would never close.
    $input = "@slot('title', 'Short title')";

    expect(($this->precompiler)($input))->toBe($marker.$input);
});

it('places pending slots before anything that opens a new buffer', function (string $directive) use ($collector, $marker) {
    expect(($this->precompiler)($directive))->toBe($marker."<?php {$collector}::flushSlots(); ?>".$directive);
})->with([
    "@include('partial')",
    "@includeWhen(\$show, 'partial')",
    "@each('partial', \$items, 'item')",
    "@component('components.card')",
]);

it('leaves lookalike directives alone', function (string $input) use ($marker) {
    expect(($this->precompiler)($input))->toBe($marker.$input);
})->with([
    'escaped' => "@@include('partial')",
    'foreach' => '@foreach ($items as $item)',
    'mid-word' => "mail@include('partial')",
]);

it('leaves directive text inside a native tag attribute alone', function () use ($collector, $marker) {
    $result = ($this->precompiler)('<native:spacer hint="Use @include(\'x\') then @endslot" />');

    expect($result)->toBe($marker."<?php {$collector}::leaf('spacer', ['hint' => 'Use @include(\\'x\\') then @endslot']); ?>");
});

it('leaves components untouched unless native compilation is active', function () use ($componentOpen, $componentClose) {
    NativeTagPrecompiler::setActive(false);

    $input = $componentOpen."<?php \$component->withAttributes([]); ?>\n @slot('a', null, []) x @endslot @include('partial')".$componentClose;

    expect(($this->precompiler)($input))->toBe($input);
});

it('orders the calls around what Blade really compiles a component to', function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    $compiled = Blade::compileString(
        '<x-box.named><x-slot:actions><native:spacer /></x-slot:actions><native:divider /></x-box.named>'
    );

    $sequence = [
        '::flushSlots()',
        '$__env->startComponent(',
        '$component->withAttributes(',
        '::beginComponent()',
        '::beginSlot()',
        "\$__env->slot('actions'",
        "::leaf('spacer'",
        'echo \\Native\\Mobile\\Edge\\NativeElementCollector::endSlot()',
        '$__env->endSlot()',
        "::leaf('divider'",
        'echo \\Native\\Mobile\\Edge\\NativeElementCollector::endSlot()',
        '$__env->renderComponent()',
        '::endComponent()',
    ];

    $offset = 0;

    foreach ($sequence as $piece) {
        $found = strpos($compiled, $piece, $offset);

        expect($found)->not->toBeFalse("[{$piece}] is missing or out of order");

        $offset = $found + strlen($piece);
    }
});
