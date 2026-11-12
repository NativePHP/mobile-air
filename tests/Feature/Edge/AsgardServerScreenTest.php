<?php

use Native\Mobile\Edge\Asgard\ComponentAddress;
use Native\Mobile\Edge\Asgard\FragmentCallbacks;
use Native\Mobile\Edge\Asgard\ServerRegion;
use Native\Mobile\Edge\Asgard\ServerScreen;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Fixtures\Edge\AppPromoScreen;

/**
 * Whole server screens, server routes and chrome
 * (docs/ASGARD-PROTOCOL.md §12): a server route opens the generic
 * ServerScreen, whose body is the `screen` region and whose chrome comes
 * with each answer.
 */
beforeEach(function () {
    NativeRouter::clearRoutes();
    ServerRegion::flush();
    ServerScreen::flushChrome();
    config(['app.debug' => false]);
    Native::fakeFragmentSource();

    NativeRouter::register('/', AppPromoScreen::class);
    NativeRouter::register('/promos/new', AppPromoScreen::class);
    NativeRouter::registerServerRoutes([
        ['uri' => '/promos/{id}', 'screen' => 'promos.show'],
        ['uri' => '/promos/new', 'screen' => 'promos.create'],
        ['uri' => '/inbox', 'screen' => 'inbox'],
    ]);
});

afterEach(function () {
    NativeRouter::clearRoutes();
    ServerRegion::flush();
    ServerScreen::flushChrome();
});

const PROMO_BODY = ['type' => 'column', 'children' => [['type' => 'text', 'key' => 'title', 'props' => ['text' => 'Promo 42']]]];

/** Press the node a callback id belongs to, as a device tap would. */
function pressCallback(TestableComponent $screen, int $id): TestableComponent
{
    return Closure::bind(function () use ($id) {
        $this->startInteraction();

        return $this->dispatchUiEvent(['type' => 1, 'callback_id' => $id]);
    }, $screen, TestableComponent::class)();
}

/** The on_press id of the first node of `$type` matching `$match`. */
function pressIdOf(array $node, string $type, callable $match): ?int
{
    if (($node['type'] ?? null) === $type && $match($node)) {
        return $node['on_press'] ?? $node['props']['on_press'] ?? null;
    }

    foreach ($node['children'] ?? [] as $child) {
        if (($id = pressIdOf($child, $type, $match)) !== null) {
            return $id;
        }
    }

    return null;
}

/** Answer the screen's latest request and draw the next frame. */
function answer(TestableComponent $screen, array $fragment, ?array $chrome = null): TestableComponent
{
    $source = Native::fakeFragmentSource();
    expect(ServerRegion::deliver($source->lastRequest('screen'), $fragment, $chrome))->toBeTrue();

    return repaint($screen);
}

/** Draw the next frame, as the runloop does once a fragment lands. */
function repaint(TestableComponent $screen): TestableComponent
{
    return Closure::bind(function () {
        $this->startInteraction();

        return $this->afterInteraction();
    }, $screen, TestableComponent::class)();
}

// ── Routes ──────────────────────────────────────────

it('resolves a server route to the ServerScreen with its address', function () {
    $resolved = NativeRouter::resolve('/promos/42?ref=mail');

    expect($resolved['class'])->toBe(ServerScreen::class)
        ->and($resolved['screen'])->toBe('promos.show')
        ->and($resolved['params'])->toBe(['id' => '42'])
        ->and($resolved['query'])->toBe(['ref' => 'mail']);
});

it('keeps app routes ahead of server routes, even parameterized ones', function () {
    expect(NativeRouter::resolve('/promos/new')['class'])->toBe(AppPromoScreen::class);
    expect(NativeRouter::serverRoutes())->not->toHaveKey('/promos/new');

    NativeRouter::register('/inbox/{folder?}', AppPromoScreen::class);
    NativeRouter::register('/{anything}', AppPromoScreen::class);

    expect(NativeRouter::resolve('/promos/7')['class'])->toBe(ServerScreen::class);
    expect(NativeRouter::resolve('/inbox')['class'])->toBe(AppPromoScreen::class);
});

it('leaves app routes out of the server table and server routes out of registeredRoutes', function () {
    expect(NativeRouter::serverRoutes())->toBe(['/promos/{id}' => 'promos.show', '/inbox' => 'inbox']);
    expect(array_keys(NativeRouter::registeredRoutes()))->toBe(['/', '/promos/new']);
});

