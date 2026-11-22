<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\BottomBar;

function bottomBarProps(array $attrs): array
{
    $bar = BottomBar::make();
    $bar->applyAttributes($attrs);

    return $bar->toArray(new CallbackRegistry)['props'] ?? [];
}

it('sends the edge blur a bottom bar asks for', function (string $value, string $style) {
    expect(bottomBarProps(['edge-blur' => $value])['edge_blur'] ?? null)->toBe($style);
})->with([
    'soft' => ['soft', 'soft'],
    'hard' => ['hard', 'hard'],
    'any case' => [' Hard ', 'hard'],
]);

it('takes the camelCase attribute too', function () {
    expect(bottomBarProps(['edgeBlur' => 'soft'])['edge_blur'] ?? null)->toBe('soft');
});

it('sends no edge blur for none, a typo or no attribute', function (array $attrs) {
    expect(bottomBarProps($attrs))->not->toHaveKey('edge_blur');
})->with([
    'none' => [['edge-blur' => 'none']],
    'typo' => [['edge-blur' => 'thicc']],
    'unset' => [[]],
]);
