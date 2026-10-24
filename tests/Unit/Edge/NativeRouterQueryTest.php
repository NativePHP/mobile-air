<?php

use Native\Mobile\Edge\Elements\BottomNav;
use Native\Mobile\Edge\Elements\BottomNavItem;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\NativeRouter;
use Tests\Fixtures\Edge\CounterScreen;
use Tests\Fixtures\Edge\DetailScreen;

/*
 * Routes match on the path. The query string comes off before matching and
 * rides back on the resolution, parsed, for the router to hand to the screen
 * as query(). Reported as #290: a warm OAuth callback reached its screen with
 * no code on it.
 */

beforeEach(fn () => NativeRouter::clearRoutes());
afterEach(fn () => NativeRouter::clearRoutes());

it('matches an exact route when the URI carries a query string', function () {
    NativeRouter::register('/auth/callback', CounterScreen::class);

    $resolved = NativeRouter::resolve('/auth/callback?authCode=example');

    expect($resolved)->not->toBeNull()
        ->and($resolved['class'])->toBe(CounterScreen::class)
        ->and($resolved['query'])->toBe(['authCode' => 'example']);
});

it('matches a pattern route and keeps route params and query apart', function () {
    NativeRouter::register('/detail/{id}', DetailScreen::class);

    $resolved = NativeRouter::resolve('/detail/7?id=99&from=deeplink');

    // A query parameter can't pose as a route segment.
    expect($resolved['params'])->toBe(['id' => '7'])
        ->and($resolved['query'])->toBe(['id' => '99', 'from' => 'deeplink']);
});

it('keeps repeated and bracketed parameters as arrays', function () {
    NativeRouter::register('/filter', CounterScreen::class);

    expect(NativeRouter::resolve('/filter?tag[]=alpha&tag[]=beta')['query'])
        ->toBe(['tag' => ['alpha', 'beta']]);
});

it('decodes the encoded query both shells send exactly once', function () {
    NativeRouter::register('/auth/callback', CounterScreen::class);

    // What iOS, and Android since #581, forward for
    // myapp://auth/callback?code=a%2Bb%3D%3D&state=x%26y
    expect(NativeRouter::resolve('/auth/callback?code=a%2Bb%3D%3D&state=x%26y')['query'])
        ->toBe(['code' => 'a+b==', 'state' => 'x&y']);
});

it('reports an empty query when the URI has none', function () {
    NativeRouter::register('/auth/callback', CounterScreen::class);

    expect(NativeRouter::resolve('/auth/callback')['query'])->toBe([]);
});

it('ignores a fragment, on its own or after a query', function () {
    NativeRouter::register('/faq', CounterScreen::class);

    expect(NativeRouter::resolve('/faq#billing')['query'])->toBe([])
        ->and(NativeRouter::resolve('/faq?open=1#billing')['query'])->toBe(['open' => '1']);
});

it('treats a bare query string as addressing the root route', function () {
    NativeRouter::register('/', CounterScreen::class);

    $resolved = NativeRouter::resolve('?ref=email');

    expect($resolved)->not->toBeNull()
        ->and($resolved['query'])->toBe(['ref' => 'email']);
});

it('answers isNativeRoute for a URI with a query string', function () {
    NativeRouter::register('/auth/callback', CounterScreen::class);

    expect(NativeRouter::isNativeRoute('/auth/callback?authCode=example'))->toBeTrue();
});

it('still resolves nothing for a path that is not registered', function () {
    NativeRouter::register('/auth/callback', CounterScreen::class);

    expect(NativeRouter::resolve('/auth/other?authCode=example'))->toBeNull();
});

it('drops parameters past max_input_vars instead of throwing', function () {
    NativeRouter::register('/auth/callback', CounterScreen::class);

    // Anyone can craft a link like this. Parsing it used to raise a warning
    // that Laravel turned into an exception inside the navigation loop.
    $limit = (int) ini_get('max_input_vars');
    $uri = '/auth/callback?'.implode('&', array_map(fn ($i) => "k$i=v", range(0, $limit)));

    expect(NativeRouter::resolve($uri)['query'])->toHaveCount($limit);
});

it('gives the path of a URI without its query or fragment', function (string $uri, string $path) {
    expect(NativeRouter::pathOf($uri))->toBe($path);
})->with([
    ['/detail/7?from=x', '/detail/7'],
    ['/detail/7/', '/detail/7'],
    ['detail/7', '/detail/7'],
    ['/faq#billing', '/faq'],
    ['/faq#a?b', '/faq'],
    ['?ref=email', '/'],
    ['/', '/'],
    ['', '/'],
]);

it('highlights the tab for the path when the current URI has a query', function () {
    $bar = queryTestTabBar()->highlight('/home?ref=push');

    expect(queryTestActiveTabs($bar))->toBe(['home']);
});

it('prefers a tab whose own URL carries the same query', function () {
    $bar = queryTestTabBar()->highlight('/remotes?tab=closed');

    expect(queryTestActiveTabs($bar))->toBe(['closed']);
});

function queryTestTabBar(): TabBar
{
    $nav = BottomNav::make();
    $nav->applyAttributes([]);

    foreach (['home' => '/home', 'remotes' => '/remotes', 'closed' => '/remotes?tab=closed'] as $id => $url) {
        $item = BottomNavItem::make();
        $item->applyAttributes(['id' => $id, 'label' => ucfirst($id), 'url' => $url]);
        $nav->addChild($item);
    }

    return TabBar::fromElement($nav);
}

/** @return list<string> */
function queryTestActiveTabs(TabBar $bar): array
{
    $ids = ['home', 'remotes', 'closed'];

    return array_values(array_map(
        fn (int $i) => $ids[$i],
        array_keys(array_filter($bar->tabElements(), fn ($item) => $item->isActive())),
    ));
}