it('replaces the whole server table on each registration', function () {
    expect(NativeRouter::registerServerRoutes([['uri' => '/deals', 'screen' => 'deals']]))->toBe(1);

    expect(NativeRouter::isNativeRoute('/promos/1'))->toBeFalse()
        ->and(NativeRouter::isNativeRoute('/deals'))->toBeTrue();
});

it('drops malformed server routes', function (mixed $entry) {
    expect(NativeRouter::registerServerRoutes([$entry, ['uri' => '/ok', 'screen' => 'ok']]))->toBe(1);
})->with([
    'not an array' => ['/promos'],
    'a web url' => [['uri' => 'https://x.test/a', 'screen' => 'a']],
    'no leading slash' => [['uri' => 'promos', 'screen' => 'a']],
    'an optional param mid-route' => [['uri' => '/a/{b?}/c', 'screen' => 'a']],
    'a class name for a screen' => [['uri' => '/a', 'screen' => 'App\\Screens\\A']],
    'no screen' => [['uri' => '/a']],
]);

// ── The screen ──────────────────────────────────────

it('shows a skeleton and asks for its body under its own address with route state', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::visit('/promos/42?ref=mail&id=spoof');

    expect($screen->instance())->toBeInstanceOf(ServerScreen::class);
    expect(ServerRegion::rendered($screen->instance()))->toHaveKey('screen');
    expect(ServerRegion::hasFragment('screen', $screen->instance()))->toBeFalse();
    $source->assertRequested('screen', times: 1);

    $request = $source->lastRequest('screen');
    expect($request->component)->toBe('promos.show')
        ->and(ComponentAddress::for($screen->instance()))->toBe('promos.show')
        ->and($request->state)->toBe(['ref' => 'mail', 'id' => '42']);
});

it('paints the body when the answer lands', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::visit('/promos/42');

    answer($screen, PROMO_BODY)->assertSee('Promo 42');
});

it('wears the chrome that comes with the answer', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::visit('/promos/42');

    answer($screen, PROMO_BODY, [
        'nav_bar' => [
            'title' => 'Promo #42',
            'back' => true,
            'actions' => [['id' => 'like', 'icon' => 'heart', 'on_press' => ['call' => 'like', 'args' => [1]]]],
        ],
        'tab_bar' => ['tabs' => [['id' => 'home', 'label' => 'Home', 'url' => '/']]],
    ])
        ->assertNavTitle('Promo #42')
        ->assertNavAction('like')
        ->assertHasTab('Home');
});

it('sends a server action from a chrome button', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::visit('/promos/42');

    answer($screen, PROMO_BODY, ['nav_bar' => ['title' => 'P', 'actions' => [['id' => 'like', 'on_press' => ['call' => 'like', 'args' => [2]]]]]]);

    $id = pressIdOf($screen->tree(), 'top_bar_action', fn ($n) => ($n['props']['id'] ?? null) === 'like');
    expect($id)->toBeInt();

    pressCallback($screen, $id);

    $source->assertActionSent('screen', 'like', times: 1);
    expect($source->lastAction('screen')->component)->toBe('promos.show')
        ->and($source->lastAction('screen')->args)->toBe([2]);
});

it('navigates from a chrome button to a known route', function () {
    $screen = Native::visit('/promos/42');

    answer($screen, PROMO_BODY, ['nav_bar' => ['actions' => [['id' => 'next', 'on_press' => ['navigate' => '/promos/43', 'transition' => 'fade']]]]]);

    pressCallback($screen, pressIdOf($screen->tree(), 'top_bar_action', fn () => true))
        ->assertNavigatedTo('/promos/43')
        ->assertTransition('fade');
});

it('opens a link tab with replace, through the checked path', function () {
    $screen = Native::visit('/promos/42');

    answer($screen, PROMO_BODY, ['tab_bar' => ['tabs' => [['id' => 'inbox', 'label' => 'Inbox', 'url' => '/inbox']]]]);

    pressCallback($screen, pressIdOf($screen->tree(), 'bottom_nav_item', fn () => true))
        ->assertReplacedWith('/inbox');
});

it('keeps the chrome it had when new chrome fails the check', function () {
    $screen = Native::visit('/promos/42');

    answer($screen, PROMO_BODY, ['nav_bar' => ['title' => 'Good']]);

    expect(ServerRegion::push('promos.show', 'screen', PROMO_BODY, ['nav_bar' => ['title' => 'Bad', 'colour' => 'red']]))->toBe(1);

    repaint($screen)->assertNavTitle('Good');
});

