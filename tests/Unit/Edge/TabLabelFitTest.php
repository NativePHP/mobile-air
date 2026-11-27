<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\BottomNav;
use Native\Mobile\Edge\Elements\NativeRootTabs;
use Native\Mobile\Edge\Layouts\Builders\TabBar;

it('publishes the label fit options on the native root tabs, clamped', function (int $lines, float $scale, array $props) {
    $tabs = NativeRootTabs::make();
    $tabs->applyAttributes(TabBar::make()->labelMaxLines($lines)->labelMinScale($scale)->toRootProps());

    expect($tabs->toArray(new CallbackRegistry)['props'])->toMatchArray($props);
})->with([
    'in range' => [2, 0.75, ['label_max_lines' => 2, 'label_min_scale' => 0.75]],
    'out of range' => [0, 1.5, ['label_max_lines' => 1, 'label_min_scale' => 1.0]],
]);

it('reads the label fit options from an inline bottom-nav', function () {
    $nav = BottomNav::make();
    $nav->applyAttributes(['label-max-lines' => '2', 'label-min-scale' => '0.6']);

    expect(TabBar::fromElement($nav)->toRootProps())->toBe(['labelMaxLines' => 2, 'labelMinScale' => 0.6]);
});
