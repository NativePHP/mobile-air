<?php

use Illuminate\Support\Carbon;
use Native\Mobile\Edge\Asgard\ComponentAddress;
use Native\Mobile\Edge\Asgard\ServerMethods;
use Native\Mobile\Edge\Asgard\ServerRegion;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Fixtures\Edge\RegionFailureScreen;
use Tests\Fixtures\Edge\ServerMethodScreen;

/**
 * Batched region requests, regions an answer carries inline, and refresh
 * hints (docs/ASGARD-PROTOCOL.md §3.1, §10.2, §10.5).
 *
 * RegionFailureScreen draws two regions, `stats` and `feed`.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
    ServerRegion::flush();
    ServerMethods::flush();
    config(['app.debug' => false, 'nativephp.asgard.region_timeout' => 15, 'nativephp.asgard.action_timeout' => 15]);
    Carbon::setTestNow(Carbon::now());
});

afterEach(function () {
    ServerRegion::flush();
    ServerMethods::flush();
    Carbon::setTestNow();
});

function plainText(string $text): array
{
    return ['type' => 'text', 'props' => ['text' => $text]];
}

function regionPart(string $text, ?string $version = null): array
{
    return ['fragment' => plainText($text), ...($version === null ? [] : ['version' => $version])];
}

/** Draw the next frame, as the runloop does once something asks for one. */
function drawn(TestableComponent $screen): TestableComponent
{
    return Closure::bind(function () {
        $this->startInteraction();

        return $this->afterInteraction();
    }, $screen, TestableComponent::class)();
}

/** A RegionFailureScreen whose two regions have their first answers. */
function answeredScreen(string $stats = 'Stats 1', string $feed = 'Feed 1'): TestableComponent
{
    $source = Native::fakeFragmentSource();
    $screen = Native::test(RegionFailureScreen::class);

    expect($source->respondToBatch(fragments: ['stats' => regionPart($stats, 'v1'), 'feed' => regionPart($feed, 'v1')]))->toBe(2);

    return drawn($screen);
}

function failureScreenAddress(): string
{
    return ComponentAddress::for(new RegionFailureScreen);
}

// ── Batching ────────────────────────────────────────

it('asks for every region a screen draws in one batch', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::test(RegionFailureScreen::class);

    $source->assertBatched(['stats', 'feed'], 1);
    expect($source->requests())->toHaveCount(2)
        ->and($source->lastBatch()->toArray())->toMatchArray(['component' => failureScreenAddress(), 'regions' => ['stats', 'feed']])
        ->and($source->requests('stats')[0]->state)->toBe($source->requests('feed')[0]->state);

    expect($source->respondToBatch(fragments: ['stats' => regionPart('Stats 1', 'v1'), 'feed' => regionPart('Feed 1', 'v2')]))->toBe(2);

    drawn($screen)->assertSee('Stats 1')->assertSee('Feed 1');

    expect(ServerRegion::subscriptions()[0]['versions'])->toBe(['feed' => 'v2', 'stats' => 'v1']);
});

it('sends one region on its own and all of them as a batch', function () {
    $source = Native::fakeFragmentSource();
    $screen = answeredScreen();

    $screen->tap('Retry');
    $source->assertBatched(['stats', 'feed'], 1)->assertRequested('stats', 2)->assertRequested('feed', 1);

    $screen->instance()->refreshServerRegions();
    drawn($screen);
    $source->assertBatched(['stats', 'feed'], 2);
});

it('fails only the region a batch answer left out', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::test(RegionFailureScreen::class);

    expect($source->respondToBatch(fragments: ['stats' => regionPart('Stats 1')]))->toBe(1);

    $screen->wait()->assertSee('Stats 1')->assertSee('Loading feed…');

    expect($screen->get('failures'))->toHaveCount(1)
        ->and($screen->get('failures')[0])->toMatchArray(['region' => 'feed', 'reason' => 'invalid', 'placeholder' => true]);
});

it('fires ServerRegionFailed for each region of a failed batch', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::test(RegionFailureScreen::class);

    expect($source->failBatch(reason: ServerRegion::SERVER, status: 503))->toBe(2);

    $screen->wait();

    expect(array_column($screen->get('failures'), 'region'))->toBe(['stats', 'feed'])
        ->and(array_unique(array_column($screen->get('failures'), 'status')))->toBe([503])
        ->and($screen->get('statsFailures'))->toBe([true]);
});

