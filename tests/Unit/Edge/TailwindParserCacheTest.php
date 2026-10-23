<?php

use Native\Mobile\Edge\TailwindParser;

beforeEach(fn () => TailwindParser::clearCache());

function parserCacheSize(): int
{
    return count((fn () => self::$cache)->call(new TailwindParser));
}

// A screen that re-renders with changing class strings (an absolutely
// positioned piece moving across it, a progress bar's width) parses a new
// string every frame. The persistent runtime keeps the parser's static cache
// for the whole session, so it has to stay bounded.

it('keeps the parse cache bounded when class strings keep changing', function () {
    for ($i = 0; $i < 10_000; $i++) {
        TailwindParser::parse("absolute left-[{$i}] top-[{$i}.5] w-[22] h-[22]");
    }

    expect(parserCacheSize())->toBeLessThanOrEqual(TailwindParser::CACHE_LIMIT)
        ->and(count((fn () => self::$unsupportedCache)->call(new TailwindParser)))->toBeLessThanOrEqual(TailwindParser::CACHE_LIMIT);
});

it('still returns the same result for a class string after the cache has rolled over', function () {
    $expected = TailwindParser::parse('p-4 w-[22]');

    for ($i = 0; $i < TailwindParser::CACHE_LIMIT * 3; $i++) {
        TailwindParser::parse("left-[{$i}]");
    }

    expect(TailwindParser::parse('p-4 w-[22]'))->toBe($expected);
});

it('keeps the strings a screen keeps using while dropping the oldest', function () {
    TailwindParser::parse('p-4');

    for ($i = 0; $i < TailwindParser::CACHE_LIMIT - 1; $i++) {
        TailwindParser::parse("left-[{$i}]");
    }
    // Full now; the next new string evicts the oldest half, 'p-4' among them.
    TailwindParser::parse('left-[999999]');
    $cache = (fn () => self::$cache)->call(new TailwindParser);

    expect($cache)->not->toHaveKey('p-4')
        ->and($cache)->toHaveKey('left-[999999]')
        ->and($cache)->toHaveKey('left-['.(TailwindParser::CACHE_LIMIT - 2).']');
});
