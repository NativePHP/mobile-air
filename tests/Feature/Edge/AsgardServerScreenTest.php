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

    expect(ServerRegion::push('promos.show', ['id' => '42'], 'screen', PROMO_BODY, ['nav_bar' => ['title' => 'Bad', 'colour' => 'red']]))->toBe(1);

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

// ── Titles before the body ──────────────────────────

/** Every nav bar title in the screen's current tree. */
function navTitles(TestableComponent $screen): array
{
    return Closure::bind(fn () => $this->navTitles($this->tree()), $screen, TestableComponent::class)();
}

it('keeps a route title template and drops a bad one with its route kept', function () {
    NativeRouter::registerServerRoutes([
        ['uri' => '/promos/{id}', 'screen' => 'promos.show', 'title' => 'Promo #{id}'],
        ['uri' => '/inbox', 'screen' => 'inbox', 'title' => "In\nbox"],
        ['uri' => '/deals', 'screen' => 'deals', 'title' => str_repeat('a', 101)],
        ['uri' => '/news', 'screen' => 'news', 'title' => ['News']],
    ]);

    expect(NativeRouter::resolve('/promos/42')['title'])->toBe('Promo #{id}')
        ->and(NativeRouter::resolve('/inbox')['title'])->toBeNull()
        ->and(NativeRouter::resolve('/deals')['title'])->toBeNull()
        ->and(NativeRouter::resolve('/news')['title'])->toBeNull();
});

it('shows the route title with its parameters filled in before any answer', function () {
    $source = Native::fakeFragmentSource();
    NativeRouter::registerServerRoutes([['uri' => '/promos/{id}', 'screen' => 'promos.show', 'title' => 'Promo #{id}']]);

    $screen = Native::visit('/promos/42');

    $screen->assertNavTitle('Promo #42');
    expect(ServerRegion::hasFragment('screen', $screen->instance()))->toBeFalse();
    $source->assertRequested('screen', times: 1);
});

it('removes placeholders with no route parameter, and never reads the query string', function () {
    NativeRouter::registerServerRoutes([
        ['uri' => '/tickets/{id?}', 'screen' => 'tickets', 'title' => 'Ticket {id} {ref}'],
    ]);

    expect(navTitles(Native::visit('/tickets?ref=mail&id=spoof')))->toBe(['Ticket']);
    expect(navTitles(Native::visit('/tickets/7?ref=mail')))->toBe(['Ticket 7']);
});

it('turns control characters in a parameter into spaces and caps the length', function () {
    NativeRouter::registerServerRoutes([['uri' => '/promos/{id}', 'screen' => 'promos.show', 'title' => 'Promo {id}']]);

    expect(navTitles(Native::visit('/promos/a%0Ab')))->toBe(['Promo a b']);
    expect(mb_strlen(navTitles(Native::visit('/promos/'.str_repeat('x', 200)))[0]))->toBe(ServerScreen::TITLE_MAX);
});

it('falls back to the placeholder when a title comes out blank', function () {
    NativeRouter::registerServerRoutes([['uri' => '/tickets/{id?}', 'screen' => 'tickets', 'title' => '{id}']]);

    expect(navTitles(Native::visit('/tickets')))->toBe(['Loading…']);
});

it('shows Loading… for a route with no title', function () {
    expect(navTitles(Native::visit('/promos/42')))->toBe(['Loading…']);
});

it('translates the default placeholder', function () {
    app('translator')->addLines(['*.Loading…' => 'Chargement…'], 'fr');
    app()->setLocale('fr');

    try {
        expect(navTitles(Native::visit('/promos/42')))->toBe(['Chargement…']);
    } finally {
        app()->setLocale('en');
    }
});

it('takes the placeholder from titlePlaceholderUsing(), with the screen uri', function () {
    ServerScreen::titlePlaceholderUsing(fn (string $uri) => "Opening {$uri}");

    expect(navTitles(Native::visit('/promos/42?ref=mail')))->toBe(['Opening /promos/42?ref=mail']);

    ServerScreen::titlePlaceholderUsing(null);

    expect(navTitles(Native::visit('/promos/42')))->toBe(['Loading…']);
});

