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

it('leaves unknown theme tokens unresolved rather than guessing', function () {
    expect(TailwindParser::parse('bg-theme-nonexistent'))->toBe([]);
});
