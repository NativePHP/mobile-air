<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\TailwindParser;

/**
 * `text-outline` draws a text run hollow: its glyphs' outline, this many points
 * wide, in the run's own text colour. The renderers read it as `text_outline`.
 */
beforeEach(fn () => NativeElementCollector::reset());

it('parses an outline width in points', function () {
    expect(TailwindParser::parse('text-outline'))->toBe(['textOutline' => 1.0]);
    expect(TailwindParser::parse('text-outline-2'))->toBe(['textOutline' => 2.0]);
    expect(TailwindParser::parse('text-outline-0'))->toBe(['textOutline' => 0.0]);
    expect(TailwindParser::parse('text-outline-[1.5]'))->toBe(['textOutline' => 1.5]);
    expect(TailwindParser::parse('text-outline-[1.5px]'))->toBe(['textOutline' => 1.5]);
    expect(TailwindParser::parse('text-outline-[bogus]'))->toBe([]);
});

it('leaves text colours that are not outlines alone', function () {
    expect(TailwindParser::parse('text-theme-outline'))->not->toHaveKey('textOutline');
    expect(TailwindParser::parse('text-red-500'))->not->toHaveKey('textOutline');
});

it('carries the outline width as the text_outline prop', function () {
    expect(Text::make('/')->class('text-outline text-red-500')->toArray(new CallbackRegistry)['props'])
        ->toMatchArray(['text_outline' => 1.0]);

    NativeElementCollector::leaf('text', ['text' => '/', 'text-outline' => '1.5']);

    expect(NativeElementCollector::collect()->toArray(new CallbackRegistry)['props']['text_outline'])->toBe(1.5);
});

it('outlines just the run it is on inside composed text', function () {
    $parent = Text::make();
    $parent->addChild(Text::make('/dev'));
    $parent->addChild(Text::make('/')->class('text-outline'));
    $parent->addChild(Text::make('null'));

    $runs = $parent->toArray(new CallbackRegistry)['children'];

    expect(array_map(fn (array $run): ?float => $run['props']['text_outline'] ?? null, $runs))->toBe([null, 1.0, null]);
});