it('takes the placeholder from a subclass', function () {
    $screen = new class extends ServerScreen
    {
        public function titlePlaceholder(): string
        {
            return 'One moment';
        }
    };

    config(['nativephp.asgard.screen' => $screen::class]);
    NativeRouter::registerServerRoutes([
        ['uri' => '/promos/{id}', 'screen' => 'promos.show'],
        ['uri' => '/deals/{id}', 'screen' => 'deals', 'title' => 'Deal {id}'],
    ]);

    expect(navTitles(Native::visit('/promos/42')))->toBe(['One moment'])
        ->and(navTitles(Native::visit('/deals/3')))->toBe(['Deal 3']);
});

it('does not borrow the title another screen of the route had', function () {
    $screen = Native::visit('/promos/42');
    answer($screen, PROMO_BODY, ['nav_bar' => ['title' => 'Promo #42', 'actions' => [['id' => 'like', 'on_press' => ['call' => 'like', 'args' => []]]]]]);

    $next = Native::visit('/promos/43');

    expect(navTitles($next))->toBe(['Loading…'])
        ->and(pressIdOf($next->tree(), 'top_bar_action', fn () => true))->toBeNull();
});

it('replaces the early title with the chrome title when the body lands', function () {
    NativeRouter::registerServerRoutes([
        ['uri' => '/promos/{id}', 'screen' => 'promos.show', 'title' => 'Promo #{id}'],
        ['uri' => '/inbox', 'screen' => 'inbox'],
    ]);
    $screen = Native::visit('/promos/42');

    expect(navTitles(answer($screen, PROMO_BODY, ['nav_bar' => ['title' => 'Summer sale']])))->toBe(['Summer sale']);

    $inbox = Native::visit('/inbox');

    expect(navTitles(answer($inbox, PROMO_BODY, ['nav_bar' => ['title' => 'Inbox']])))->toBe(['Inbox']);
});

it('keeps the route title, but never the placeholder, under chrome with no title', function () {
    NativeRouter::registerServerRoutes([
        ['uri' => '/promos/{id}', 'screen' => 'promos.show', 'title' => 'Promo #{id}'],
        ['uri' => '/inbox', 'screen' => 'inbox'],
    ]);

    $promo = Native::visit('/promos/42');
    expect(navTitles(answer($promo, PROMO_BODY, ['nav_bar' => ['back' => true]])))->toBe(['Promo #42']);

    $inbox = Native::visit('/inbox');
    expect(navTitles(answer($inbox, PROMO_BODY, ['nav_bar' => ['back' => true]])))->toBe([]);

    // A later answer without a title leaves it that way.
    expect(ServerRegion::push('inbox', [], 'screen', PROMO_BODY, ['nav_bar' => []]))->toBe(1);
    expect(navTitles(repaint($inbox)))->toBe([]);
});

it('drops the placeholder once a body lands without any chrome', function () {
    $inbox = Native::visit('/inbox');

    expect(navTitles($inbox))->toBe(['Loading…'])
        ->and(navTitles(answer($inbox, PROMO_BODY)))->toBe([]);
});

it('adds no nav bar to chrome that has none', function () {
    NativeRouter::registerServerRoutes([['uri' => '/promos/{id}', 'screen' => 'promos.show', 'title' => 'Promo #{id}']]);
    $screen = Native::visit('/promos/42');

    expect(navTitles(answer($screen, PROMO_BODY, [])))->toBe([]);
});

it('applies chrome pushed with a fragment', function () {
    $screen = Native::visit('/promos/42');

    expect(ServerRegion::push('promos.show', ['id' => '42'], 'screen', PROMO_BODY, ['nav_bar' => ['title' => 'Pushed']]))->toBe(1);

    repaint($screen)->assertNavTitle('Pushed')->assertSee('Promo 42');
});

it('keeps a pushed fragment off a screen of the same route with other parameters', function () {
    $body = fn (string $text) => ['type' => 'text', 'props' => ['text' => $text]];

    $first = Native::visit('/promos/1');
    answer($first, $body('Promo 1'), ['nav_bar' => ['title' => 'Promo #1']]);

    // The next screen up the stack is its own subscription, and the server
    // pushes its body down it.
    $second = Native::visit('/promos/2');
    expect(ServerRegion::push('promos.show', ['id' => '2'], 'screen', $body('Promo 2'), ['nav_bar' => ['title' => 'Promo #2']]))->toBe(1);

    repaint($second)->assertSee('Promo 2')->assertNavTitle('Promo #2');

    // Going back shows the screen underneath as it was, not a copy of the
    // one that just left.
    repaint($first)->assertSee('Promo 1')->assertDontSee('Promo 2')->assertNavTitle('Promo #1');
});

