<?php

use Illuminate\Support\Facades\Route;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Contracts\NativeRouteFallback;
use Native\Mobile\Edge\Elements\BottomNav;
use Native\Mobile\Edge\Elements\BottomNavItem;
use Native\Mobile\Edge\Elements\TopBar;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\Builders\TabBarOptions;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\BarChoicesScreen;
use Tests\Fixtures\Edge\ChromeColumnLayout;
use Tests\Fixtures\Edge\ChromeScreen;
use Tests\Fixtures\Edge\ChromeTabsLayout;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\DetailScreen;
use Tests\Fixtures\Edge\DrawnRouteTabsLayout;
use Tests\Fixtures\Edge\HiddenTabOptionsScreen;
use Tests\Fixtures\Edge\HiddenTabScreen;
use Tests\Fixtures\Edge\InlineBarsScreen;
use Tests\Fixtures\Edge\RouteTabsLayout;
use Tests\Fixtures\Edge\StackOnlyLayout;

/**
 * Route::native(...)->children(...) declares the screens you reach from a
 * screen. A child's path is relative to its parent. The screen only knows
 * that it is a child. The bars decide what that means for them: the tab bar
 * hides and the top bar shows a back button, unless the bar was told
 * otherwise or the screen's own knobs say otherwise. The tab of the parent
 * stays highlighted. All of it holds on native chrome and on the
 * custom-Column chrome path alike.
 */
beforeEach(function () {
    NativeRouter::clearRoutes();
    app('view')->addLocation(__DIR__.'/../Fixtures/views');
});

afterEach(function () {
    NativeRouter::clearRoutes();
});

/** The active tab labels in a wire tree, whichever path drew the bar. */
function activeTabLabels(array $node): array
{
    $labels = [];

    if (($node['type'] ?? null) === 'bottom_nav_item' && ($node['props']['active'] ?? false)) {
        $labels[] = $node['props']['label'] ?? '';
    }

    foreach ($node['children'] ?? [] as $child) {
        $labels = [...$labels, ...activeTabLabels($child)];
    }

    return $labels;
}

/** The active tab labels of a TabBar builder. */
function activeBuilderTabs(TabBar $tabs): array
{
    return activeTabLabels($tabs->toElement()->toArray(new CallbackRegistry));
}

/** Whether the back button shows, whichever path drew the top bar. Null when nothing says. */
function backShown(array $node): ?bool
{
    $props = $node['props'] ?? [];
    $value = match ($node['type'] ?? null) {
        'native_root_tabs' => $props['nav_back'] ?? null,
        'native_root_stack' => $props['back'] ?? null,
        'top_bar' => $props['show_navigation_icon'] ?? null,
        default => null,
    };

    if ($value !== null) {
        return (bool) $value;
    }

    foreach ($node['children'] ?? [] as $child) {
        if (($found = backShown($child)) !== null) {
            return $found;
        }
    }

    return null;
}

/** A Chats tab with one child screen, plus a Settings tab. */
function registerChatRoutes(
    string $layout = RouteTabsLayout::class,
    string $child = ChromeScreen::class,
    string $parent = ChromeScreen::class,
): void {
    Route::nativeGroup($layout, function () use ($child, $parent) {
        Route::native('/chats', $parent)
            ->name('chats')
            ->children(
                Route::native('{id}', $child)->name('chats.show'),
            );

        Route::native('/settings', ChromeScreen::class)->name('settings');
    });
}

// ── Relative child paths ────────────────────────────

it('puts a child\'s path under its parent, with ->name() chained before ->children()', function () {
    $parent = Route::native('/chats', CounterScreen::class)
        ->name('chats')
        ->children(
            $child = Route::native('{id}', DetailScreen::class)->name('chats.show'),
            Route::native('new', DetailScreen::class),
        );

    // children() returns the parent route so the chain can carry on.
    expect($parent->getName())->toBe('chats')
        ->and($parent->uri())->toBe('chats')
        ->and($child->uri())->toBe('chats/{id}')
        ->and(array_keys(NativeRouter::registeredRoutes()))->toBe(['/chats', '/chats/{id}', '/chats/new'])
        ->and(NativeRouter::parentOf('/chats/{id}'))->toBe('/chats')
        ->and(NativeRouter::parentOf('/chats/new'))->toBe('/chats')
        ->and(NativeRouter::parentOf('/chats'))->toBeNull()
        // Nothing is left behind at the short URI.
        ->and(NativeRouter::isNativeRoute('/5'))->toBeFalse()
        ->and(NativeRouter::resolve('/chats/5'))->toMatchArray([
            'class' => DetailScreen::class,
            'params' => ['id' => '5'],
            'pattern' => '/chats/{id}',
            'parent' => '/chats',
        ]);
});

it('leaves a child alone when its URI is already under the parent', function () {
    Route::native('/chats', CounterScreen::class)->children(
        $child = Route::native('/chats/{id}', DetailScreen::class),
    );

    expect($child->uri())->toBe('chats/{id}')
        ->and(array_keys(NativeRouter::registeredRoutes()))->toBe(['/chats', '/chats/{id}'])
        ->and(NativeRouter::parentOf('/chats/{id}'))->toBe('/chats');
});

it('only treats whole segments as already under the parent', function () {
    Route::native('/chats', CounterScreen::class)->children(
        Route::native('/chats-archive', DetailScreen::class),
    );

    expect(array_keys(NativeRouter::registeredRoutes()))->toBe(['/chats', '/chats/chats-archive']);
});

