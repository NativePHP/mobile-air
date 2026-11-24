<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\BottomBar;
use Native\Mobile\Edge\Elements\BottomNav;
use Native\Mobile\Edge\Elements\NativeRootTabs;
use Native\Mobile\Edge\Layouts\Builders\TabBar;

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

it('lets a tab bar builder pick its edge blur', function (string $style) {
    expect(TabBar::make()->edgeBlur($style)->toRootProps()['edgeBlur'] ?? null)->toBe($style)
        ->and(TabBar::make()->edgeBlur($style)->toElement()->getRawProps()['edge_blur'] ?? null)->toBe($style);
})->with(['soft', 'hard', 'none']);

it('leaves a tab bar on the default edge blur unless asked', function () {
    expect(TabBar::make()->toRootProps())->not->toHaveKey('edgeBlur');
});

it('refuses an edge blur a tab bar does not know', function () {
    TabBar::make()->edgeBlur('thicc');
})->throws(InvalidArgumentException::class);

it('reads edge-blur off an inline bottom-nav and carries it to the tabs root', function () {
    $nav = BottomNav::make();
    $nav->applyAttributes(['edge-blur' => 'hard']);

    expect($nav->getRawProps()['edge_blur'] ?? null)->toBe('hard');

    $root = NativeRootTabs::make();
    $root->applyAttributes(TabBar::fromElement($nav)->toRootProps());

    expect($root->toArray(new CallbackRegistry)['props']['edge_blur'] ?? null)->toBe('hard');
});

it('drops an edge-blur value a bottom-nav does not know', function () {
    $nav = BottomNav::make();
    $nav->applyAttributes(['edge-blur' => 'thicc']);

    expect($nav->getRawProps())->not->toHaveKey('edge_blur');
});
