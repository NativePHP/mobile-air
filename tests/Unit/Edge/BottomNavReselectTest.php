<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Elements\BottomNavItem;
use Native\Mobile\Edge\Layouts\Builders\Tab;

/*
 * Tab::onReselect() — the active-tab reselect handler for bottom-nav
 * items. Wire contract (Android renderer consumes it):
 *
 *   - `on_reselect` prop: registered callback id, dispatched via the
 *     PRESS event when the already-active tab is re-tapped.
 *   - `reselect_scroll_target` prop: optional string naming a native
 *     scroll container registered in NativeScrollTargetRegistry. When
 *     present, native scrolls that container to top first and then
 *     invokes PHP once the scroll completes — unless it is already at
 *     exact top, in which case PHP is invoked immediately.
 *
 * Both props are omitted entirely when no handler is configured, and a
 * configured handler never suppresses the URL tab's auto-wired
 * `on_press` navigation (unlike ->press(), which overrides it).
 */

it('omits on_reselect and reselect_scroll_target from a plain URL tab', function () {
    $registry = new CallbackRegistry;
    $node = Tab::link('Log', '/log')->toElement()->toArray($registry);

    expect($node['props'])->not->toHaveKey('on_reselect')
        ->and($node['props'])->not->toHaveKey('reselect_scroll_target');
});

it('keeps the plain URL tab navigate on_press unchanged', function () {
    $registry = new CallbackRegistry;
    $node = Tab::link('Log', '/log')->toElement()->toArray($registry);

    expect($node)->toHaveKey('on_press');

    $resolved = $registry->resolve($node['on_press']);
    expect($resolved['method'])->toBe('__navigate');

    $config = $registry->resolveNavigation($resolved['args'][0]);
    expect($config)->toMatchArray([
        'type' => 'replace',
        'uri' => '/log',
        'transition' => 'none',
    ]);
});

it('serializes onReselect as a registered callback id without touching the navigate on_press', function () {
    $registry = new CallbackRegistry;
    $node = Tab::link('Log', '/log')
        ->onReselect('refreshLog')
        ->toElement()
        ->toArray($registry);

    $props = $node['props'];
    expect($props)->toHaveKey('on_reselect')
        ->and($props['on_reselect'])->toBeInt()->toBeGreaterThan(0)
        ->and($props)->not->toHaveKey('reselect_scroll_target');

    // Distinct id from the navigate press, which stays intact.
    expect($props['on_reselect'])->not->toBe($node['on_press']);
    $nav = $registry->resolve($node['on_press']);
    expect($nav['method'])->toBe('__navigate');

    // The reselect id resolves back to the handler method.
    $reselect = $registry->resolve($props['on_reselect']);
    expect($reselect['method'])->toBe('refreshLog')
        ->and($reselect['args'])->toBe([]);
});

it('serializes reselect_scroll_target when a scroll target is configured', function () {
    $registry = new CallbackRegistry;
    $node = Tab::link('Log', '/log')
        ->onReselect('refreshLog', 'log-feed')
        ->toElement()
        ->toArray($registry);

    expect($node['props'])->toMatchArray([
        'on_reselect' => $registry->lookup('refreshLog'),
        'reselect_scroll_target' => 'log-feed',
    ]);
});

it('coexists with a custom press handler: press keeps on_press, reselect keeps its prop', function () {
    $registry = new CallbackRegistry;
    $node = Tab::link('Log', '/log')
        ->press('openSheet')
        ->onReselect('refreshLog')
        ->toElement()
        ->toArray($registry);

    expect($node['on_press'])->toBe($registry->lookup('openSheet'))
        ->and($node['props']['on_reselect'])->toBe($registry->lookup('refreshLog'));
});

it('serializes a reselect handler set directly on BottomNavItem', function () {
    $registry = new CallbackRegistry;
    $item = BottomNavItem::make();
    $item->applyAttributes(['id' => 'log', 'label' => 'Log', 'url' => '/log']);
    $item->onReselect('refreshLog', 'log-feed');
    $node = $item->toArray($registry);

    expect($node['props'])->toMatchArray([
        'on_reselect' => $registry->lookup('refreshLog'),
        'reselect_scroll_target' => 'log-feed',
    ]);
});