it('throws on bad chrome with app.debug on', function () {
    config(['app.debug' => true]);
    $screen = Native::visit('/promos/42');

    expect(fn () => answer($screen, PROMO_BODY, ['nav_bar' => ['actions' => [['id' => 'x', 'on_press' => ['navigate' => 'https://evil.test']]]]]))
        ->toThrow(RuntimeException::class, 'app path');
});

it('hides the bars the chrome says to hide', function () {
    $screen = Native::visit('/promos/42');

    answer($screen, PROMO_BODY, ['native' => true, 'nav_bar' => ['title' => 'T', 'hidden' => true], 'tab_bar' => ['hidden' => true, 'tabs' => [['id' => 'h', 'label' => 'H', 'url' => '/']]]])
        ->assertNavBarHidden()
        ->assertTabBarHidden();
});

it('paints chrome content with its callbacks in the screen scope', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::visit('/promos/42');

    answer($screen, PROMO_BODY, [
        'native' => true,
        'nav_bar' => ['title' => 'T', 'title_view' => [['type' => 'text', 'props' => ['text' => 'Brand']]]],
        'bottom_bar' => ['type' => 'pressable', 'on_press' => ['call' => 'send', 'args' => []], 'children' => [['type' => 'text', 'props' => ['text' => 'Send']]]],
    ])->assertSee('Brand')->assertSee('Send')->tap('Send');

    $source->assertActionSent('screen', 'send', times: 1);
});

it('sends the search query to the server action the chrome names', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::visit('/promos/42');

    answer($screen, PROMO_BODY, ['nav_bar' => ['search' => ['placeholder' => 'Find', 'on_query' => ['call' => 'search', 'args' => ['all']]]]]);

    $screen->instance()->asgardSearchQuery('shoes');

    expect($source->lastAction('screen')->call)->toBe('search')
        ->and($source->lastAction('screen')->args)->toBe(['all', 'shoes']);
});

it('starts a screen opened again in the chrome it last had', function () {
    $screen = Native::visit('/promos/42');
    answer($screen, PROMO_BODY, ['nav_bar' => ['title' => 'Remembered']]);

    Native::visit('/promos/43')->assertNavTitle('Remembered');
});

it('applies chrome pushed with a fragment', function () {
    $screen = Native::visit('/promos/42');

    expect(ServerRegion::push('promos.show', 'screen', PROMO_BODY, ['nav_bar' => ['title' => 'Pushed']]))->toBe(1);

    repaint($screen)->assertNavTitle('Pushed')->assertSee('Promo 42');
});

it('keeps a pushed fragment off a screen of the same route with other parameters', function () {
    $body = fn (string $text) => ['type' => 'text', 'props' => ['text' => $text]];

    $first = Native::visit('/promos/1');
    answer($first, $body('Promo 1'), ['nav_bar' => ['title' => 'Promo #1']]);

    // The next screen up the stack opens the address's stream with its own
    // state, and the server pushes its body down it.
    $second = Native::visit('/promos/2');
    expect(ServerRegion::push('promos.show', 'screen', $body('Promo 2'), ['nav_bar' => ['title' => 'Promo #2']]))->toBe(1);

    repaint($second)->assertSee('Promo 2')->assertNavTitle('Promo #2');

    // Going back shows the screen underneath as it was, not a copy of the
    // one that just left.
    repaint($first)->assertSee('Promo 1')->assertDontSee('Promo 2')->assertNavTitle('Promo #1');
});

/** Resume a screen as the router does when the one above it pops. */
function resume(TestableComponent $screen): TestableComponent
{
    Closure::bind(fn () => $this->resumeFromFlow(), $screen, TestableComponent::class)();

    return $screen;
}

it('takes its stream back when it comes back to the top of the stack', function () {
    $body = fn (string $text) => ['type' => 'text', 'props' => ['text' => $text]];
    $source = Native::fakeFragmentSource();

    $first = Native::visit('/promos/1');
    answer($first, $body('Promo 1'));

    $second = Native::visit('/promos/2');
    answer($second, $body('Promo 2'));
    Closure::bind(fn () => $this->component->unmount(), $second, TestableComponent::class)();

    resume($first);

    // It asks again with its own state, so the answer reopens the stream
    // for /promos/1, and keeps what it had on screen meanwhile.
    $source->assertRequested('screen', 3);
    expect($source->lastRequest('screen')->state)->toMatchArray(['id' => '1']);
    $first->assertSee('Promo 1');

    answer($first, $body('Promo 1 again'));
    expect(ServerRegion::push('promos.show', 'screen', $body('Pushed to 1')))->toBe(1);
    repaint($first)->assertSee('Pushed to 1');
});

