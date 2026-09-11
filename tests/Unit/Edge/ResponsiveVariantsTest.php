<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\LazyGrid;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\TailwindParser;

beforeEach(function () {
    NativeElementCollector::reset();
    TailwindParser::clearCache();
    TailwindParser::setBreakpoints(null);
    ElementRegistry::reset();
    ElementRegistry::register('text', Text::class);
    ElementRegistry::register('lazy_grid', LazyGrid::class);
});

afterEach(function () {
    TailwindParser::setBreakpoints(null);
    ElementRegistry::reset();
});

function collectedVariants(array $tree): array
{
    return json_decode($tree['props']['_variants'], true);
}

// ── Parser ──────────────────────────────────────────

it('buckets a breakpoint-prefixed class under variants', function () {
    expect(TailwindParser::parse('md:p-4'))->toBe(['variants' => ['md' => ['padding' => 16]]]);
    expect(TailwindParser::parse('lg:flex-row'))->toBe(['variants' => ['lg' => ['flexDirection' => 1]]]);
});

it('merges classes that share a breakpoint into one bucket', function () {
    expect(TailwindParser::parse('p-2 md:p-4 md:flex-row lg:p-8'))->toBe([
        'padding' => 8,
        'variants' => [
            'md' => ['padding' => 16, 'flexDirection' => 1],
            'lg' => ['padding' => 32],
        ],
    ]);
});

it('composes breakpoint, platform and dark prefixes in either order', function () {
    TailwindParser::setPlatform('ios');

    expect(TailwindParser::parse('md:ios:p-4'))->toBe(['variants' => ['md' => ['padding' => 16]]]);
    expect(TailwindParser::parse('ios:md:p-4'))->toBe(['variants' => ['md' => ['padding' => 16]]]);
    // Other-platform variants drop silently, exactly like the unprefixed form.
    expect(TailwindParser::parse('md:android:p-4'))->toBe([]);
    expect(TailwindParser::parse('md:dark:bg-[#111111] md:dark:opacity-50'))->toBe([
        'variants' => ['md' => ['dark' => ['bg' => '#111111', 'opacity' => 0.5]]],
    ]);

    TailwindParser::setPlatform(null);
});

it('treats an unknown prefix as an unsupported class', function () {
    expect(TailwindParser::parse('huge:p-4'))->toBe([]);
});

it('honours a custom breakpoint table', function () {
    TailwindParser::setBreakpoints(['tablet' => 700]);

    expect(TailwindParser::parse('tablet:p-4'))->toBe(['variants' => ['tablet' => ['padding' => 16]]]);
    expect(TailwindParser::parse('md:p-4'))->toBe([]);
    expect(TailwindParser::breakpointMinWidth('tablet'))->toBe(700.0);
    expect(TailwindParser::breakpointMinWidth('md'))->toBeNull();
});

it('parses grid-cols-N into gridColumns', function () {
    expect(TailwindParser::parse('grid-cols-3'))->toBe(['gridColumns' => 3]);
    expect(TailwindParser::parse('grid-cols-0'))->toBe(['gridColumns' => 1]);
    expect(TailwindParser::parse('md:grid-cols-2'))->toBe(['variants' => ['md' => ['gridColumns' => 2]]]);
});

// ── Wire ────────────────────────────────────────────

it('ships variants as one _variants prop of per-breakpoint deltas sorted by min width', function () {
    NativeElementCollector::open('column', ['class' => 'p-2 lg:p-8 md:flex-row md:bg-[#FF0000]']);
    NativeElementCollector::close();

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['layout']['padding'])->toBe(8.0);
    expect(collectedVariants($tree))->toEqual([
        ['min' => 768.0, 'layout' => ['flex_direction' => 1], 'style' => ['bg_color' => '#FF0000']],
        ['min' => 1024.0, 'layout' => ['padding' => 32.0]],
    ]);
});

it('carries text and grid keys a variant cannot apply through an element', function () {
    NativeElementCollector::leaf('text', ['text' => 'Hi', 'class' => 'text-sm md:text-2xl md:font-bold']);
    $text = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect(collectedVariants($text))->toEqual([
        ['min' => 768.0, 'props' => ['font_size' => 24.0, 'font_weight' => 6]],
    ]);

    NativeElementCollector::reset();
    NativeElementCollector::open('lazy_grid', ['class' => 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3']);
    NativeElementCollector::close();
    $grid = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($grid['props']['columns'])->toBe(1);
    expect(collectedVariants($grid))->toEqual([
        ['min' => 768.0, 'props' => ['columns' => 2]],
        ['min' => 1024.0, 'props' => ['columns' => 3]],
    ]);
});

it('emits no _variants prop when no breakpoint class is present', function () {
    NativeElementCollector::open('column', ['class' => 'p-2 flex-row']);
    NativeElementCollector::close();

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['props'] ?? [])->not->toHaveKey('_variants');
});

it('ships variants from the programmatic class() path too', function () {
    $tree = Column::make()->class('p-2 md:p-4')->toArray(new CallbackRegistry);

    expect(collectedVariants($tree))->toEqual([
        ['min' => 768.0, 'layout' => ['padding' => 16.0]],
    ]);
});
