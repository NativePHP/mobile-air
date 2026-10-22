<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Image;
use Native\Mobile\Edge\Enums\ImageFit;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\TailwindParser;

beforeEach(function () {
    NativeElementCollector::reset();
});

afterEach(function () {
    NativeElementCollector::reset();
});

/*
 * The backing values are what mobile-ui's image renderers switch on, so a
 * change here silently changes how every image is drawn. Pin them.
 */
it('pins the fit wire values to the native image renderers', function () {
    expect([
        ImageFit::None->value, ImageFit::Contain->value,
        ImageFit::Cover->value, ImageFit::Fill->value,
    ])->toBe([0, 1, 2, 3]);
});

it('resolves fit names to cases', function () {
    expect(ImageFit::fromLabel('cover'))->toBe(ImageFit::Cover);
    expect(ImageFit::fromLabel(' Contain '))->toBe(ImageFit::Contain);
    expect(ImageFit::fromLabel('scale-down'))->toBe(ImageFit::Contain);
    expect(ImageFit::fromLabel('stretch'))->toBe(ImageFit::Fill);
    expect(ImageFit::fromLabel('none'))->toBe(ImageFit::None);
    expect(ImageFit::fromLabel('center'))->toBeNull();
});

it('accepts a case, a name or a wire value on the fluent setter', function () {
    $fit = fn (mixed $mode) => Image::make('a.png')->fit($mode)->toArray(new CallbackRegistry)['props']['fit'] ?? null;

    expect($fit(ImageFit::Cover))->toBe(2);
    expect($fit('fill'))->toBe(3);
    expect($fit(1))->toBe(1);
    expect($fit('0'))->toBe(0);
});

it('leaves fit unset for a value it does not know', function () {
    $props = Image::make('a.png')->fit('blur')->toArray(new CallbackRegistry)['props'];
    expect($props)->not->toHaveKey('fit');

    $props = Image::make('a.png')->fit(9)->toArray(new CallbackRegistry)['props'];
    expect($props)->not->toHaveKey('fit');
});

it('resolves a fit attribute by name through the Blade collector', function () {
    NativeElementCollector::leaf('image', ['src' => 'a.png', 'fit' => 'cover']);

    $tree = NativeElementCollector::collect()->toArray(new CallbackRegistry);

    expect($tree['props']['fit'])->toBe(ImageFit::Cover->value);
});

it('maps the object-* classes to fit cases', function () {
    expect(TailwindParser::parse('object-none'))->toMatchArray(['fit' => ImageFit::None->value]);
    expect(TailwindParser::parse('object-contain'))->toMatchArray(['fit' => ImageFit::Contain->value]);
    expect(TailwindParser::parse('object-cover'))->toMatchArray(['fit' => ImageFit::Cover->value]);
    expect(TailwindParser::parse('object-fill'))->toMatchArray(['fit' => ImageFit::Fill->value]);
    expect(TailwindParser::parse('object-scale-down'))->toMatchArray(['fit' => ImageFit::Contain->value]);
});
