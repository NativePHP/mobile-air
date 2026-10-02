<?php

use Native\Mobile\Edge\TailwindParser;

/**
 * Theme-aware color classes (`bg-theme-*` / `text-theme-*` / `border-theme-*`):
 *  - tokens are open-ended — whatever key the registered resolver answers for
 *    works, including app-defined tokens like `success` or `outline-variant`;
 *  - the standard trailing `/N` opacity modifier applies to the RESOLVED
 *    token color (light AND the dark companion), same as palette/hex classes.
 */
beforeEach(function () {
    TailwindParser::setThemeResolver(fn (string $token): ?string => match ($token) {
        'primary' => '#FF6B00',
        'success' => '#00E475',
        'outline-variant' => '#3E4246',
        default => null,
    });
    TailwindParser::setThemeDarkResolver(fn (string $token): ?string => match ($token) {
        'primary' => '#FFA85C',
        default => null,
    });
});

afterEach(function () {
    TailwindParser::setThemeResolver(null);
    TailwindParser::setThemeDarkResolver(null);
});

it('resolves app-defined theme tokens, not just the shipped set', function () {
    expect(TailwindParser::parse('bg-theme-success'))->toBe(['bg' => '#00E475'])
        ->and(TailwindParser::parse('border-theme-outline-variant'))
        ->toBe(['borderColor' => '#3E4246', 'borderWidth' => 1]);
});

it('applies opacity modifiers to resolved theme colors', function () {
    // 15% → 0x26 alpha byte, prepended in wire order.
    expect(TailwindParser::parse('bg-theme-success/15'))->toBe(['bg' => '#2600E475'])
        ->and(TailwindParser::parse('text-theme-success/50'))->toBe(['color' => '#8000E475']);
});

it('applies the opacity modifier to the dark companion too', function () {
    $parsed = TailwindParser::parse('bg-theme-primary/50');

    expect($parsed['bg'])->toBe('#80FF6B00')
        ->and($parsed['dark']['bg'])->toBe('#80FFA85C');
});

it('lets an explicit border width beat the one a theme border implies', function () {
    // A theme border needs *a* width to be visible at all, but asserting one made the
    // outcome depend on class order: `border-2 border-theme-primary` rendered 1px while
    // `border-theme-primary border-2` rendered 2px, with nothing on screen to explain it.
    expect(TailwindParser::parse('border-2 border-theme-primary'))
        ->toMatchArray(['borderColor' => '#FF6B00', 'borderWidth' => 2])
        ->and(TailwindParser::parse('border-theme-primary border-2'))
        ->toMatchArray(['borderColor' => '#FF6B00', 'borderWidth' => 2]);
});

it('still gives a bare theme border a width of its own', function () {
    expect(TailwindParser::parse('border-theme-success'))
        ->toBe(['borderColor' => '#00E475', 'borderWidth' => 1]);
});

it('never leaks the internal default-width key into the parsed output', function () {
    // parse() resolves it at the end; the renderers read this array directly, so a
    // leftover key would reach the wire as an unknown style prop.
    expect(TailwindParser::parse('border-theme-primary'))->not->toHaveKey('borderWidthDefault')
        ->and(TailwindParser::parse('border-4 border-theme-primary'))->not->toHaveKey('borderWidthDefault');
});

it('leaves unknown theme tokens unresolved rather than guessing', function () {
    expect(TailwindParser::parse('bg-theme-nonexistent'))->toBe([]);
});
