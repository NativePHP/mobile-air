<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\Pressable;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Testing\Native;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Edge\ChromeColumnLayout;
use Tests\Fixtures\Edge\ChromeTabsLayout;
use Tests\Fixtures\Edge\InlineTopBarMenuScreen;
use Tests\Fixtures\Edge\NavMenuScreen;

/**
 * Richer menus on top-bar actions: subtitles, checkmarks, titled sections,
 * the compact header row and submenus. Sections and rows are sentinel
 * actions (like dividers) whose children are the items; a submenu is an
 * item with items of its own. Unset props stay off the wire.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

function topBarMenuProps(NavAction|TopBarAction $action): array
{
    $element = $action instanceof NavAction ? $action->toElement() : $action;

    return $element->toArray(new CallbackRegistry)['props'];
}

function topBarMenuNode(NavAction $action): array
{
    return $action->toElement()->toArray(new CallbackRegistry);
}

// ── TopBarAction attributes (Blade path) ────────────

it('serializes subtitle on a top-bar action', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'sort', 'label' => 'Sort', 'subtitle' => 'Date']);

    expect(topBarMenuProps($action)['subtitle'])->toBe('Date');
});

it('serializes checked, section and row as booleans', function (string $key) {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'x', $key => '1']);

    expect(topBarMenuProps($action)[$key])->toBeTrue();
})->with(['checked', 'section', 'row']);

it('keeps an explicit false for checked, section and row', function (string $key) {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'x', $key => false]);

    expect(topBarMenuProps($action)[$key])->toBeFalse();
})->with(['checked', 'section', 'row']);

it('omits the menu props when the attributes are not set', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'mute', 'label' => 'Mute', 'icon' => 'bell']);

    expect(topBarMenuProps($action))->not->toHaveKeys(['subtitle', 'checked', 'section', 'row']);
});

// ── NavAction item props ────────────────────────────

it('builds subtitle and checked through the NavAction fluent API', function () {
    $props = topBarMenuProps(NavAction::make('sort_date')->label('Date')->subtitle('Newest first')->checked()->press('sort'));

    expect($props['subtitle'])->toBe('Newest first')
        ->and($props['checked'])->toBeTrue()
        ->and($props['label'])->toBe('Date');
});

it('leaves NavAction checked(false) and an unset subtitle out of the wire props', function () {
    $props = topBarMenuProps(NavAction::make('sort_title')->label('Title')->checked(false)->press('sort'));

    expect($props)->not->toHaveKeys(['checked', 'subtitle']);
});

it('omits section and row from a plain action', function () {
    expect(topBarMenuProps(NavAction::make('mute')->label('Mute')->press('mute')))
        ->not->toHaveKeys(['section', 'row', 'divider']);
});

// ── Sections ────────────────────────────────────────

it('builds a titled section with its items as children', function () {
    $node = topBarMenuNode(NavAction::section('Display', [
        NavAction::make('gallery')->label('Gallery')->press('gallery'),
        NavAction::make('list')->label('List')->press('list'),
    ]));

    expect($node['type'])->toBe('top_bar_action')
        ->and($node['props'])->toBe(['id' => 'section', 'label' => 'Display', 'section' => true])
        ->and(array_column(array_column($node['children'], 'props'), 'id'))->toBe(['gallery', 'list']);
});

it('builds an untitled section from named items', function () {
    $node = topBarMenuNode(NavAction::section(items: [
        NavAction::make('delete')->label('Delete')->destructive()->press('delete'),
    ]));

    expect($node['props'])->toBe(['id' => 'section', 'section' => true])
        ->and($node['children'])->toHaveCount(1)
        ->and($node['children'][0]['props']['destructive'])->toBeTrue();
});

it('accepts a section\'s items through items() as well', function () {
    $node = topBarMenuNode(NavAction::section('Sort by')->items([
        NavAction::make('sort_date')->label('Date')->press('sort'),
    ]));

    expect($node['props']['section'])->toBeTrue()
        ->and($node['children'][0]['props']['id'])->toBe('sort_date');
});

it('strips item props and callbacks from a section', function () {
    $node = topBarMenuNode(
        NavAction::section('Display')->icon('star')->subtitle('x')->checked()->destructive()->disabled()->press('nope')
    );

    expect($node['props'])->toBe(['id' => 'section', 'label' => 'Display', 'section' => true])
        ->and($node['on_press'] ?? 0)->toBe(0);
});

// ── Rows ────────────────────────────────────────────

it('builds a header row with its items as children', function () {
    $node = topBarMenuNode(NavAction::row([
        NavAction::make('scan')->label('Scan')->icon('doc.viewfinder')->press('scan'),
        NavAction::make('pin')->label('Pin')->icon('pin')->press('pin'),
        NavAction::make('lock')->label('Lock')->icon('lock')->press('lock'),
    ]));

    expect($node['props'])->toBe(['id' => 'row', 'row' => true])
        ->and(array_column(array_column($node['children'], 'props'), 'label'))->toBe(['Scan', 'Pin', 'Lock']);
});

it('strips item props from a row, including a label', function () {
    $node = topBarMenuNode(NavAction::row([])->label('Ignored')->icon('star')->checked());

    expect($node['props'])->toBe(['id' => 'row', 'row' => true]);
});

// ── Sentinel ids ────────────────────────────────────

it('gives dividers, sections and rows sentinel ids', function () {
    expect(topBarMenuProps(NavAction::divider())['id'])->toBe('divider')
        ->and(topBarMenuProps(NavAction::section('Display'))['id'])->toBe('section')
        ->and(topBarMenuProps(NavAction::section())['id'])->toBe('section')
        ->and(topBarMenuProps(NavAction::row([]))['id'])->toBe('row');
});

// ── Nesting ─────────────────────────────────────────

it('nests a row inside a submenu inside a section', function () {
    $node = topBarMenuNode(NavAction::make('more')->icon('ellipsis')->a11yLabel('More')->items([
        NavAction::section('View', [
            NavAction::make('view_as')->label('View as')->subtitle('Gallery')->items([
                NavAction::row([
                    NavAction::make('zoom_in')->label('Bigger')->icon('plus')->press('zoomIn'),
                    NavAction::make('zoom_out')->label('Smaller')->icon('minus')->press('zoomOut'),
                ]),
                NavAction::make('gallery')->label('Gallery')->checked()->press('gallery'),
            ]),
        ]),
    ]));

    $section = $node['children'][0];
    $submenu = $section['children'][0];
    $row = $submenu['children'][0];
    $checked = $submenu['children'][1];

    expect($section['props'])->toMatchArray(['id' => 'section', 'section' => true, 'label' => 'View'])
        ->and($submenu['props'])->toMatchArray(['id' => 'view_as', 'label' => 'View as', 'subtitle' => 'Gallery'])
        ->and($submenu['props'])->not->toHaveKeys(['section', 'row'])
        ->and($row['props'])->toBe(['id' => 'row', 'row' => true])
        ->and(array_column(array_column($row['children'], 'props'), 'id'))->toBe(['zoom_in', 'zoom_out'])
        ->and($row['children'][0]['on_press'] ?? 0)->toBeGreaterThan(0)
        ->and($checked['props'])->toMatchArray(['id' => 'gallery', 'checked' => true]);
});

it('carries the structure into a pressable :menu', function () {
    $pressable = Pressable::make();
    $pressable->applyAttributes(['menu' => [
        NavAction::row([NavAction::make('pin')->label('Pin')->icon('pin')->press('pin')]),
        NavAction::section('Move', [NavAction::make('archive')->label('Archive')->press('archive')]),
    ]]);

    [$row, $section] = $pressable->toArray(new CallbackRegistry)['children'];

    expect($row['props']['row'])->toBeTrue()
        ->and($row['children'][0]['props']['id'])->toBe('pin')
        ->and($section['props'])->toMatchArray(['section' => true, 'label' => 'Move'])
        ->and($section['children'][0]['props']['id'])->toBe('archive');
});

// ── Blade: nested <native:top-bar-action> ───────────

it('builds sections, rows and submenus from nested Blade top-bar actions', function () {
    $tree = Native::test(InlineTopBarMenuScreen::class)->tree();

    $more = collect($tree['children'])->first(fn (array $n) => ($n['props']['id'] ?? null) === 'more');
    [$row, $view, $danger] = $more['children'];
    $submenu = $view['children'][0];

    expect($tree['type'])->toBe('native_root_stack')
        ->and($row['props'])->toMatchArray(['row' => true])
        ->and(array_column(array_column($row['children'], 'props'), 'id'))->toBe(['scan', 'pin'])
        ->and($view['props'])->toMatchArray(['section' => true, 'label' => 'View'])
        ->and($submenu['props'])->toMatchArray(['id' => 'view_as', 'subtitle' => 'Gallery'])
        ->and($submenu['children'][0]['props']['checked'])->toBeFalse()
        ->and($submenu['children'][1]['props']['checked'])->toBeTrue()
        ->and($danger['props'])->toMatchArray(['section' => true])
        ->and($danger['props'])->not->toHaveKey('label')
        ->and($danger['children'][0]['props'])->toMatchArray(['id' => 'delete', 'destructive' => true]);
});

it('wires callbacks on Blade items nested in sections and submenus', function () {
    Native::test(InlineTopBarMenuScreen::class)
        ->tap('Gallery')
        ->assertSet('display', 'gallery')
        ->assertElement('top_bar_action', fn (array $n) => ($n['props']['id'] ?? null) === 'gallery'
            && ($n['props']['checked'] ?? null) === true)
        ->tap('Delete')
        ->assertSet('deletes', 1);
});

// ── Testing harness ─────────────────────────────────

it('finds nav actions inside sections, rows and submenus', function (string $layout) {
    Native::test(NavMenuScreen::class, layout: $layout)
        ->assertNavAction('more')
        ->assertNavAction('scan')
        ->assertNavAction('lock')
        ->assertNavAction('view_as')
        ->assertNavAction('zoom_in')
        ->assertNavAction('gallery')
        ->assertNavAction('delete')
        ->assertMissingNavAction('section')
        ->assertMissingNavAction('row');
})->with([ChromeTabsLayout::class, ChromeColumnLayout::class]);

it('finds Blade nav actions inside sections, rows and submenus', function () {
    Native::test(InlineTopBarMenuScreen::class)
        ->assertNavAction('scan')
        ->assertNavAction('view_as')
        ->assertNavAction('list')
        ->assertNavAction('delete');
});

it('leaves section and row sentinels out of the actions it lists', function () {
    Native::test(NavMenuScreen::class, layout: ChromeTabsLayout::class)
        ->assertNavAction('nope');
})->throws(
    AssertionFailedError::class,
    'Nav action [nope] not found. Actions present: more, scan, pin, lock, view_as, zoom_in, zoom_out, gallery, list, delete'
);

it('does not flag sections and rows as unlabeled icon-only actions', function () {
    Native::test(NavMenuScreen::class, layout: ChromeTabsLayout::class)->assertAccessible();
    Native::test(InlineTopBarMenuScreen::class)->assertAccessible();
});

it('tracks checked state from the screen', function () {
    Native::test(NavMenuScreen::class, layout: ChromeTabsLayout::class)
        ->assertElement('top_bar_action', fn (array $n) => ($n['props']['id'] ?? null) === 'list'
            && ($n['props']['checked'] ?? false) === true)
        ->assertMissingElement('top_bar_action', fn (array $n) => ($n['props']['id'] ?? null) === 'gallery'
            && isset($n['props']['checked']))
        ->tap('Gallery')
        ->assertSet('display', 'gallery')
        ->assertElement('top_bar_action', fn (array $n) => ($n['props']['id'] ?? null) === 'gallery'
            && ($n['props']['checked'] ?? false) === true);
});