it('leaves the children of the root route where they are', function () {
    Route::native('/', CounterScreen::class)->children(
        Route::native('notifications', DetailScreen::class),
    );

    expect(array_keys(NativeRouter::registeredRoutes()))->toBe(['/', '/notifications'])
        ->and(NativeRouter::parentOf('/notifications'))->toBe('/');
});

it('moves grandchildren along when nested declarations run inside-out', function () {
    // PHP runs the innermost children() first: `info` goes under `{id}`
    // while `{id}` is still at the top level. The outer call then has to
    // take both of them under `/chats`.
    Route::native('/chats', CounterScreen::class)->children(
        Route::native('{id}', DetailScreen::class)->children(
            Route::native('info', DetailScreen::class)->children(
                Route::native('photo/{photo}', DetailScreen::class)->name('chats.photo'),
            ),
        ),
    );

    expect(array_keys(NativeRouter::registeredRoutes()))->toBe([
        '/chats',
        '/chats/{id}',
        '/chats/{id}/info',
        '/chats/{id}/info/photo/{photo}',
    ])
        ->and(NativeRouter::ancestorsOf('/chats/{id}/info/photo/{photo}'))
        ->toBe(['/chats/{id}/info', '/chats/{id}', '/chats'])
        ->and(NativeRouter::ancestorsOf('/chats/{id}'))->toBe(['/chats'])
        ->and(NativeRouter::ancestorsOf('/chats'))->toBe([])
        ->and(NativeRouter::ancestorsOf('/nope'))->toBe([])
        ->and(NativeRouter::resolve('/chats/5/info/photo/9')['params'])->toBe(['id' => '5', 'photo' => '9'])
        ->and(route('chats.photo', ['id' => 5, 'photo' => 9], false))->toBe('/chats/5/info/photo/9');

    $this->get('/chats/5/info/photo/9')->assertSuccessful()->assertSee(DetailScreen::class);
    $this->get('/chats/5/info')->assertSuccessful();
    $this->get('/info')->assertNotFound();
});

it('keeps two parents\' children apart when they share a short URI', function () {
    // This asserts the UNBOUND default — a package may have bound a
    // NativeRouteFallback, which takes precedence over the stub.
    unset(app()[NativeRouteFallback::class]);

    Route::native('/chats', ChromeScreen::class)->children(
        Route::native('{id}', DetailScreen::class)->name('chats.show'),
    );

    Route::native('/posts', ChromeScreen::class)->children(
        Route::native('{id}', CounterScreen::class)->name('posts.show'),
    );

    // Native navigation finds both.
    expect(NativeRouter::resolve('/chats/5')['class'])->toBe(DetailScreen::class)
        ->and(NativeRouter::resolve('/posts/5')['class'])->toBe(CounterScreen::class)
        ->and(NativeRouter::isNativeRoute('/5'))->toBeFalse();

    // So does a real request through Laravel's router (start URL, deep
    // link, hot-reload redirect). The second `{id}` must not have taken
    // the first one's place in the route collection.
    $this->get('/chats/5')->assertSuccessful()->assertSee(DetailScreen::class);
    $this->get('/posts/5')->assertSuccessful()->assertSee(CounterScreen::class);
    $this->get('/5')->assertNotFound();
});

it('generates URLs for a named child route', function () {
    // The URL generator is usually built before the routes file loads.
    app('url');

    Route::native('/chats', CounterScreen::class)
        ->name('chats')
        ->children(
            Route::native('{id}', DetailScreen::class)->name('chats.show'),
        );

    // Laravel's name lookups already know both routes, without anyone
    // refreshing them: ->name() ran before ->children() rebuilt the index.
    expect(Route::getRoutes()->getByName('chats.show')?->uri())->toBe('chats/{id}')
        ->and(Route::getRoutes()->getByName('chats')?->uri())->toBe('chats')
        ->and(route('chats.show', ['id' => 5], false))->toBe('/chats/5')
        ->and(route('chats', [], false))->toBe('/chats');
});

it('does not double a route group prefix', function () {
    Route::prefix('app')->group(function () {
        Route::native('/chats', CounterScreen::class)->children(
            Route::native('{id}', DetailScreen::class)
                ->name('app.chats.show')
                ->children(Route::native('info', DetailScreen::class)),
            // The long form, written as the group sees it.
            Route::native('/chats/new', DetailScreen::class),
        );
    });

    expect(array_keys(NativeRouter::registeredRoutes()))->toBe([
        '/app/chats',
        '/app/chats/{id}',
        '/app/chats/{id}/info',
        '/app/chats/new',
    ])
        ->and(NativeRouter::parentOf('/app/chats/{id}/info'))->toBe('/app/chats/{id}')
        ->and(route('app.chats.show', ['id' => 5], false))->toBe('/app/chats/5');

    $this->get('/app/chats/5/info')->assertSuccessful();
});

it('works inside a nativeGroup', function () {
    Route::nativeGroup(RouteTabsLayout::class, function () {
        Route::native('/chats', CounterScreen::class)->children(
            Route::native('{id}', DetailScreen::class),
        );
    });

    expect(NativeRouter::resolve('/chats/7'))->toMatchArray([
        'layout' => RouteTabsLayout::class,
        'pattern' => '/chats/{id}',
        'parent' => '/chats',
    ]);
});

