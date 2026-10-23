<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\NativeRootTabs;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ChromeScreen;
use Tests\Fixtures\Edge\PresentationTabsLayout;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

it('omits presentation metadata unless explicitly selected', function () {
    expect(TabBar::make()->toRootProps())->toBe([])
        ->not->toHaveKeys(['tabPresentationId', 'tabPresentationConfiguration']);
});

it('preserves the legacy root serialization when presentation is unused', function () {
    expect(TabBar::make()
        ->dark()
        ->labelVisibility('selected')
        ->activeColor('#112233')
        ->backgroundColor('#445566')
        ->textColor('#778899')
        ->font('Inter')
        ->minimizeOnScroll()
        ->toRootProps())->toBe([
            'dark' => true,
            'labelVisibility' => 'selected',
            'activeColor' => '#112233',
            'backgroundColor' => '#445566',
            'textColor' => '#778899',
            'fontName' => 'Inter',
            'minimizeOnScroll' => true,
        ]);
});

it('preserves presentation metadata through element conversion', function () {
    $element = TabBar::make()
        ->presentation('acme:floating', ['radius' => 24])
        ->toElement();

    expect(TabBar::fromElement($element)->toRootProps())->toBe([
        'tabPresentationId' => 'acme:floating',
        'tabPresentationConfiguration' => '{"radius":24}',
    ]);
});

it('serializes presentation metadata as bounded JSON root props', function () {
    $props = TabBar::make()->presentation('acme:floating', [
        'radius' => 24,
        'glass' => true,
        'labels' => ['Home', 'Profile'],
    ])->toRootProps();

    expect($props['tabPresentationId'])->toBe('acme:floating')
        ->and(json_decode($props['tabPresentationConfiguration'], true, flags: JSON_THROW_ON_ERROR))->toBe([
            'radius' => 24,
            'glass' => true,
            'labels' => ['Home', 'Profile'],
        ]);
});

it('serializes an empty configuration as an object', function () {
    expect(TabBar::make()->presentation('system')->toRootProps()['tabPresentationConfiguration'])
        ->toBe('{}');
});

it('preserves an unknown valid presentation identifier for native fallback', function () {
    expect(TabBar::make()->presentation('example:missing')->toRootProps())->toBe([
        'tabPresentationId' => 'example:missing',
        'tabPresentationConfiguration' => '{}',
    ]);
});

it('maps presentation metadata onto the native root wire names', function () {
    $root = NativeRootTabs::make();
    $root->applyAttributes(TabBar::make()->presentation('acme:minimal', ['gap' => 8])->toRootProps());

    expect($root->toArray(new CallbackRegistry)['props'])
        ->tab_presentation_id->toBe('acme:minimal')
        ->tab_presentation_configuration->toBe('{"gap":8}');
});

it('publishes a layout presentation on the existing native root tabs', function () {
    $tree = Native::test(ChromeScreen::class, layout: PresentationTabsLayout::class)->tree();

    expect($tree['type'])->toBe('native_root_tabs')
        ->and($tree['props']['tab_presentation_id'])->toBe('acme:floating')
        ->and($tree['props']['tab_presentation_configuration'])->toBe('{"radius":24}')
        ->and($tree['props'])->not->toHaveKey('hide_tab_bar')
        ->and($tree['props'])->not->toHaveKey('hide_nav_bar');
});

it('rejects invalid presentation identifiers', function (string $identifier) {
    TabBar::make()->presentation($identifier);
})->with([
    '',
    'Uppercase',
    'two:namespace:separators',
    'leading-dash' => '-leading',
    'trailing-dash' => 'trailing-',
    'spaces are unsafe',
    str_repeat('a', TabBar::MAX_PRESENTATION_IDENTIFIER_LENGTH + 1),
])->throws(InvalidArgumentException::class);

it('rejects unsupported presentation configuration values', function (mixed $value) {
    TabBar::make()->presentation('acme:test', ['value' => $value]);
})->with([
    'object' => new stdClass,
    'infinity' => INF,
    'negative infinity' => -INF,
    'not a number' => NAN,
    'invalid utf8' => "\xB1\x31",
])->throws(InvalidArgumentException::class);

it('rejects closures in presentation configuration', function () {
    TabBar::make()->presentation('acme:test', ['value' => fn () => null]);
})->throws(InvalidArgumentException::class);

it('rejects resources in presentation configuration', function () {
    $resource = fopen('php://memory', 'r');
    try {
        TabBar::make()->presentation('acme:test', ['resource' => $resource]);
    } finally {
        fclose($resource);
    }
})->throws(InvalidArgumentException::class);

it('rejects top-level lists, excessive depth, and excessive encoded size', function (array $configuration) {
    TabBar::make()->presentation('acme:test', $configuration);
})->with([
    'top-level list' => [[1, 2]],
    'depth' => [['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => true]]]]]]]]]],
    'size' => [['value' => str_repeat('x', TabBar::MAX_PRESENTATION_CONFIGURATION_BYTES)]],
])->throws(InvalidArgumentException::class);