it('paints a push on every screen that asked with the same state', function () {
    $first = Native::visit('/promos/7');
    $second = Native::visit('/promos/7');

    expect(ServerRegion::push('promos.show', ['id' => '7'], 'screen', PROMO_BODY))->toBe(2);

    repaint($first)->assertSee('Promo 42');
    repaint($second)->assertSee('Promo 42');
});

// ── The push stream ─────────────────────────────────

it('keeps a covered screen of the same route on its own subscription', function () {
    $body = fn (string $text) => ['type' => 'text', 'props' => ['text' => $text]];
    $source = Native::fakeFragmentSource();

    $first = Native::visit('/promos/1');
    answer($first, $body('Promo 1'));
    $second = Native::visit('/promos/2');
    answer($second, $body('Promo 2'));

    // One stream, two subscriptions: one per state.
    expect(array_column($source->watching(), 'state'))->toEqualCanonicalizing([['id' => '1'], ['id' => '2']]);

    // Each push lands on its own screen only, the covered one included.
    expect(ServerRegion::push('promos.show', ['id' => '1'], 'screen', $body('Pushed to 1')))->toBe(1)
        ->and(ServerRegion::push('promos.show', ['id' => '2'], 'screen', $body('Pushed to 2')))->toBe(1);

    repaint($second)->assertSee('Pushed to 2')->assertDontSee('Pushed to 1');
    repaint($first)->assertSee('Pushed to 1')->assertDontSee('Pushed to 2');

    // Nothing was fetched again to make that work.
    $source->assertRequested('screen', 2);
});

it('fetches a signalled region again, keeping the fragment up meanwhile', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::visit('/promos/1');
    $source->respond('screen', PROMO_BODY, 'v1');
    repaint($screen);

    // The version it holds: nothing to do.
    expect(ServerRegion::signal('promos.show', ['id' => '1'], 'screen', 'v1'))->toBe(0);
    expect(ServerRegion::repaintPending())->toBeFalse();

    expect(ServerRegion::signal('promos.show', ['id' => '1'], 'screen', 'v2'))->toBe(1);
    expect(ServerRegion::repaintPending())->toBeTrue();

    repaint($screen)->assertSee('Promo 42');
    $source->assertRequested('screen', 2);

    $source->respond('screen', ['type' => 'text', 'props' => ['text' => 'Fresh']], 'v2');
    repaint($screen)->assertSee('Fresh');

    // Caught up, so the same signal again does nothing.
    expect(ServerRegion::signal('promos.show', ['id' => '1'], 'screen', 'v2'))->toBe(0);
});

it('fetches a covered screen\'s signalled region when it is drawn again', function () {
    $body = fn (string $text) => ['type' => 'text', 'props' => ['text' => $text]];
    $source = Native::fakeFragmentSource();

    $first = Native::visit('/promos/1');
    answer($first, $body('Promo 1'));
    $second = Native::visit('/promos/2');
    answer($second, $body('Promo 2'));

    // A signal for the screen underneath does not fetch for the one on top.
    expect(ServerRegion::signal('promos.show', ['id' => '1'], 'screen', 'v9'))->toBe(1);
    repaint($second);
    $source->assertRequested('screen', 2);

    // It asks, with its own state, once it is drawn again.
    repaint($first);
    $source->assertRequested('screen', 3);
    expect($source->lastRequest('screen')->state)->toMatchArray(['id' => '1']);
});

it('keeps getting its pushes after the screen above it pops', function () {
    $body = fn (string $text) => ['type' => 'text', 'props' => ['text' => $text]];
    $source = Native::fakeFragmentSource();

    $first = Native::visit('/promos/1');
    answer($first, $body('Promo 1'));
    $second = Native::visit('/promos/2');
    answer($second, $body('Promo 2'));

    Closure::bind(fn () => $this->component->unmount(), $second, TestableComponent::class)();
    ServerRegion::sync();

    expect($source->watching())->toHaveCount(1)
        ->and($source->watching()[0]['state'])->toBe(['id' => '1']);

    expect(ServerRegion::push('promos.show', ['id' => '1'], 'screen', $body('Pushed to 1')))->toBe(1)
        ->and(ServerRegion::push('promos.show', ['id' => '2'], 'screen', $body('Pushed to 2')))->toBe(0);
    repaint($first)->assertSee('Pushed to 1');
    $source->assertRequested('screen', 2);
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