it('still matches a moved child after its old URI was matched once', function () {
    $child = Route::native('info', DetailScreen::class);

    // Matching compiles the route's regex and caches its parameter names
    // (none yet). Both would be wrong for the URI it is about to get.
    expect(NativeRouter::resolve('/info')['params'])->toBe([]);

    Route::native('/chats/{id}', CounterScreen::class)->children($child);

    expect(NativeRouter::resolve('/info'))->toBeNull()
        ->and(NativeRouter::resolve('/chats/5/info')['params'])->toBe(['id' => '5']);
});

it('keeps custom binding fields on a moved child', function () {
    Route::native('/chats', CounterScreen::class)->children(
        $child = Route::native('{chat:slug}', DetailScreen::class),
    );

    expect($child->uri())->toBe('chats/{chat}')
        ->and($child->bindingFieldFor('chat'))->toBe('slug');
});

it('refuses a relative child that took over an existing route\'s URI', function () {
    Route::native('/{id}', CounterScreen::class);

    // Declared as `{id}`, this child replaces the route above before
    // children() gets to move it. That cannot be undone, so say so.
    Route::native('/chats', CounterScreen::class)->children(
        Route::native('{id}', DetailScreen::class),
    );
})->throws(InvalidArgumentException::class, "Write the child's full path instead: [/chats/{id}]");

it('refuses a relative child that took over a web route\'s URI', function () {
    Route::get('/settings', fn () => 'web settings');

    Route::native('/profile', CounterScreen::class)->children(
        Route::native('settings', DetailScreen::class),
    );
})->throws(InvalidArgumentException::class, "Write the child's full path instead: [/profile/settings]");

it('lets the long form take over an existing route, as any route can', function () {
    Route::native('/chats/{id}', CounterScreen::class);

    Route::native('/chats', CounterScreen::class)->children(
        Route::native('/chats/{id}', DetailScreen::class),
    );

    expect(NativeRouter::resolve('/chats/5')['class'])->toBe(DetailScreen::class);
});

it('refuses a child with no path of its own', function () {
    Route::native('/chats', CounterScreen::class)->children(
        Route::native('/', DetailScreen::class),
    );
})->throws(InvalidArgumentException::class, 'needs a path of its own');

// ── The registry ────────────────────────────────────

it('finds the pattern for a concrete URI without resolving it', function () {
    Route::native('/chats', CounterScreen::class);
    Route::native('/chats/{id}', DetailScreen::class);

    expect(NativeRouter::patternFor('/chats/7'))->toBe('/chats/{id}')
        ->and(NativeRouter::patternFor('chats'))->toBe('/chats')
        ->and(NativeRouter::patternFor('/nope'))->toBeNull();
});

it('gives a child its parent\'s layout, whichever was set first', function () {
    // ->layout() after ->children(): the child was linked while the
    // parent still had no layout.
    Route::native('/chats', CounterScreen::class)
        ->children(Route::native('{id}', DetailScreen::class))
        ->layout(RouteTabsLayout::class);

    // ->layout() before ->children().
    Route::native('/settings', CounterScreen::class)
        ->layout(ChromeTabsLayout::class)
        ->children(Route::native('about', DetailScreen::class));

    expect(NativeRouter::resolve('/chats/7')['layout'])->toBe(RouteTabsLayout::class)
        ->and(NativeRouter::resolve('/settings/about')['layout'])->toBe(ChromeTabsLayout::class)
        ->and(NativeRouter::registeredRoutes()['/chats/{id}']['layout'])->toBe(RouteTabsLayout::class);
});

it('keeps a child\'s own layout and passes the nearest one down', function () {
    Route::native('/chats', CounterScreen::class)
        ->layout(RouteTabsLayout::class)
        ->children(
            Route::native('{id}', DetailScreen::class)
                ->layout(ChromeColumnLayout::class)
                ->children(Route::native('media', DetailScreen::class)),
            Route::native('new', DetailScreen::class),
        );

    expect(NativeRouter::resolve('/chats/7')['layout'])->toBe(ChromeColumnLayout::class)
        // The grandchild takes its nearest ancestor's layout, not the root's.
        ->and(NativeRouter::resolve('/chats/7/media')['layout'])->toBe(ChromeColumnLayout::class)
        ->and(NativeRouter::resolve('/chats/new')['layout'])->toBe(RouteTabsLayout::class);
});

it('finds a moved child when ->layout() is chained after ->children()', function () {
    $child = Route::native('{id}', DetailScreen::class);

    Route::native('/chats', CounterScreen::class)->children($child);

    $child->layout(StackOnlyLayout::class);

    expect(NativeRouter::resolve('/chats/7')['layout'])->toBe(StackOnlyLayout::class);
});

it('leaves a route with no parent and no layout without one', function () {
    Route::native('/chats', CounterScreen::class)->children(
        Route::native('{id}', DetailScreen::class),
    );

    expect(NativeRouter::resolve('/chats/7')['layout'])->toBeNull()
        ->and(NativeRouter::resolve('/chats'))->toMatchArray(['pattern' => '/chats', 'parent' => null]);
});

it('keeps the class and layout keys of registeredRoutes() and adds the parent', function () {
    Route::native('/chats', CounterScreen::class)->children(
        Route::native('{id}', DetailScreen::class),
    );

    expect(NativeRouter::registeredRoutes()['/chats/{id}'])->toMatchArray([
        'class' => DetailScreen::class,
        'layout' => null,
        'pattern' => '/chats/{id}',
        'parent' => '/chats',
    ]);
});