it('leaves a resumed screen alone when its stream still has its state', function () {
    $source = Native::fakeFragmentSource();

    $first = Native::visit('/promos/1');
    answer($first, PROMO_BODY);

    resume($first);

    $source->assertRequested('screen', 1);
});

it('only takes the stream back on the frame after a resume', function () {
    $source = Native::fakeFragmentSource();

    $first = Native::visit('/promos/1');
    answer($first, PROMO_BODY);
    $second = Native::visit('/promos/2');
    answer($second, PROMO_BODY);

    repaint($first);

    $source->assertRequested('screen', 2);
});

it('paints a push on every screen that asked with the same state', function () {
    $first = Native::visit('/promos/7');
    $second = Native::visit('/promos/7');

    expect(ServerRegion::push('promos.show', 'screen', PROMO_BODY))->toBe(2);

    repaint($first)->assertSee('Promo 42');
    repaint($second)->assertSee('Promo 42');
});

// ── Navigation slots in fragments ───────────────────

it('navigates from a fragment to an app route or a server route', function (string $uri, string $mode) {
    $screen = Native::visit('/promos/42');
    $slot = ['navigate' => $uri] + ($mode === 'replace' ? ['replace' => true] : []);

    answer($screen, ['type' => 'pressable', 'on_press' => $slot, 'children' => [['type' => 'text', 'props' => ['text' => 'Go']]]])
        ->tap('Go');

    $mode === 'replace' ? $screen->assertReplacedWith($uri) : $screen->assertNavigatedTo($uri);
})->with([
    'an app route' => ['/', 'navigate'],
    'a server route' => ['/promos/9', 'navigate'],
    'a server route with a query' => ['/inbox?unread=1', 'replace'],
]);

it('goes back from a fragment', function () {
    $screen = Native::visit('/promos/42');

    answer($screen, ['type' => 'pressable', 'on_press' => ['back' => true, 'transition' => 'slide_from_left'], 'children' => [['type' => 'text', 'props' => ['text' => 'Back']]]])
        ->tap('Back')
        ->assertWentBack();
});

it('goes nowhere for a route the app does not know', function () {
    $screen = Native::visit('/promos/42');

    answer($screen, ['type' => 'pressable', 'on_press' => ['navigate' => '/admin'], 'children' => [['type' => 'text', 'props' => ['text' => 'Go']]]])
        ->tap('Go')
        ->assertNoNavigation();
});

it('throws for an unknown route with app.debug on', function () {
    $screen = Native::visit('/promos/42');
    answer($screen, ['type' => 'pressable', 'on_press' => ['navigate' => '/admin'], 'children' => [['type' => 'text', 'props' => ['text' => 'Go']]]]);

    config(['app.debug' => true]);

    expect(fn () => $screen->tap('Go'))->toThrow(RuntimeException::class, 'not a route this app knows');
});

it('refuses navigation slots that are not app paths or not in a press slot', function (array $slot, string $name = 'on_press') {
    expect(FragmentCallbacks::parseSlot($slot, $reason, $name))->toBeNull()
        ->and($reason)->toBeString();
})->with([
    'a web url' => [['navigate' => 'https://evil.test']],
    'protocol relative' => [['navigate' => '//evil.test']],
    'relative' => [['navigate' => 'promos/1']],
    'whitespace' => [['navigate' => '/a b']],
    'a backslash' => [['navigate' => '/a\\b']],
    'extra keys' => [['navigate' => '/a', 'data' => ['x' => 1]]],
    'replace not bool' => [['navigate' => '/a', 'replace' => 1]],
    'unknown transition' => [['navigate' => '/a', 'transition' => 'spin']],
    'back false' => [['back' => false]],
    'back with a uri' => [['back' => true, 'navigate' => '/a']],
    'on change' => [['navigate' => '/a'], 'on_change'],
    'on submit' => [['back' => true], 'on_submit'],
    'the canonical form on the wire' => [['call' => 'navigate', 'args' => ['/a', null], 'target' => 'navigate']],
]);

it('reads a navigation expression back exactly', function () {
    $slot = FragmentCallbacks::parseSlot(['navigate' => '/a?b=1', 'replace' => true, 'transition' => 'fade']);
    $registry = new FragmentCallbacks('server:screen');
    $id = $registry->register(FragmentCallbacks::expressionFor($slot));

    expect($registry->resolve($id))->toBe(['method' => 'replace', 'args' => ['/a?b=1', 'fade'], 'target' => 'navigate']);
});
