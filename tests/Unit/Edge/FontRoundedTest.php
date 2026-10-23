<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\TailwindParser;

it('parses font-rounded as the rounded font design', function () {
    expect(TailwindParser::parse('font-rounded'))->toBe(['fontFamily' => 3]);
});

it('keeps font-rounded apart from the font weights', function () {
    expect(TailwindParser::parse('font-rounded font-bold'))
        ->toMatchArray(['fontFamily' => 3, 'fontWeight' => TailwindParser::parse('font-bold')['fontWeight']]);
});

it('sends the rounded design on a text node', function () {
    $props = Text::make('42')->class('font-rounded')->toArray(new CallbackRegistry)['props'];

    expect($props['font_family'] ?? null)->toBe(3);
});