it('refuses a child that is not a native route', function () {
    Route::native('/chats', CounterScreen::class)->children(
        Route::get('/web-page', fn () => 'web'),
    );
})->throws(InvalidArgumentException::class, 'Route [/web-page] is not a native route');

it('refuses a parent that is not a native route', function () {
    Route::get('/web-page', fn () => 'web')->children(
        Route::native('/chats', CounterScreen::class),
    );
})->throws(InvalidArgumentException::class, 'Route [/web-page] is not a native route');

it('refuses a link that would make a route its own ancestor', function () {
    $chats = Route::native('/chats', CounterScreen::class);
    $detail = Route::native('{id}', DetailScreen::class);

    $chats->children($detail);

    expect(fn () => $detail->children($chats))->toThrow(InvalidArgumentException::class, 'its own ancestor')
        ->and(fn () => $chats->children($chats))->toThrow(InvalidArgumentException::class, 'its own ancestor')
        // The refused links changed nothing.
        ->and(array_keys(NativeRouter::registeredRoutes()))->toBe(['/chats', '/chats/{id}'])
        ->and(NativeRouter::parentOf('/chats'))->toBeNull()
        ->and(NativeRouter::ancestorsOf('/chats/{id}'))->toBe(['/chats']);
});

it('forgets parents when the routes are cleared', function () {
    Route::native('/chats', CounterScreen::class)->children(
        Route::native('{id}', DetailScreen::class),
    );

    NativeRouter::clearRoutes();

    expect(NativeRouter::parentOf('/chats/{id}'))->toBeNull()
        ->and(NativeRouter::registeredRoutes())->toBe([]);

    // Registering the same URI again starts with a clean slate.
    Route::native('/chats/{id}', DetailScreen::class);

    expect(NativeRouter::parentOf('/chats/{id}'))->toBeNull();
});

it('links two routes by pattern without moving either', function () {
    Route::native('/chats', CounterScreen::class);
    Route::native('/conversation/{id}', DetailScreen::class);

    NativeRouter::setParent('/conversation/{id}', '/chats');

    expect(array_keys(NativeRouter::registeredRoutes()))->toBe(['/chats', '/conversation/{id}'])
        ->and(NativeRouter::resolve('/conversation/5')['parent'])->toBe('/chats')
        ->and(fn () => NativeRouter::setParent('/chats', '/conversation/{id}'))
        ->toThrow(InvalidArgumentException::class, 'its own ancestor')
        ->and(fn () => NativeRouter::setParent('/nope', '/chats'))
        ->toThrow(InvalidArgumentException::class, 'Route [/nope] is not a native route');
});

// ── The screen knows it is a child ──────────────────

it('tells a screen whether its route is a child', function () {
    registerChatRoutes();

    expect(Native::visit('/chats/5')->instance()->isChildScreen())->toBeTrue()
        ->and(Native::visit('/chats')->instance()->isChildScreen())->toBeFalse()
        // Mounted straight from the class, there is no route to ask.
        ->and(Native::test(ChromeScreen::class)->instance()->isChildScreen())->toBeFalse();
});

it('leaves shouldHideTabBar() to the screen\'s own knobs', function () {
    Route::nativeGroup(RouteTabsLayout::class, function () {
        Route::native('/chats', ChromeScreen::class)->children(
            Route::native('{id}', ChromeScreen::class),
            Route::native('{id}/shortcut', HiddenTabScreen::class),
            Route::native('{id}/options', HiddenTabOptionsScreen::class),
        );
    });

    // The child's tab bar is hidden, but the screen did not decide that:
    // the bar did, from the route. shouldHideTabBar() only reports what
    // $hidesTabBar and tabBarOptions() say, exactly as before children().
    $child = Native::visit('/chats/5')->assertTabBarHidden();

    expect($child->instance()->shouldHideTabBar())->toBeFalse()
        ->and(Native::visit('/chats')->instance()->shouldHideTabBar())->toBeFalse()
        ->and(Native::visit('/chats/5/shortcut')->instance()->shouldHideTabBar())->toBeTrue()
        ->and(Native::visit('/chats/5/options')->instance()->shouldHideTabBar())->toBeTrue();
});

it('carries the route context across a followed navigation', function () {
    registerChatRoutes();

    $chats = Native::visit('/chats')->assertTabBarVisible();

    $conversation = $chats->call('navigate', '/chats/5')->follow();

    expect($conversation->instance()->isChildScreen())->toBeTrue();
    $conversation->assertTabBarHidden();
});

it('hands the route context to screens the router rebuilds', function () {
    registerChatRoutes();

    $router = new NativeRouter;
    $router->preloadStack([['uri' => '/chats'], ['uri' => '/chats/5']]);

    $stack = (fn () => $this->stack)->call($router);

    expect($stack)->toHaveCount(2)
        ->and($stack[0]['component']->isChildScreen())->toBeFalse()
        ->and($stack[1]['component']->isChildScreen())->toBeTrue();
});

// ── Defaults on native chrome ───────────────────────