it('keeps a timer for each region of a batch', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::test(RegionFailureScreen::class);

    // One region of the batch is answered on its own, the other never is.
    $source->respond('stats', plainText('Stats 1'));

    $screen->wait(16)->assertSee('Stats 1');

    expect(array_column($screen->get('failures'), 'region'))->toBe(['feed'])
        ->and($screen->get('failures')[0]['reason'])->toBe('timeout');
});

it('drops the part of a batch a newer request superseded', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::test(RegionFailureScreen::class);
    $batch = $source->lastBatch();

    $screen->tap('Retry');

    expect($source->respondToBatch($batch, ['stats' => regionPart('Old stats'), 'feed' => regionPart('Feed 1')]))->toBe(1);

    drawn($screen)->assertSee('Feed 1')->assertDontSee('Old stats');
});

// ── Inline fragments on an action ───────────────────

it('paints the other regions an action answer carries on the owner that sent it', function () {
    $source = Native::fakeFragmentSource();
    $screen = answeredScreen();
    $other = answeredScreen('Other stats', 'Other feed');

    $action = ServerRegion::action($screen->instance(), 'stats', 'bump', []);

    expect($source->respondToAction($action, plainText('Stats 2'), [
        'feed' => regionPart('Feed 2', 'v7'),
        // Never drawn here, so it goes nowhere.
        'sidebar' => regionPart('Sidebar'),
    ]))->toBeTrue();

    drawn($screen)->assertSee('Stats 2')->assertSee('Feed 2');
    drawn($other)->assertSee('Other feed')->assertDontSee('Feed 2');

    expect(ServerRegion::hasFragment('sidebar', $screen->instance()))->toBeFalse();

    // The two share a subscription, so with the other gone its tokens show.
    // The action's own answer carried no version here.
    $other->instance()->unmount();
    unset($other);

    expect(ServerRegion::subscriptions()[0]['versions'])->toBe(['feed' => 'v7']);
});

it('leaves a region alone when a newer request for it went out after the action', function () {
    $source = Native::fakeFragmentSource();
    $screen = answeredScreen();

    $action = ServerRegion::action($screen->instance(), 'stats', 'bump', []);
    $screen->instance()->refreshServerRegions('feed');
    drawn($screen);

    $source->respondToAction($action, plainText('Stats 2'), ['feed' => regionPart('Feed from the action')]);
    drawn($screen)->assertSee('Feed 1')->assertDontSee('Feed from the action');

    $source->respond('feed', plainText('Feed 3'));
    drawn($screen)->assertSee('Feed 3');
});

it('supersedes an older request for a region it paints', function () {
    $source = Native::fakeFragmentSource();
    $screen = answeredScreen();

    $screen->instance()->refreshServerRegions('feed');
    drawn($screen);
    $older = $source->lastRequest('feed');

    $action = ServerRegion::action($screen->instance(), 'stats', 'bump', []);
    $source->respondToAction($action, plainText('Stats 2'), ['feed' => regionPart('Feed 2')]);

    expect($source->respond($older, plainText('Stale feed')))->toBeFalse();

    // Nor does the older request's timer fire.
    $screen->wait(16)->assertSee('Feed 2');
    expect($screen->get('failures'))->toBe([]);
});

it('refuses a bad inline fragment without stopping the action\'s own', function () {
    config(['app.debug' => true]);

    $source = Native::fakeFragmentSource();
    $screen = answeredScreen();

    $action = ServerRegion::action($screen->instance(), 'stats', 'bump', []);

    expect($source->respondToAction($action, plainText('Stats 2'), ['feed' => ['fragment' => ['type' => 'holographic_chart']]]))->toBeTrue();

    drawn($screen)->assertSee('Stats 2')->assertSee('Feed 1');
});

it('paints a #[Server] call\'s regions and reports the ones it did not', function () {
    $server = Native::fakeServerTransport();
    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    $server->resolve(fragments: [
        'sales-summary' => regionPart('Revenue', 'v3'),
        'chart' => regionPart('Never drawn'),
    ]);

    drawn($screen)->assertSee('Revenue');

    expect($screen->get('refusedFragments'))->toBe(['chart'])
        ->and(ServerRegion::subscriptions()[0]['versions'])->toBe(['sales-summary' => 'v3']);
});

