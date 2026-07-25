<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\BottomNav;
use Native\Mobile\Edge\Elements\BottomNavItem;
use Native\Mobile\Edge\Elements\TopBar;
use Native\Mobile\Edge\Elements\TopBarAction;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ChromeTabsLayout;
use Tests\Fixtures\Edge\CustomTopBarScreen;
use Tests\Fixtures\Edge\FabScreen;
use Tests\Fixtures\Edge\InlineBottomNavScreen;
use Tests\Fixtures\Edge\InlineTopBarScreen;
use Tests\Fixtures\Edge\ScrollFabScreen;
use Tests\Fixtures\Edge\ScrollNoFabScreen;
use Tests\Fixtures\Edge\ScrollRootFabScreen;

/**
 * Composable blade chrome — inline `<native:top-bar>` / `<native:bottom-nav>`
 * elements hoisted out of the screen tree and fed through the SAME
 * native-chrome sentinels (NativeRootStack / NativeRootTabs) the layout
 * builders produce, making the documented three-tier contract real:
 *
 *   1. no inline bar        → the layout's builder bar
 *   2. inline bar           → wins that slot (other slot still layout-fed)
 *   3. inline bar + `custom` → stays in-tree as a drawn element, and still
 *                              suppresses the layout's slot
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
});

// ── Builder reconstruction (fromElement) ────────────

it('reconstructs a NavBar from a collected top_bar element', function () {
    $el = TopBar::make();
    $el->applyAttributes([
        'title' => 'Inline',
        'subtitle' => 'Sub',
        'back' => true,
        'background-color' => '#112233',
        'text-color' => '#FFFFFF',
        'font-name' => 'Inter-Bold',
        'display-mode' => 'large',
        'scroll-behavior' => 'pinned',
        'elevation' => 3,
    ]);

    $props = NavBar::fromElement($el)->toRootProps();

    expect($props)->toMatchArray([
        'title' => 'Inline',
        'subtitle' => 'Sub',
        'back' => true,
        'backgroundColor' => '#112233',
        'textColor' => '#FFFFFF',
        'fontName' => 'Inter-Bold',
        'displayMode' => 'large',
        'scrollBehavior' => 'pinned',
        'elevation' => 3,
    ]);
});

it('adopts top_bar_action children as prebuilt action elements', function () {
    $action = TopBarAction::make();
    $action->applyAttributes(['id' => 'save', 'icon' => 'check']);
    $action->onPress('save');

    $el = TopBar::make();
    $el->applyAttributes(['title' => 'X']);
    $el->addChild($action);

    $elements = NavBar::fromElement($el)->actionElements();

    // The very same instance — collector-wired callbacks survive.
    expect($elements)->toHaveCount(1)
        ->and($elements[0])->toBe($action);
});

it('reconstructs a TabBar from a collected bottom_nav element', function () {
    $el = BottomNav::make();
    $el->applyAttributes([
        'dark' => true,
        'active-color' => '#FF0000',
        'label-visibility' => 'unlabeled',
        'font-name' => 'Inter-Bold',
    ]);

    $props = TabBar::fromElement($el)->toRootProps();

    expect($props)->toMatchArray([
        'dark' => true,
        'activeColor' => '#FF0000',
        'labelVisibility' => 'unlabeled',
        'fontName' => 'Inter-Bold',
    ]);
});

it('auto-highlights prebuilt tab items only when none is explicitly active', function () {
    $make = function (bool $withExplicitActive) {
        $nav = BottomNav::make();
        $nav->applyAttributes([]);
        foreach ([['home', '/'], ['settings', '/settings']] as [$id, $url]) {
            $item = BottomNavItem::make();
            $item->applyAttributes(['id' => $id, 'label' => ucfirst($id), 'url' => $url]);
            $nav->addChild($item);
        }
        if ($withExplicitActive) {
            $nav->getChildren()[1]->setActive(true);
        }

        return TabBar::fromElement($nav);
    };

    // No explicit active → longest-prefix URL match wins.
    $auto = $make(false)->highlight('/settings');
    [$home, $settings] = $auto->tabElements();
    expect($home->isActive())->toBeFalse()
        ->and($settings->isActive())->toBeTrue();

    // Explicit active in the blade → highlight() leaves it alone.
    $explicit = $make(true)->highlight('/');
    [$home, $settings] = $explicit->tabElements();
    expect($home->isActive())->toBeFalse()
        ->and($settings->isActive())->toBeTrue();
});

it('mixes builder tabs and prebuilt items in highlight and tabElements', function () {
    $nav = BottomNav::make();
    $nav->applyAttributes([]);
    $item = BottomNavItem::make();
    $item->applyAttributes(['id' => 'inline', 'label' => 'Inline', 'url' => '/inline']);
    $nav->addChild($item);

    $bar = TabBar::fromElement($nav);
    $bar->add(Tab::link('Built', '/built'));
    $bar->highlight('/built');

    $elements = $bar->tabElements();
    expect($elements)->toHaveCount(2);
    // Builder tabs come first, prebuilt after.
    expect($elements[0]->toArray(new CallbackRegistry)['props']['active'] ?? false)->toBeTrue()
        ->and($elements[1]->isActive())->toBeFalse();
});

// ── Hoisting: inline chrome without a layout ────────

it('hoists an inline top-bar into a NativeRootStack with no layout at all', function () {
    Native::test(InlineTopBarScreen::class)
        ->assertNavTitle('Inline Title')
        ->assertElement('native_root_stack', function (array $n) {
            return ($n['props']['subtitle'] ?? null) === 'From Blade'
                && ($n['props']['background_color'] ?? null) === '#112233'
                && ($n['props']['display_mode'] ?? null) === 'large'
                && ($n['props']['back'] ?? null) === true;
        })
        // The bar was hoisted — it must not also render as a drawn element.
        ->assertMissingElement('top_bar')
        ->assertSee('Top bar body');
});

it('keeps inline top-bar actions (and their callbacks) on the chrome root', function () {
    $screen = Native::test(InlineTopBarScreen::class)
        ->assertElement('top_bar_action', function (array $n) {
            return ($n['props']['id'] ?? null) === 'save'
                && isset($n['on_press']);
        });

    $screen->tap('Save')->assertSet('saves', 1);
});

it('hoists an inline bottom-nav into a NativeRootTabs with no layout at all', function () {
    Native::test(InlineBottomNavScreen::class)
        ->assertHasTabBar()
        ->assertHasTab('Home')
        ->assertHasTab('Settings')
        ->assertTabActive('Home')          // explicit `active` attribute respected
        ->assertMissingElement('bottom_nav')
        ->assertElement('native_root_tabs', fn (array $n) => ($n['props']['active_color'] ?? null) === '#FF0000')
        ->assertSee('Bottom nav body');
});

// ── Three-tier contract with a layout ───────────────

it('lets an inline top-bar win its slot while the layout still supplies the tab bar', function () {
    Native::test(InlineTopBarScreen::class, layout: ChromeTabsLayout::class)
        ->assertHasTabBar()               // layout's slot survives
        ->assertHasTab('Home')
        ->assertHasTab('Detail')
        ->assertNavTitle('Inline Title')  // inline bar wins the nav slot
        ->assertElement('native_root_tabs', function (array $n) {
            // The layout's navBar (title "Layout Title Should Lose") was suppressed.
            return ($n['props']['nav_title'] ?? null) === 'Inline Title';
        });
});

it('lets an inline bottom-nav win its slot while the layout still supplies the nav bar', function () {
    Native::test(InlineBottomNavScreen::class, layout: ChromeTabsLayout::class)
        ->assertHasTabBar()
        ->assertHasTab('Settings')        // inline tabs
        ->assertMissingElement('bottom_nav_item', fn (array $n) => ($n['props']['label'] ?? null) === 'Detail')
        ->assertNavTitle('Bottom Screen'); // layout navBar survives for the other slot
});

it('leaves a custom top-bar in the tree and suppresses the layout nav slot', function () {
    Native::test(CustomTopBarScreen::class, layout: ChromeTabsLayout::class)
        ->assertHasTabBar()               // layout tab bar still renders
        ->assertElement('top_bar', fn (array $n) => ($n['props']['title'] ?? null) === 'Drawn Bar')
        ->assertElement('native_root_tabs', fn (array $n) => ! isset($n['props']['nav_title']))
        ->assertSee('Custom bar body');
});

it('renders a custom top-bar as a plain drawn element when there is no layout', function () {
    Native::test(CustomTopBarScreen::class)
        ->assertElement('top_bar')
        ->assertMissingElement('native_root_stack')
        ->assertMissingElement('native_root_tabs');
});

// ── Fab ─────────────────────────────────────────────

it('floats a top-level fab as the last absolute child of a full-size flex root', function () {
    Native::test(FabScreen::class)
        // No Stack wrapper for a fill-sized flex root — the fab overlays
        // via absolute positioning so the content tree keeps the exact
        // shape (and scroll geometry) it has without a fab.
        ->assertMissingElement('stack')
        // The fab IS a pressable on the wire: absolutely positioned,
        // circular, elevated, with the icon as a child.
        ->assertElement('pressable', function (array $n) {
            return ($n['layout']['position_type'] ?? null) === 1
                && ($n['style']['border_radius'] ?? null) == 28.0
                && ($n['style']['elevation'] ?? null) == 6.0
                && collect($n['children'] ?? [])->contains(fn ($c) => ($c['type'] ?? null) === 'icon')
                && isset($n['on_press']);
        })
        // Draw order = z order: the fab must be the LAST child of the
        // content root so it renders above its siblings.
        ->assertElement('column', function (array $n) {
            $last = collect($n['children'] ?? [])->last();

            return ($n['layout']['width'] ?? null) === 'fill'
                && ($n['layout']['height'] ?? null) === 'fill'
                && ($last['type'] ?? null) === 'pressable'
                && ($last['layout']['position_type'] ?? null) === 1;
        })
        ->assertMissingElement('fab')
        ->assertSee('Fab body');
});

it('fires the fab tap handler', function () {
    Native::test(FabScreen::class)
        ->tap('create-fab')
        ->assertSet('created', 1);
});

// Scroll regression: a top-bar + full-size scroll-view + fab screen used
// to get its content wrapped in a Stack overlay. The iOS stack layout
// measures non-scroll children with an `.unspecified` proposal, so the
// scroll_view one level down (stack → column → scroll_view) was sized to
// its intrinsic CONTENT height instead of the viewport — the scrollable
// range collapsed and the list rubber-banded ("elastic" scroll, bottom
// unreachable). The content tree must stay byte-identical to the no-fab
// tree, with the fab riding along as a trailing absolute overlay child.
it('keeps the scroll-view tree identical to the no-fab tree when a fab is hoisted', function () {
    $withFab = Native::test(ScrollFabScreen::class)->tree();
    $without = Native::test(ScrollNoFabScreen::class)->tree();

    // No Stack wrapper anywhere.
    Native::test(ScrollFabScreen::class)->assertMissingElement('stack');

    // Same chrome root; the content child is the implicit fill Column in
    // both trees.
    $fabContent = collect($withFab['children'])->firstWhere('type', 'column');
    $plainContent = collect($without['children'])->firstWhere('type', 'column');
    expect($fabContent)->not->toBeNull();
    expect($plainContent)->not->toBeNull();

    // The fab is the LAST child of the content root (absolute overlay,
    // drawn on top); every child before it matches the no-fab tree
    // exactly — same types, same ids, same layout — so the native
    // renderers measure the scroll viewport identically with or without
    // the fab.
    $fabChildren = $fabContent['children'];
    $fab = array_pop($fabChildren);
    expect($fab['type'])->toBe('pressable');
    expect($fab['layout']['position_type'] ?? null)->toBe(1);
    expect($fabChildren)->toEqual($plainContent['children']);

    // And the scroll-view itself sits DIRECTLY under the content root,
    // still declaring full size.
    $scroll = collect($fabChildren)->firstWhere('type', 'scroll_view');
    expect($scroll)->not->toBeNull();
    expect($scroll['layout']['width'] ?? null)->toBe('fill');
    expect($scroll['layout']['height'] ?? null)->toBe('fill');
});

it('adds new scroll children without disturbing the hoisted fab overlay', function () {
    // The reporter hit the bug "when adding more tasks" — assert the
    // grown tree keeps the same shape: still no Stack, the new card
    // rendered inside the scroll-view, fab still the trailing absolute
    // child of the content root.
    Native::test(ScrollFabScreen::class)
        ->tap('add-task')
        ->assertSet('tasks', ['One', 'Two', 'Three', 'Task 4'])
        ->assertMissingElement('stack')
        ->assertElement('scroll_view', function (array $n) {
            return collect($n['children'] ?? [])
                ->contains(fn ($c) => ($c['children'][0]['props']['text'] ?? null) === 'Task 4');
        })
        ->assertElement('column', function (array $n) {
            $last = collect($n['children'] ?? [])->last();

            return ($n['layout']['height'] ?? null) === 'fill'
                && ($last['type'] ?? null) === 'pressable'
                && ($last['layout']['position_type'] ?? null) === 1;
        });
});

it('still wraps a scroll-view ROOT in a Stack overlay, forced to fill', function () {
    // When the blade's single root IS the scroll-view (fab declared
    // inside it), the fab cannot remain a scroll child — the Stack
    // overlay is required. The scroll-view is then forced to fill the
    // stack so it keeps viewport-bounded scrolling (it would otherwise
    // be placed at its intrinsic content size).
    Native::test(ScrollRootFabScreen::class)
        ->assertElement('stack', function (array $n) {
            $types = collect($n['children'] ?? [])->pluck('type');

            return ($n['layout']['width'] ?? null) === 'fill'
                && ($n['layout']['height'] ?? null) === 'fill'
                && $types->first() === 'scroll_view'
                && $types->contains('pressable');
        })
        ->assertElement('scroll_view', function (array $n) {
            // Forced fill — mirrors the viewport proposal it received as
            // the direct chrome content before the Stack existed.
            return ($n['layout']['width'] ?? null) === 'fill'
                && ($n['layout']['height'] ?? null) === 'fill'
                // The fab must no longer be one of the scroll children.
                && collect($n['children'] ?? [])->every(
                    fn ($c) => ($c['layout']['position_type'] ?? null) !== 1
                );
        })
        ->assertSee('One');
});