it('hides the tab bar, shows back and keeps the parent tab with layout-built bars', function () {
    registerChatRoutes();

    $child = Native::visit('/chats/5')
        ->assertElement('native_root_tabs', function (array $n) {
            return ($n['props']['hide_tab_bar'] ?? false) === true
                && ($n['props']['nav_back'] ?? false) === true
                && ($n['props']['current_uri'] ?? null) === '/chats/5';
        })
        ->assertTabBarHidden()
        ->assertTabActive('Chats');

    expect(activeTabLabels($child->tree()))->toBe(['Chats']);

    // The parent is an ordinary tab root.
    Native::visit('/chats')
        ->assertElement('native_root_tabs', function (array $n) {
            return ! ($n['props']['hide_tab_bar'] ?? false)
                && ($n['props']['nav_back'] ?? null) === false;
        })
        ->assertTabBarVisible()
        ->assertTabActive('Chats');
});

it('hides the tab bar, shows back and keeps the parent tab with inline bars', function () {
    Route::native('/chats', InlineBarsScreen::class)->children(
        Route::native('{id}', InlineBarsScreen::class),
    );

    $child = Native::visit('/chats/5')
        ->assertElement('native_root_tabs', function (array $n) {
            return ($n['props']['hide_tab_bar'] ?? false) === true
                && ($n['props']['nav_back'] ?? false) === true;
        })
        ->assertTabActive('Chats');

    expect(activeTabLabels($child->tree()))->toBe(['Chats']);

    Native::visit('/chats')
        ->assertElement('native_root_tabs', function (array $n) {
            return ! ($n['props']['hide_tab_bar'] ?? false)
                && ($n['props']['nav_back'] ?? null) === false;
        })
        ->assertTabActive('Chats');
});

it('shows back on a child screen that only has a top bar', function () {
    Route::native('/chats', CounterScreen::class)->children(
        Route::native('{id}', ChromeScreen::class)->layout(StackOnlyLayout::class),
    );

    Native::visit('/chats/5')
        ->assertElement('native_root_stack', fn (array $n) => ($n['props']['back'] ?? false) === true);
});

// ── Defaults on the custom-Column chrome path ───────

it('hides the tab bar and shows back when the layout draws its own bars', function () {
    registerChatRoutes(DrawnRouteTabsLayout::class);

    Native::visit('/chats/5')
        ->assertMissingElement('native_root_tabs')
        ->assertMissingElement('bottom_nav')
        ->assertTabBarHidden()
        ->assertElement('top_bar', fn (array $n) => ($n['props']['show_navigation_icon'] ?? false) === true);

    $parent = Native::visit('/chats')
        ->assertMissingElement('native_root_tabs')
        ->assertElement('bottom_nav')
        ->assertElement('top_bar', fn (array $n) => ($n['props']['show_navigation_icon'] ?? null) === false);

    expect(activeTabLabels($parent->tree()))->toBe(['Chats']);
});

// ── The tab bar: the screen, then the bar, then the route ──

dataset('bar paths', [
    'platform bars' => RouteTabsLayout::class,
    'hand-built bars' => DrawnRouteTabsLayout::class,
]);

it('keeps an inline bottom nav marked :hidden="false" on a child screen', function () {
    registerChatRoutes(child: BarChoicesScreen::class);

    Native::visit('/chats/5', ['view' => 'inline-bars-keep-tabs-screen'])
        ->assertElement('native_root_tabs')
        ->assertTabBarVisible()
        ->assertTabActive('Chats');
});

it('hides an inline bottom nav marked hidden on a parent screen', function () {
    registerChatRoutes(parent: BarChoicesScreen::class);

    Native::visit('/chats', ['view' => 'inline-bars-hide-tabs-screen'])
        ->assertElement('native_root_tabs')
        ->assertTabBarHidden();
});

it('keeps a layout\'s TabBar set to hidden(false) on a child screen', function (string $layout) {
    registerChatRoutes($layout, BarChoicesScreen::class);

    $child = Native::visit('/chats/5', ['layoutTabsHidden' => false])->assertTabBarVisible();

    expect(activeTabLabels($child->tree()))->toBe(['Chats']);
})->with('bar paths');

it('hides a layout\'s TabBar set to hidden() on a parent screen', function (string $layout) {
    registerChatRoutes($layout, parent: BarChoicesScreen::class);

    Native::visit('/chats', ['layoutTabsHidden' => true])->assertTabBarHidden();
})->with('bar paths');

it('lets tabBarOptions()->hidden(true) beat a bar that says keep', function (string $layout) {
    registerChatRoutes($layout, parent: BarChoicesScreen::class);

    // The layout's bar says keep. On a parent screen, so the route says
    // keep too. The screen's own say still wins.
    Native::visit('/chats', ['layoutTabsHidden' => false, 'tabBarHidden' => true])
        ->assertTabBarHidden();
})->with('bar paths');

it('lets tabBarOptions()->hidden(true) beat an inline :hidden="false"', function () {
    registerChatRoutes(parent: BarChoicesScreen::class, child: BarChoicesScreen::class);

    foreach (['/chats', '/chats/5'] as $uri) {
        Native::visit($uri, ['view' => 'inline-bars-keep-tabs-screen', 'tabBarHidden' => true])
            ->assertElement('native_root_tabs')
            ->assertTabBarHidden();
    }
});

it('lets $hidesTabBar = true beat a bar that says keep', function () {
    registerChatRoutes(parent: BarChoicesScreen::class, child: BarChoicesScreen::class);

    Native::visit('/chats', ['view' => 'inline-bars-keep-tabs-screen', 'hidesTabBar' => true])
        ->assertTabBarHidden();

    Native::visit('/chats/5', ['layoutTabsHidden' => false, 'hidesTabBar' => true])
        ->assertTabBarHidden();
});

