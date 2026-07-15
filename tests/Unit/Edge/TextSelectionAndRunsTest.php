<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\TailwindParser;

/**
 * Two additive text features:
 *  - `select-text` / `select-none` (+ `->selectable()`) → a `selectable` prop
 *    the native renderers read to opt a subtree into native text selection.
 *  - Nested `<text>` runs serialize as a parent `text` node whose children are
 *    themselves `text` nodes — the tree shape the inline-rich-text renderers
 *    (AttributedString / AnnotatedString composition) walk.
 */
it('maps select-text / select-none to the selectable prop', function () {
    expect(TailwindParser::parse('select-text'))->toBe(['selectable' => 1]);
    expect(TailwindParser::parse('select-none'))->toBe(['selectable' => 0]);
});

it('carries selectable through the class() path onto any element', function () {
    $node = Text::make('hi')->class('select-text')->toArray(new CallbackRegistry);
    expect($node['props']['selectable'])->toBe(1);

    $off = Text::make('hi')->class('select-none')->toArray(new CallbackRegistry);
    expect($off['props']['selectable'])->toBe(0);
});

it('exposes a selectable() fluent method', function () {
    expect(Text::make('hi')->selectable()->toArray(new CallbackRegistry)['props']['selectable'])->toBe(1);
    expect(Text::make('hi')->selectable(false)->toArray(new CallbackRegistry)['props']['selectable'])->toBe(0);
});

it('nests child text runs as text nodes carrying their own styling', function () {
    $parent = Text::make();
    $parent->addChild(Text::make('Use '));
    $parent->addChild(Text::make('code')->bg('#eeeeee')->class('font-mono'));
    $parent->addChild(Text::make(' here.'));

    $node = $parent->toArray(new CallbackRegistry);

    expect($node['type'])->toBe('text');
    expect($node['children'])->toHaveCount(3);

    // Every run is its own text node — renderers concatenate them into one
    // wrapping attributed string.
    expect(collect($node['children'])->pluck('type')->all())->toBe(['text', 'text', 'text']);

    // The middle run keeps its own background (the inline-code chip) and font.
    expect($node['children'][1]['style']['bg_color'])->toBe('#eeeeee');
    expect($node['children'][1]['props']['text'])->toBe('code');
});

it('maps leading classes to the line_height / line_height_px props', function () {
    // Unitless multiplier of the font size.
    expect(Text::make('hi')->class('leading-snug')->toArray(new CallbackRegistry)['props']['line_height'])->toBe(1.375);

    // Arbitrary absolute px goes to its own prop, not the multiplier slot.
    expect(Text::make('hi')->class('leading-[22px]')->toArray(new CallbackRegistry)['props']['line_height_px'])->toBe(22.0);
});

it('maps the font attribute to the font_name prop', function () {
    $t = Text::make('hi');
    $t->applyAttributes(['font' => 'Inter-Bold']);

    expect($t->toArray(new CallbackRegistry)['props']['font_name'])->toBe('Inter-Bold');
});