it('drops a #[Server] call\'s region when a newer request for it went out', function () {
    $server = Native::fakeServerTransport();
    $source = Native::fakeFragmentSource();
    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    $screen->instance()->refreshServerRegions('sales-summary');
    drawn($screen);

    $server->resolve(fragments: ['sales-summary' => regionPart('From the call')]);

    expect($screen->get('refusedFragments'))->toBe(['sales-summary']);

    $source->respond('sales-summary', plainText('From the render'));
    drawn($screen)->assertSee('From the render');
});

// ── Hints ───────────────────────────────────────────

it('marks every live owner that drew the region, and each asks with its own state', function () {
    $source = Native::fakeFragmentSource();
    $first = answeredScreen();
    $second = answeredScreen();
    $second->instance()->timeout = 9.0;

    expect(ServerRegion::hint(failureScreenAddress(), 'stats'))->toBe(2);

    drawn($first);
    $source->assertRequested('stats', 3);

    // The second is not drawn yet, as a covered screen would not be.
    drawn($second);
    $source->assertRequested('stats', 4);

    expect($source->lastRequest('stats')->state['timeout'])->toBe(9.0)
        ->and($source->requests('stats')[2]->state['timeout'])->toBeNull();
});

it('marks every region an owner drew when the hint names none, and batches them', function () {
    $source = Native::fakeFragmentSource();
    $screen = answeredScreen();

    expect(ServerRegion::hint(failureScreenAddress()))->toBe(2);

    drawn($screen);
    $source->assertBatched(['stats', 'feed'], 2);
});

it('ignores hints for regions nobody drew, other components and closed screens', function () {
    $first = answeredScreen();
    $second = answeredScreen();

    expect(ServerRegion::hint(failureScreenAddress(), 'sidebar'))->toBe(0)
        ->and(ServerRegion::hint('app.native.screens.other', 'stats'))->toBe(0);

    $second->instance()->unmount();

    expect(ServerRegion::hint(failureScreenAddress(), 'stats'))->toBe(1);
});

it('collapses many hints and signals for one region into one fetch', function () {
    $source = Native::fakeFragmentSource();
    $screen = answeredScreen();
    $state = $source->lastRequest('stats')->state;

    // The version it holds already: nothing to fetch.
    expect(ServerRegion::signal(failureScreenAddress(), $state, 'stats', 'v1'))->toBe(0);

    ServerRegion::hint(failureScreenAddress(), 'stats');
    ServerRegion::hint(failureScreenAddress(), 'stats');
    ServerRegion::hints([['component' => failureScreenAddress(), 'region' => 'stats'], ['component' => failureScreenAddress(), 'region' => 'stats']]);
    expect(ServerRegion::signal(failureScreenAddress(), $state, 'stats', 'v2'))->toBe(1);

    drawn($screen);
    $source->assertRequested('stats', 2)->assertRequested('feed', 1);

    drawn($screen);
    $source->assertRequested('stats', 2);
});

it('skips hints of the wrong shape', function () {
    $screen = answeredScreen();

    expect(ServerRegion::hints(['stats', ['region' => 'stats'], ['component' => failureScreenAddress(), 'region' => 3], ['component' => failureScreenAddress(), 'region' => 'feed']]))->toBe(1);
});

it('takes hints from an action answer and from a #[Server] call answer', function () {
    $source = Native::fakeFragmentSource();
    $server = Native::fakeServerTransport();
    $screen = answeredScreen();

    $action = ServerRegion::action($screen->instance(), 'stats', 'bump', []);
    $source->respondToAction($action, plainText('Stats 2'), refresh: [['component' => failureScreenAddress(), 'region' => 'feed']]);
    drawn($screen);
    $source->assertRequested('feed', 2);

    $caller = Native::test(ServerMethodScreen::class)->tap('Refresh');
    $server->resolve(refresh: [['component' => failureScreenAddress()]]);

    drawn($screen);
    $source->assertBatched(['stats', 'feed'], 2);
});
