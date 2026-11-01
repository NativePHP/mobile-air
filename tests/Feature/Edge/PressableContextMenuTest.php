<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Pressable;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeTagPrecompiler;

/**
 * `:context-menu` on a pressable: the `:menu` items, opened by a long press,
 * while a tap still fires `@press`.
 */
beforeEach(function () {
    NativeElementCollector::reset();
    NativeTagPrecompiler::setActive(true);

    $this->viewPath = sys_get_temp_dir().'/pressable-context-menu-'.getmypid();

    if (! is_dir($this->viewPath)) {
        mkdir($this->viewPath, 0755, true);
    }

    app('view')->addLocation($this->viewPath);
});

afterEach(function () {
    NativeTagPrecompiler::setActive(false);
    NativeElementCollector::reset();

    foreach (glob($this->viewPath.'/*.php') as $file) {
        unlink($file);
    }
});

/**
 * @param  array<string, mixed>  $data
 * @return array{0: array<string, mixed>, 1: CallbackRegistry}
 */
function renderPressable(string $test, string $blade, array $data = []): array
{
    file_put_contents(test()->viewPath."/{$test}.blade.php", $blade);

    NativeElementCollector::reset();
    view($test, $data)->render();

    $registry = new CallbackRegistry;

    return [NativeElementCollector::collect()->toArray($registry), $registry];
}

it('opens a context menu on a long press and keeps the tap for @press', function () {
    [$tree, $registry] = renderPressable('context-menu', <<<'BLADE'
        <native:pressable @press="open('42')" :context-menu="$items">
            <native:text>Ada Lovelace</native:text>
        </native:pressable>
        BLADE, ['items' => [
        NavAction::make('pin')->label('Pin')->icon('pin')->press("pin('42')"),
        NavAction::divider(),
        NavAction::make('block')->label('Block @ada')->icon('nosign')->destructive()->press("block('42')"),
    ]]);

    expect($tree['type'])->toBe('pressable')
        ->and($tree['props']['has_context_menu'])->toBeTrue()
        ->and($tree['props'])->not->toHaveKey('has_menu')
        ->and($registry->resolve($tree['on_press']))->toBe(['method' => 'open', 'args' => ['42']]);

    $content = array_values(array_filter($tree['children'], fn (array $child): bool => $child['type'] !== 'top_bar_action'));
    [$pin, $divider, $block] = array_values(array_filter($tree['children'], fn (array $child): bool => $child['type'] === 'top_bar_action'));

    expect(array_column($content, 'type'))->toBe(['text'])
        ->and($pin['props'])->toMatchArray(['id' => 'pin', 'label' => 'Pin', 'icon' => 'pin'])
        ->and($registry->resolve($pin['on_press']))->toBe(['method' => 'pin', 'args' => ['42']])
        ->and($divider['props']['divider'])->toBeTrue()
        ->and($block['props'])->toMatchArray(['label' => 'Block @ada', 'destructive' => true])
        ->and($registry->resolve($block['on_press']))->toBe(['method' => 'block', 'args' => ['42']]);
});

it('still opens a :menu on a tap', function () {
    [$tree] = renderPressable('tap-menu', <<<'BLADE'
        <native:pressable :menu="$items"><native:text>More</native:text></native:pressable>
        BLADE, ['items' => [NavAction::make('mute')->label('Mute')->press('mute')]]);

    expect($tree['props']['has_menu'])->toBeTrue()
        ->and($tree['props'])->not->toHaveKey('has_context_menu')
        ->and(array_column(array_column($tree['children'], 'props'), 'label'))->toBe(['Mute']);
});

it('takes the camelCase spelling and pre-built top-bar actions', function () {
    $pressable = Pressable::make(Text::make('Row'));
    $pressable->applyAttributes(['contextMenu' => [NavAction::make('stash')->label('Stash')->toElement()]]);

    $tree = $pressable->toArray(new CallbackRegistry);

    expect($tree['props']['has_context_menu'])->toBeTrue()
        ->and($tree['children'][1]['props']['label'])->toBe('Stash');
});

it('leaves a pressable with an empty context menu as a plain pressable', function () {
    [$tree] = renderPressable('empty-context-menu', <<<'BLADE'
        <native:pressable @press="open" :context-menu="[]"><native:text>Row</native:text></native:pressable>
        BLADE);

    expect($tree['props'] ?? [])->not->toHaveKey('has_context_menu')
        ->and($tree['children'])->toHaveCount(1);
});

it('refuses a pressable with both a :menu and a :context-menu', function () {
    $pressable = Pressable::make();

    $pressable->applyAttributes([
        'menu' => [NavAction::make('a')->label('A')],
        'context-menu' => [NavAction::make('b')->label('B')],
    ]);
})->throws(InvalidArgumentException::class, 'not both');