it('lets tabBarOptions()->hidden(false) keep a bar that says hide', function (string $layout) {
    registerChatRoutes($layout, BarChoicesScreen::class);

    // A child route, and the layout's bar asks to hide on top of that.
    Native::visit('/chats/5', ['layoutTabsHidden' => true, 'tabBarHidden' => false])
        ->assertTabBarVisible();

    // The screen alone, against the route default.
    Native::visit('/chats/5', ['tabBarHidden' => false])->assertTabBarVisible();
})->with('bar paths');

it('lets tabBarOptions()->hidden(false) keep an inline bar marked hidden', function () {
    registerChatRoutes(child: BarChoicesScreen::class);

    Native::visit('/chats/5', ['view' => 'inline-bars-hide-tabs-screen', 'tabBarHidden' => false])
        ->assertTabBarVisible();
});

// ── The back button: the screen, then the bar, then the route ──

it('keeps the back button off for an inline back="false" on a child screen', function () {
    registerChatRoutes(child: BarChoicesScreen::class);

    $child = Native::visit('/chats/5', ['view' => 'inline-bars-no-back-screen']);

    expect(backShown($child->tree()))->toBeFalse();
});

it('shows the back button for an inline back on a parent screen', function () {
    registerChatRoutes(parent: BarChoicesScreen::class);

    $parent = Native::visit('/chats', ['view' => 'inline-bars-back-screen']);

    expect(backShown($parent->tree()))->toBeTrue();
});

it('keeps the back button off for a layout\'s NavBar set to back(false) on a child screen', function (string $layout) {
    registerChatRoutes($layout, BarChoicesScreen::class);

    $child = Native::visit('/chats/5', ['layoutBack' => false])
        // Only the back button was refused. The tab bar's default stands.
        ->assertTabBarHidden();

    expect(backShown($child->tree()))->toBeFalse();
})->with('bar paths');

it('shows the back button for a layout\'s NavBar set to back() on a parent screen', function (string $layout) {
    registerChatRoutes($layout, parent: BarChoicesScreen::class);

    expect(backShown(Native::visit('/chats', ['layoutBack' => true])->tree()))->toBeTrue();
})->with('bar paths');

it('lets navigationOptions()->back() beat the layout\'s NavBar', function (string $layout) {
    registerChatRoutes($layout, BarChoicesScreen::class, BarChoicesScreen::class);

    expect(backShown(Native::visit('/chats/5', ['layoutBack' => true, 'navBack' => false])->tree()))->toBeFalse()
        ->and(backShown(Native::visit('/chats', ['layoutBack' => false, 'navBack' => true])->tree()))->toBeTrue()
        // Against the route default alone.
        ->and(backShown(Native::visit('/chats/5', ['navBack' => false])->tree()))->toBeFalse();
})->with('bar paths');

it('lets setNavBar([\'back\' => ...]) beat the layout\'s NavBar', function (string $layout) {
    registerChatRoutes($layout, BarChoicesScreen::class, BarChoicesScreen::class);

    expect(backShown(Native::visit('/chats/5', ['layoutBack' => true, 'navState' => ['back' => false]])->tree()))->toBeFalse()
        ->and(backShown(Native::visit('/chats', ['navState' => ['back' => true]])->tree()))->toBeTrue();
})->with('bar paths');

it('still leaves an inline top bar out of navigationOptions()', function () {
    registerChatRoutes(child: BarChoicesScreen::class, parent: BarChoicesScreen::class);

    // An inline <native:top-bar> has never merged the screen's
    // navigationOptions(). That stays, so its own attribute and then the
    // route are all that decide.
    expect(backShown(Native::visit('/chats', ['view' => 'inline-bars-back-screen', 'navBack' => false])->tree()))->toBeTrue()
        ->and(backShown(Native::visit('/chats/5', ['view' => 'inline-bars-screen', 'navBack' => false])->tree()))->toBeTrue();
});

// ── The owning tab ──────────────────────────────────

it('keeps the Home tab for a child of the root route', function () {
    // `/notifications` is not under any tab's URL as a prefix: a tab at
    // `/` only ever matches `/` itself. The route tree decides.
    Route::native('/', ChromeScreen::class)
        ->layout(ChromeTabsLayout::class)
        ->children(Route::native('notifications', ChromeScreen::class));

    $child = Native::visit('/notifications')->assertTabBarHidden();

    expect(activeTabLabels($child->tree()))->toBe(['Home']);
});

it('keeps the parent tab for a child linked at an unrelated URL', function () {
    Route::nativeGroup(RouteTabsLayout::class, function () {
        Route::native('/chats', ChromeScreen::class);
        Route::native('/settings', ChromeScreen::class);
        Route::native('/conversation/{id}', ChromeScreen::class);
    });

    NativeRouter::setParent('/conversation/{id}', '/chats');

    $child = Native::visit('/conversation/5')
        ->assertElement('native_root_tabs', fn (array $n) => ($n['props']['nav_back'] ?? false) === true)
        ->assertTabBarHidden();

    expect(activeTabLabels($child->tree()))->toBe(['Chats']);
});

