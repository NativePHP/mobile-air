<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeElementCollector;

/**
 * `min-scale` lets text that is too wide for its lines shrink, down to that
 * share of its font size, before it truncates. The renderers read it as
 * `min_scale`.
 */
beforeEach(fn () => NativeElementCollector::reset());

it('carries the min-scale attribute as the min_scale prop', function () {
    NativeElementCollector::leaf('text', ['text' => '12,345', 'max-lines' => '1', 'min-scale' => '0.5']);

    expect(NativeElementCollector::collect()->toArray(new CallbackRegistry)['props'])
        ->toMatchArray(['max_lines' => 1, 'min_scale' => 0.5]);
});

it('takes min-scale fluently', function () {
    expect(Text::make('12,345')->maxLines(1)->minScale(0.4)->toArray(new CallbackRegistry)['props'])
        ->toMatchArray(['max_lines' => 1, 'min_scale' => 0.4]);
});

it('clamps min-scale to a share between 0 and 1', function () {
    expect(Text::make('12,345')->minScale(1.5)->toArray(new CallbackRegistry)['props']['min_scale'])->toBe(1.0);
    expect(Text::make('12,345')->minScale(-0.5)->toArray(new CallbackRegistry)['props']['min_scale'])->toBe(0.0);
});

it('leaves min_scale out when it is not set', function () {
    expect(Text::make('12,345')->toArray(new CallbackRegistry)['props'])->not->toHaveKey('min_scale');
});
