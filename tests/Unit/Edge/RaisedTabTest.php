<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\BottomNavItem;
use Native\Mobile\Edge\Layouts\Builders\RaisedTab;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Edge\ChromeScreen;
use Tests\Fixtures\Edge\InlineRaisedBottomNavScreen;
use Tests\Fixtures\Edge\RaisedColumnLayout;
use Tests\Fixtures\Edge\RaisedTabsLayout;

/**
 * Raised tabs — a tab of the native tab bar lifted into a floating disc.
 * The style rides on the tab's own `bottom_nav_item` as `raised` +
 * `raised_*` props; the native tab renderers draw the disc over that
 * item's slot and route its taps through the item.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

function raisedTabProps(Tab $tab): array
{
    return $tab->toElement()->toArray(new CallbackRegistry)['props'];
}

function raisedTabItem(TestableComponent $screen, string $label): array
{
    $found = null;
    $walk = function (array $node) use (&$walk, &$found, $label) {
        if (($node['type'] ?? null) === 'bottom_nav_item' && ($node['props']['label'] ?? null) === $label) {
            $found = $node;
        }
        foreach ($node['children'] ?? [] as $child) {
            $walk($child);
        }
    };
    $walk($screen->tree());

    return $found;
}

// ── Builder serialization ───────────────────────────

it('serializes a bare raised tab with the default disc', function () {
    $props = raisedTabProps(Tab::link('Create', '/create', icon: 'add')->raised());

    expect($props)->toMatchArray([
        'id' => 'create',
        'icon' => 'add',
        'raised' => true,
        'raised_size' => 52,
        'raised_lift' => 20,
        'raised_icon_color' => '#FFFFFF',
        'raised_icon_size' => 26,
        'raised_shadow' => true,
        'raised_shadow_elevation' => 8,
        'raised_press_scale' => 0.94,
        'raised_dock_with_keyboard' => true,
    ]);

    // The disc uses the tab's own icon, and the fill falls back to the
    // bar's active colour natively, so neither is sent.
    expect($props)->not->toHaveKeys(['raised_icon', 'raised_color', 'raised_gradient_from', 'raised_ring_color', 'raised_halo_color', 'raised_shadow_color']);
});

it('leaves a flat tab without any raised props', function () {
    $props = raisedTabProps(Tab::link('Home', '/', icon: 'home'));

    expect(array_filter(array_keys($props), fn ($key) => str_starts_with($key, 'raised')))->toBe([]);
});

it('serializes a fully styled disc', function () {
    $props = raisedTabProps(Tab::link('Create', '/create', icon: 'add')->raised(
        RaisedTab::make()
            ->size(60)
            ->lift(24)
            ->gradient('#3FBFA0', '#17977F', 150)
            ->icon('edit')
            ->iconColor('#0F172A')
            ->iconSize(28)
            ->ring('white', 4)
            ->activeHalo('#1D9C8447', 2)
            ->shadow('#000000', 12)
            ->pressScale(0.9)
            ->dockWithKeyboard(false)
    ));

    expect($props)->toMatchArray([
        'icon' => 'add',
        'raised' => true,
        'raised_size' => 60,
        'raised_lift' => 24,
        'raised_gradient_from' => '#3FBFA0',
        'raised_gradient_to' => '#17977F',
        'raised_gradient_angle' => 150,
        'raised_icon' => 'edit',
        'raised_icon_color' => '#0F172A',
        'raised_icon_size' => 28,
        'raised_ring_color' => '#FFFFFF',
        'raised_ring_width' => 4,
        'raised_halo_color' => '#471D9C84',
        'raised_halo_width' => 2,
        'raised_shadow_color' => '#000000',
        'raised_shadow_elevation' => 12,
        'raised_press_scale' => 0.9,
        'raised_dock_with_keyboard' => false,
    ]);
});

it('lets the last of color() and gradient() win', function () {
    $solid = RaisedTab::make()->gradient('#000', '#FFF')->color('#FF0000')->toProps();
    expect($solid['raised_color'])->toBe('#FF0000')
        ->and($solid)->not->toHaveKey('raised_gradient_from');

    $gradient = RaisedTab::make()->color('#FF0000')->gradient('#000', '#FFF', -90)->toProps();
    expect($gradient)->not->toHaveKey('raised_color')
        ->and($gradient['raised_gradient_angle'])->toBe(270);
});

it('turns the shadow off', function () {
    expect(RaisedTab::make()->withoutShadow()->toProps()['raised_shadow'])->toBeFalse();
});

it('drops a zero-width ring or halo', function () {
    $props = RaisedTab::make()->ring('white', 0)->activeHalo('white', 0)->toProps();

    expect($props)->not->toHaveKeys(['raised_ring_color', 'raised_halo_color']);
});

it('lowers a tab raised earlier in the chain', function () {
    $tab = Tab::link('Create', '/create')->raised()->raised(false);

    expect($tab->isRaised())->toBeFalse()
        ->and(raisedTabProps($tab))->not->toHaveKey('raised');
});

it('resolves the disc icon per platform', function (?string $platform, ?string $icon) {
    $props = raisedTabItem(Native::test(ChromeScreen::class, layout: RaisedTabsLayout::class, platform: $platform), 'Create')['props'];

    expect($props['raised_icon'] ?? null)->toBe($icon)
        // The tab's own icon is untouched: the disc icon is separate.
        ->and($props['icon'])->toBe('add');
})->with([
    'ios' => ['ios', 'plus'],
    'android' => ['android', 'add'],
    // No shared name and no platform: no disc icon, so the renderers fall
    // back to the tab's own icon.
    'unknown platform' => [null, null],
]);

// ── Colours ─────────────────────────────────────────

it('normalizes the element colour grammar to wire hex', function (string $input, string $wire) {
    expect(RaisedTab::make()->color($input)->toProps()['raised_color'])->toBe($wire);
})->with([
    '#RGB' => ['#f0a', '#FF00AA'],
    '#RRGGBB' => ['#3fbfa0', '#3FBFA0'],
    '#RRGGBBAA is CSS order' => ['#3FBFA080', '#803FBFA0'],
    'palette name' => ['teal-500', '#14B8A6'],
    'palette name with opacity' => ['teal-500/50', '#8014B8A6'],
    'white' => ['white', '#FFFFFF'],
    'transparent' => ['transparent', '#00000000'],
]);

it('resolves theme tokens to their light value, as gradient stops do', function () {
    TailwindParser::setThemeResolver(fn (string $token) => $token === 'primary' ? '#1D9C84' : null);

    try {
        expect(RaisedTab::make()->color('theme-primary')->toProps()['raised_color'])->toBe('#1D9C84')
            ->and(fn () => RaisedTab::make()->color('theme-missing'))->toThrow(InvalidArgumentException::class, 'unsupported colour');
    } finally {
        TailwindParser::setThemeResolver(null);
    }
});

it('rejects colours it cannot draw', function (string $input) {
    RaisedTab::make()->color($input);
})->with([
    'named colour' => ['rebeccapurple'],
    'hsl()' => ['hsl(10, 50%, 50%)'],
    'bad hex' => ['#12345'],
    'rgb()' => ['rgb(29, 156, 132)'],
    'rgba()' => ['rgba(0, 0, 0, 0.5)'],
])->throws(InvalidArgumentException::class);

it('validates every colour option', function (Closure $call) {
    $call(RaisedTab::make());
})->with([
    'gradient' => [fn (RaisedTab $r) => $r->gradient('nope', '#000')],
    'iconColor' => [fn (RaisedTab $r) => $r->iconColor('nope')],
    'ring' => [fn (RaisedTab $r) => $r->ring('nope')],
    'activeHalo' => [fn (RaisedTab $r) => $r->activeHalo('nope')],
    'shadow' => [fn (RaisedTab $r) => $r->shadow('nope')],
])->throws(InvalidArgumentException::class, 'unsupported colour');

// ── Ranges ──────────────────────────────────────────

it('range-checks sizes when they are set', function (Closure $call, string $message) {
    expect(fn () => $call(RaisedTab::make()))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'size too small' => [fn (RaisedTab $r) => $r->size(23), 'size must be between 24 and 120, got 23'],
    'size too large' => [fn (RaisedTab $r) => $r->size(121), 'size must be between 24 and 120'],
    'negative lift' => [fn (RaisedTab $r) => $r->lift(-1), 'lift must be between 0 and 80'],
    'lift too large' => [fn (RaisedTab $r) => $r->lift(81), 'lift must be between 0 and 80'],
    'icon size' => [fn (RaisedTab $r) => $r->iconSize(65), 'iconSize must be between 12 and 64'],
    'ring width' => [fn (RaisedTab $r) => $r->ring('white', 17), 'ring width must be between 0 and 16'],
    'halo width' => [fn (RaisedTab $r) => $r->activeHalo('white', -1), 'halo width must be between 0 and 16'],
    'shadow elevation' => [fn (RaisedTab $r) => $r->shadow(null, 33), 'shadow elevation must be between 0 and 32'],
    'press scale low' => [fn (RaisedTab $r) => $r->pressScale(0.49), 'pressScale must be between 0.5 and 1.0'],
    'press scale high' => [fn (RaisedTab $r) => $r->pressScale(1.01), 'pressScale must be between 0.5 and 1.0'],
]);

it('accepts the range bounds', function () {
    $props = RaisedTab::make()->size(24)->lift(0)->iconSize(64)->ring('white', 16)->pressScale(1.0)->toProps();

    expect($props)->toMatchArray([
        'raised_size' => 24,
        'raised_lift' => 0,
        'raised_icon_size' => 64,
        'raised_ring_width' => 16,
        'raised_press_scale' => 1.0,
    ]);
});

it('refuses to raise a search tab', function () {
    Tab::search('Search', icon: 'search')->raised();
})->throws(InvalidArgumentException::class, "is a search tab, which can't be raised");

// ── Blade attributes ────────────────────────────────

it('raises an inline bottom-nav item from its attributes', function () {
    $item = BottomNavItem::make();
    $item->applyAttributes([
        'id' => 'create',
        'label' => 'Create',
        'icon' => 'add',
        'raised' => true,
        'raised-size' => '56',
        'raised-lift' => '18',
        'raised-gradient-from' => '#3FBFA0',
        'raised-gradient-to' => '#17977F',
        'raised-gradient-angle' => '90',
        'raised-icon' => 'edit',
        'raised-icon-color' => 'black',
        'raised-ring-color' => 'white',
        'raised-halo-color' => 'teal-500/30',
        'raised-halo-width' => '2',
        'raised-shadow-color' => '#000',
        'raised-shadow-elevation' => '4',
        'raised-press-scale' => '0.9',
        'raised-dock-with-keyboard' => 'false',
    ]);

    expect($item->toArray(new CallbackRegistry)['props'])->toMatchArray([
        'raised' => true,
        'raised_size' => 56,
        'raised_lift' => 18,
        'raised_gradient_from' => '#3FBFA0',
        'raised_gradient_to' => '#17977F',
        'raised_gradient_angle' => 90,
        'raised_icon' => 'edit',
        'raised_icon_color' => '#000000',
        'raised_ring_color' => '#FFFFFF',
        'raised_ring_width' => 3,
        'raised_halo_color' => '#4D14B8A6',
        'raised_halo_width' => 2,
        'raised_shadow_color' => '#000000',
        'raised_shadow_elevation' => 4,
        'raised_press_scale' => 0.9,
        'raised_dock_with_keyboard' => false,
    ]);
});

it('treats style attributes as raising the item unless raised is false', function (array $attrs, bool $raised) {
    $item = BottomNavItem::make();
    $item->applyAttributes(['id' => 'create', 'label' => 'Create', ...$attrs]);

    expect($item->isRaised())->toBe($raised)
        ->and($item->toArray(new CallbackRegistry)['props']['raised'] ?? false)->toBe($raised);
})->with([
    'bare raised' => [['raised' => true], true],
    'style only' => [['raised-color' => 'teal-500'], true],
    'raised="false"' => [['raised' => 'false', 'raised-color' => 'teal-500'], false],
    'bound false' => [['raised' => false], false],
    'no raised attributes' => [[], false],
]);

it('shows the blade attribute name when a value is not a number', function () {
    BottomNavItem::make()->applyAttributes(['raised-size' => 'big']);
})->throws(InvalidArgumentException::class, 'raised-size must be a whole number, got [big]');

it('rejects unknown raised attributes instead of raising with a typo ignored', function () {
    BottomNavItem::make()->applyAttributes(['id' => 'create', 'raised-colour' => 'teal-500']);
})->throws(InvalidArgumentException::class, 'Unknown raised tab attribute [raised-colour]');

it('rejects a width or angle without the colour it applies to', function (string $attribute, string $message) {
    expect(fn () => BottomNavItem::make()->applyAttributes([$attribute => '4']))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'gradient angle' => ['raised-gradient-angle', 'raised-gradient-angle needs raised-gradient-from'],
    'ring width' => ['raised-ring-width', 'raised-ring-width needs raised-ring-color'],
    'halo width' => ['raised-halo-width', 'raised-halo-width needs raised-halo-color'],
]);

it('needs both ends of an attribute gradient', function () {
    BottomNavItem::make()->applyAttributes(['raised-gradient-to' => '#000']);
})->throws(InvalidArgumentException::class, 'needs both raised-gradient-from and raised-gradient-to');

it('refuses a raised search item in blade', function () {
    BottomNavItem::make()->applyAttributes(['id' => 'search', 'search' => true, 'raised' => true]);
})->throws(InvalidArgumentException::class, "can't be raised");

// ── Through the chrome wrapper ──────────────────────

it('rides the raised style on the tab item of the native tab root', function () {
    $screen = Native::test(ChromeScreen::class, layout: RaisedTabsLayout::class)
        ->assertHasTabBar()
        ->assertTabRaised('Create')
        ->assertTabNotRaised('Home')
        ->assertTabNotRaised('Inbox');

    expect(raisedTabItem($screen, 'Create')['props'])->toMatchArray([
        'id' => 'create',
        'url' => '/create',
        'icon' => 'add',
        'raised' => true,
        'raised_gradient_from' => '#3FBFA0',
        'raised_gradient_to' => '#17977F',
        'raised_gradient_angle' => 150,
        'raised_ring_color' => '#FFFFFF',
        'raised_ring_width' => 4,
    ]);
});

it('keeps the raised tab a real tab with its own navigation', function () {
    $screen = Native::test(ChromeScreen::class, layout: RaisedTabsLayout::class);

    // The disc fires the item's own press; the item keeps the replace
    // navigation core wires for every link tab.
    expect(raisedTabItem($screen, 'Create'))->toHaveKey('on_press');
});

it('raises an inline bottom-nav item and keeps its tap handler', function () {
    $screen = Native::test(InlineRaisedBottomNavScreen::class)
        ->assertHasTabBar()
        ->assertTabRaised('Create')
        ->assertTabNotRaised('Home');

    expect(raisedTabItem($screen, 'Create')['props'])->toMatchArray([
        'raised_size' => 56,
        'raised_color' => '#14B8A6',
        'raised_ring_color' => '#DBFFFFFF',
        'raised_ring_width' => 4,
        'raised_dock_with_keyboard' => false,
    ]);

    expect(raisedTabItem($screen, 'Create'))->toHaveKey('on_press');
    $screen->press('create')->assertSet('creates', 1);

    $screen->set('canCreate', false)->assertTabNotRaised('Create');
});

it('fails assertTabRaised on a flat tab', function () {
    Native::test(ChromeScreen::class, layout: RaisedTabsLayout::class)->assertTabRaised('Home');
})->throws(AssertionFailedError::class, 'Tab [Home] exists but is not raised.');

it('fails assertTabNotRaised on a raised tab', function () {
    Native::test(ChromeScreen::class, layout: RaisedTabsLayout::class)->assertTabNotRaised('Create');
})->throws(AssertionFailedError::class, 'Tab [Create] is raised.');

it('fails assertTabRaised when no native tab chrome draws the disc', function () {
    Native::test(ChromeScreen::class, layout: RaisedColumnLayout::class)->assertTabRaised('Create');
})->throws(AssertionFailedError::class, 'only native tab chrome draws a raised tab');