it('still highlights by URL alone when given no ancestors', function () {
    $tabs = fn () => TabBar::make()
        ->add(Tab::link('Chats', '/chats'))
        ->add(Tab::link('Settings', '/settings'));

    expect(activeBuilderTabs($tabs()->highlight('/chats/5')))->toBe(['Chats'])
        ->and(activeBuilderTabs($tabs()->highlight('/settings')))->toBe(['Settings'])
        ->and(activeBuilderTabs($tabs()->highlight('/conversation/5')))->toBe([]);
});

it('highlights the tab of the nearest ancestor route', function () {
    $tabs = fn () => TabBar::make()
        ->add(Tab::link('Home', '/'))
        ->add(Tab::link('Chats', '/chats'))
        ->add(Tab::link('Settings', '/settings'));

    // No shared prefix: the ancestor chain finds the tab.
    expect(activeBuilderTabs($tabs()->highlight('/conversation/5', ['/chats'])))->toBe(['Chats'])
        // Nearest first: skip ancestors that are not tabs.
        ->and(activeBuilderTabs($tabs()->highlight('/photo/9', ['/conversation/{id}', '/chats'])))->toBe(['Chats'])
        // An exact URL match beats the route tree.
        ->and(activeBuilderTabs($tabs()->highlight('/settings', ['/chats'])))->toBe(['Settings'])
        // The route tree beats a URL prefix.
        ->and(activeBuilderTabs($tabs()->highlight('/settings/about', ['/chats'])))->toBe(['Chats'])
        // No ancestor is a tab: fall back to the longest prefix.
        ->and(activeBuilderTabs($tabs()->highlight('/settings/about', ['/elsewhere'])))->toBe(['Settings']);
});

it('matches a tab with route parameters to its ancestor pattern', function () {
    Route::native('/teams/{team}', CounterScreen::class);
    Route::native('/members/{member}', DetailScreen::class);
    NativeRouter::setParent('/members/{member}', '/teams/{team}');

    $tabs = TabBar::make()
        ->add(Tab::link('Home', '/'))
        ->add(Tab::link('Team', '/teams/5'))
        ->highlight('/members/9', NativeRouter::ancestorsOf('/members/{member}'));

    expect(activeBuilderTabs($tabs))->toBe(['Team']);
});

it('leaves an inline item marked active alone, whatever the route tree says', function () {
    $nav = BottomNav::make();
    $nav->applyAttributes([]);
    foreach ([['chats', '/chats', false], ['settings', '/settings', true]] as [$id, $url, $active]) {
        $item = BottomNavItem::make();
        $item->applyAttributes(['id' => $id, 'label' => ucfirst($id), 'url' => $url, 'active' => $active]);
        $nav->addChild($item);
    }

    [$chats, $settings] = TabBar::fromElement($nav)
        ->highlight('/conversation/5', ['/chats'])
        ->tabElements();

    expect($chats->isActive())->toBeFalse()
        ->and($settings->isActive())->toBeTrue();
});

// ── The bars on their own ───────────────────────────

/** A screen whose route is a child route, or not, without registering any routes. */
function screenOnRoute(bool $child): ChromeScreen
{
    $screen = new ChromeScreen;
    $screen->setRouteContext($child ? '/chats/{id}' : '/chats', $child ? '/chats' : null);

    return $screen;
}

it('shows the back button on a child route unless the NavBar was told', function () {
    expect(NavBar::make()->showsBack())->toBeFalse()
        ->and(NavBar::make()->forScreen(screenOnRoute(false))->showsBack())->toBeFalse()
        ->and(NavBar::make()->forScreen(screenOnRoute(true))->showsBack())->toBeTrue()
        ->and(NavBar::make()->back(false)->forScreen(screenOnRoute(true))->showsBack())->toBeFalse()
        ->and(NavBar::make()->back()->forScreen(screenOnRoute(false))->showsBack())->toBeTrue()
        // The order of the calls does not matter.
        ->and(NavBar::make()->forScreen(screenOnRoute(true))->back(false)->showsBack())->toBeFalse()
        ->and(NavBar::make()->forScreen(screenOnRoute(true))->toRootProps()['back'])->toBeTrue();
});

it('treats the screen\'s options and state as setting the back button', function () {
    $viaOptions = NavBar::make()->back()->mergeOptions(NavBarOptions::make()->back(false));
    $viaState = NavBar::make()->back()->mergeState(['back' => false]);

    foreach ([$viaOptions, $viaState] as $bar) {
        expect($bar->forScreen(screenOnRoute(true))->showsBack())->toBeFalse();
    }

    // Options and state that say nothing about back leave it to the route.
    $untouched = NavBar::make()
        ->mergeOptions(NavBarOptions::make()->title('Hi'))
        ->mergeState(['title' => 'Hello'])
        ->forScreen(screenOnRoute(true));

    expect($untouched->showsBack())->toBeTrue();
});

it('tells an absent inline back attribute from back="false"', function () {
    $make = function (array $attrs): NavBar {
        $el = TopBar::make();
        $el->applyAttributes(['title' => 'X', ...$attrs]);

        return NavBar::fromElement($el)->forScreen(screenOnRoute(true));
    };

    expect($make([])->showsBack())->toBeTrue()
        ->and($make(['back' => 'false'])->showsBack())->toBeFalse()
        ->and($make(['back' => false])->showsBack())->toBeFalse()
        ->and($make(['show-navigation-icon' => false])->showsBack())->toBeFalse()
        ->and(NavBar::fromElement(tap(TopBar::make())->applyAttributes(['back' => true]))->showsBack())->toBeTrue();
});

