<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Image;
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
    ElementRegistry::register('image', Image::class);
});

afterEach(function () {
    TailwindParser::setBreakpoints(null);
    ElementRegistry::reset();
});

function collectedVariants(array $tree): array
{
    return json_decode($tree['props']['_variants'], true);
}

function collectColumn(string $class): array
{
    NativeElementCollector::reset();
    NativeElementCollector::open('column', ['class' => $class]);
    NativeElementCollector::close();

    return NativeElementCollector::collect()->toArray(new CallbackRegistry);
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

it('parses dark:md: into the same shape as md:dark:', function () {
    expect(TailwindParser::parse('dark:md:bg-[#111111]'))
        ->toBe(TailwindParser::parse('md:dark:bg-[#111111]'))
        ->toBe(['variants' => ['md' => ['dark' => ['bg' => '#111111']]]]);
});

it('maps hidden and the display utilities to display', function () {
    expect(TailwindParser::parse('hidden'))->toBe(['display' => 1]);
    expect(TailwindParser::parse('flex'))->toBe(['display' => 0]);
    expect(TailwindParser::parse('hidden md:flex'))->toBe(['display' => 1, 'variants' => ['md' => ['display' => 0]]]);
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

// ── Building on the base ────────────────────────────

it('keeps the base edges a breakpoint does not override', function () {
    expect(collectedVariants(collectColumn('p-4 md:px-8')))->toEqual([
        ['min' => 768.0, 'layout' => ['padding' => [16.0, 32.0, 16.0, 32.0]]],
    ]);
    expect(collectedVariants(collectColumn('mt-2 md:mx-4')))->toEqual([
        ['min' => 768.0, 'layout' => ['margin' => [8.0, 16.0, 0.0, 16.0]]],
    ]);
});

it('keeps the base radius on corners a breakpoint does not round', function () {
    expect(collectedVariants(collectColumn('rounded-xl md:rounded-t-3xl')))->toEqual([
        ['min' => 768.0, 'props' => ['radius_tl' => 24.0, 'radius_tr' => 24.0, 'radius_br' => 12.0, 'radius_bl' => 12.0]],
    ]);
});

it('widens a border without restating the base border colour', function () {
    // A width alone never reached the wire before — the style builder
    // only emits a border with its colour. The colour is unchanged, so
    // only the width ships and the native fold keeps the base colour.
    expect(collectedVariants(collectColumn('border border-[#FF0000] md:border-2')))->toEqual([
        ['min' => 768.0, 'style' => ['border_width' => 2.0]],
    ]);
});

it('ships only what each breakpoint changes over the narrower ones', function () {
    expect(collectedVariants(collectColumn('p-2 sm:p-4 sm:flex-row md:p-4 lg:flex-col')))->toEqual([
        ['min' => 640.0, 'layout' => ['padding' => 16.0, 'flex_direction' => 1]],
        ['min' => 1024.0, 'layout' => ['flex_direction' => 0]],
    ]);
});

it('ships dark overrides from either prefix order', function () {
    expect(collectedVariants(collectColumn('dark:md:bg-[#111111]')))
        ->toEqual(collectedVariants(collectColumn('md:dark:bg-[#111111]')))
        ->toEqual([['min' => 768.0, 'props' => ['dark_bg_color' => '#111111']]]);
});

it('shows a hidden node from a breakpoint up', function () {
    $tree = collectColumn('hidden md:flex');

    expect($tree['layout']['display'])->toBe(1);
    expect(collectedVariants($tree))->toEqual([
        ['min' => 768.0, 'layout' => ['display' => 0]],
    ]);
});

it('carries element props exactly as the unprefixed class would', function () {
    NativeElementCollector::leaf('text', ['text' => 'Hi', 'class' => 'md:tracking-wide md:leading-loose md:uppercase md:italic md:underline md:font-mono md:text-7xl']);
    $text = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect(collectedVariants($text))->toEqual([
        ['min' => 768.0, 'props' => [
            'font_size' => 72.0,
            'font_style' => 1,
            'font_family' => 2,
            'underline' => 1,
            'text_transform' => 1,
            'letter_spacing' => 0.025,
            'line_height' => 2.0,
        ]],
    ]);

    NativeElementCollector::reset();
    NativeElementCollector::leaf('image', ['src' => 'a.png', 'class' => 'object-cover md:object-contain']);
    $image = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect(collectedVariants($image))->toEqual([
        ['min' => 768.0, 'props' => ['fit' => 1]],
    ]);
});

it('does not register callbacks while building breakpoints', function () {
    NativeElementCollector::leaf('text', ['text' => 'A', '_press' => 'handleA', 'class' => 'p-2 md:p-4']);
    $registry = new CallbackRegistry;
    $tree = NativeElementCollector::collect()->toArray($registry);

    expect($tree['on_press'])->toBe($registry->lookup('handleA'));
    expect($registry->expressions())->toHaveCount(1);
});