it('carries an unset back button through toElement() and fromElement()', function () {
    $roundTrip = fn (NavBar $bar): NavBar => NavBar::fromElement($bar->toElement());

    // Unset stays unset, so the route still decides after the round trip.
    expect(array_key_exists('show_navigation_icon', NavBar::make()->toElement()->getRawProps()))->toBeFalse()
        ->and($roundTrip(NavBar::make())->forScreen(screenOnRoute(true))->showsBack())->toBeTrue()
        // A value set on purpose stays set, either way.
        ->and($roundTrip(NavBar::make()->back(false))->forScreen(screenOnRoute(true))->showsBack())->toBeFalse()
        ->and($roundTrip(NavBar::make()->back())->forScreen(screenOnRoute(false))->showsBack())->toBeTrue()
        // Even when the bar already knew its screen: only the drawn value
        // takes the route default, never the attribute.
        ->and(array_key_exists('show_navigation_icon', NavBar::make()->forScreen(screenOnRoute(true))->toElement()->getRawProps()))->toBeFalse();
});

it('always draws a back button value, from the route when nothing set it', function () {
    $drawn = fn (NavBar $bar): mixed => $bar->toElement()->toArray(new CallbackRegistry)['props']['show_navigation_icon'] ?? null;

    // A drawn bar must say: one renderer shows the icon when the prop is missing.
    expect($drawn(NavBar::make()))->toBeFalse()
        ->and($drawn(NavBar::make()->forScreen(screenOnRoute(true))))->toBeTrue()
        ->and($drawn(NavBar::make()->back(false)->forScreen(screenOnRoute(true))))->toBeFalse()
        ->and($drawn(NavBar::make()->back()))->toBeTrue();

    // A top bar written in Blade without `back` is drawn as before: no prop.
    $inline = TopBar::make();
    $inline->applyAttributes(['title' => 'X']);
    expect($inline->toArray(new CallbackRegistry)['props'])->not->toHaveKey('show_navigation_icon');
});

it('hides the tab bar on a child route unless the TabBar was told', function () {
    expect(TabBar::make()->isHidden())->toBeFalse()
        ->and(TabBar::make()->forScreen(screenOnRoute(false))->isHidden())->toBeFalse()
        ->and(TabBar::make()->forScreen(screenOnRoute(true))->isHidden())->toBeTrue()
        ->and(TabBar::make()->hidden(false)->forScreen(screenOnRoute(true))->isHidden())->toBeFalse()
        ->and(TabBar::make()->hidden()->forScreen(screenOnRoute(false))->isHidden())->toBeTrue()
        // null hands the choice back to the route.
        ->and(TabBar::make()->hidden(false)->hidden(null)->forScreen(screenOnRoute(true))->isHidden())->toBeTrue()
        // The sentinel only hears about it when the bar hides.
        ->and(TabBar::make()->forScreen(screenOnRoute(true))->toRootProps())->toMatchArray(['hideTabBar' => true])
        ->and(TabBar::make()->forScreen(screenOnRoute(false))->toRootProps())->not->toHaveKey('hideTabBar');
});

it('lets the screen\'s own tab bar knobs beat the TabBar', function () {
    $hidesTabBar = new HiddenTabScreen;
    $optionsHide = new HiddenTabOptionsScreen;
    $optionsKeep = new class extends ChromeScreen
    {
        public function tabBarOptions(): ?TabBarOptions
        {
            return TabBarOptions::make()->hidden(false);
        }
    };
    $optionsKeep->setRouteContext('/chats/{id}', '/chats');

    expect(TabBar::make()->hidden(false)->forScreen($hidesTabBar)->isHidden())->toBeTrue()
        ->and(TabBar::make()->hidden(false)->forScreen($optionsHide)->isHidden())->toBeTrue()
        ->and(TabBar::make()->hidden()->forScreen($optionsKeep)->isHidden())->toBeFalse();
});

it('carries an unset tab bar through toElement() and fromElement()', function () {
    $roundTrip = fn (TabBar $bar): TabBar => TabBar::fromElement($bar->toElement());

    expect(TabBar::make()->toElement()->hiddenAttribute())->toBeNull()
        ->and($roundTrip(TabBar::make())->forScreen(screenOnRoute(true))->isHidden())->toBeTrue()
        ->and($roundTrip(TabBar::make()->hidden(false))->forScreen(screenOnRoute(true))->isHidden())->toBeFalse()
        ->and($roundTrip(TabBar::make()->hidden())->forScreen(screenOnRoute(false))->isHidden())->toBeTrue()
        // Never on the wire: a drawn bar is simply not drawn when hidden.
        ->and(TabBar::make()->hidden(false)->toElement()->toArray(new CallbackRegistry)['props'])->not->toHaveKey('hidden');
});

it('reads the hidden attribute of an inline bottom nav', function () {
    $hidden = function (array $attrs): ?bool {
        $nav = BottomNav::make();
        $nav->applyAttributes($attrs);

        return $nav->hiddenAttribute();
    };

    expect($hidden([]))->toBeNull()
        ->and($hidden(['hidden' => true]))->toBeTrue()
        ->and($hidden(['hidden' => false]))->toBeFalse()
        ->and($hidden(['hidden' => 'false']))->toBeFalse()
        ->and($hidden(['hidden' => null]))->toBeNull();
});
