<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Native\Mobile\Edge\Asgard\ServerMethods;
use Native\Mobile\Edge\Asgard\ServerRegion;
use Native\Mobile\Edge\Asgard\ServerScreen;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Events\Asgard\ServerActionFailed;
use Native\Mobile\Events\Asgard\ServerRegionFailed;
use Native\Mobile\Events\Asgard\ServerScreenFailed;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use Tests\Fixtures\Edge\ActionFailureScreen;
use Tests\Fixtures\Edge\FragmentSourceChild;
use Tests\Fixtures\Edge\RegionFailureScreen;
use Tests\Fixtures\Edge\ScreenFailureLanding;
use Tests\Fixtures\Edge\ServerMethodFailureScreen;

/**
 * Timeouts and failures for server screens, server actions and #[Server]
 * methods (docs/ASGARD-PROTOCOL.md §10.2, §12.1). A server screen whose
 * body does not come gives up and leaves, and ServerScreenFailed fires on
 * the screen it lands on. A server action or #[Server] call that fails
 * fires ServerActionFailed on its own screen, and an @server region whose
 * fragment does not come fires ServerRegionFailed there. All are heard by
 * #[On].
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
    NativeRouter::clearRoutes();
    ServerRegion::flush();
    ServerMethods::flush();
    ServerScreen::flushChrome();
    config(['app.debug' => false, 'nativephp.asgard.screen_timeout' => 15, 'nativephp.asgard.action_timeout' => 15, 'nativephp.asgard.region_timeout' => 15]);
    Carbon::setTestNow(Carbon::now());
    Native::fakeFragmentSource();

    NativeRouter::register('/', ScreenFailureLanding::class);
    NativeRouter::registerServerRoutes([['uri' => '/promos/{id}', 'screen' => 'promos.show']]);
});

afterEach(function () {
    NativeRouter::clearRoutes();
    ServerRegion::flush();
    ServerMethods::flush();
    ServerScreen::flushChrome();
    Carbon::setTestNow();
});

const TIMEOUT_BODY = ['type' => 'text', 'key' => 'title', 'props' => ['text' => 'Promo 42']];

/** Open a server screen on top of the landing screen, as a flow. */
function promoOverHome(): array
{
    $home = Native::visit('/');
    $home->instance()->navigate('/promos/42');
    $promo = $home->follow();

    // The harness gives each screen a router of its own. Put the landing
    // screen under this one, as the device's stack would have it.
    $router = Closure::bind(fn () => $this->nativeRouter, $promo->instance(), NativeComponent::class)();
    Closure::bind(function () use ($home) {
        array_unshift($this->stack, ['component' => $home->instance(), 'uri' => '/', 'params' => []]);
    }, $router, NativeRouter::class)();

    return [$home, $promo];
}

/** The wait the runloop would block for, in ms. */
function loopTimeout(NativeComponent $screen): int
{
    return (new ReflectionMethod(NativeComponent::class, 'nextEventTimeout'))->invoke($screen);
}

/** A pressable fragment node with a server action. */
function serverButton(string $call, string $label): array
{
    return [
        'type' => 'pressable',
        'key' => $call,
        'on_press' => ['call' => $call, 'args' => [], 'target' => 'server'],
        'children' => [['type' => 'text', 'props' => ['text' => $label]]],
    ];
}

function actionScreen(?float $timeout = null): TestableComponent
{
    $screen = Native::test(ActionFailureScreen::class);
    $screen->set('timeout', $timeout);

    expect(ServerRegion::applyFragment('picks', ['type' => 'column', 'children' => [
        serverButton('like', 'Like'),
        serverButton('shuffle', 'Shuffle'),
    ]], $screen->instance()))->toBeTrue();

    return $screen->set('range', '7d');
}

// ── Server screens ──────────────────────────────────

it('gives up on a body that does not come in time, goes back and fires the event there', function () {
    $heard = [];
    Event::listen(ServerScreenFailed::class, function ($e) use (&$heard) {
        $heard[] = $e;
    });

    [, $promo] = promoOverHome();

    $promo->wait(14.9)->assertNoNavigation();

    $promo->wait(0.2)->assertWentBack();

    // Fired once the screen below is live, so app listeners get it then.
    expect($heard)->toBe([]);

    $home = $promo->goBack();

    expect($heard)->toHaveCount(1)
        ->and($heard[0]->uri)->toBe('/promos/42')
        ->and($heard[0]->address)->toBe('promos.show')
        ->and($heard[0]->reason)->toBe('timeout')
        ->and($heard[0]->status)->toBeNull();

    expect($home->get('heard'))->toBe([['uri' => '/promos/42', 'address' => 'promos.show', 'reason' => 'timeout', 'status' => null]])
        ->and($home->get('timeouts'))->toBe(['promos.show']);

    // Heard as a native event is: the screen drew a frame for it.
    $home->assertSee('Gave up on promos.show: timeout');
});

it('gives up at once on a hard failure, without waiting for the timer', function () {
    [, $promo] = promoOverHome();
    $source = Native::fakeFragmentSource();

    expect($source->fail('screen', ServerRegion::SERVER, 503))->toBeTrue();

    $promo->wait()->assertWentBack();

    $home = $promo->goBack();

    // The `when` filter for timeouts does not match, the catch-all does.
    expect($home->get('heard'))->toBe([['uri' => '/promos/42', 'address' => 'promos.show', 'reason' => 'server', 'status' => 503]])
        ->and($home->get('timeouts'))->toBe([]);
});

it('gives up on a body that cannot be rendered', function (bool $debug) {
    config(['app.debug' => $debug]);
    [, $promo] = promoOverHome();
    $source = Native::fakeFragmentSource();

    expect(ServerRegion::deliver($source->lastRequest('screen'), ['type' => 'no_such_element']))->toBeFalse();

    $promo->wait()->assertWentBack();

    expect($promo->goBack()->get('heard')[0]['reason'])->toBe('invalid');
})->with(['debug off' => false, 'debug on' => true]);

it('replaces itself with / when there is no screen below', function () {
    $promo = Native::visit('/promos/42');
    Native::fakeFragmentSource()->fail('screen', ServerRegion::NETWORK);

    $promo->wait()->assertReplacedWith('/');

    $home = $promo->follow();

    expect($home->get('heard'))->toBe([['uri' => '/promos/42', 'address' => 'promos.show', 'reason' => 'network', 'status' => null]]);
});

it('stops the timer once the body lands, and keeps the body when a refresh fails', function () {
    [, $promo] = promoOverHome();
    $source = Native::fakeFragmentSource();

    $source->respond('screen', TIMEOUT_BODY);
    $promo->wait()->assertSee('Promo 42');

    expect(ServerRegion::awaitingBody($promo->instance()))->toBeFalse();

    $promo->wait(60)->assertNoNavigation();

    ServerRegion::refresh($promo->instance());
    $promo->wait();
    $source->fail('screen', ServerRegion::SERVER, 500);

    $promo->wait()->assertNoNavigation()->assertSee('Promo 42');
});

it('stops the timer when the screen is unmounted', function () {
    $promo = Native::visit('/promos/42');
    $screen = $promo->instance();

    expect(ServerRegion::awaitingBody($screen))->toBeTrue();

    $screen->unmount();

    Carbon::setTestNow(Carbon::now()->addSeconds(30));

    expect(ServerRegion::awaitingBody($screen))->toBeFalse()
        ->and(ServerRegion::hasDeadlines())->toBeFalse()
        ->and(ServerRegion::expire([$screen]))->toBeFalse()
        ->and(ServerScreen::takeFailures())->toBe([]);
});

it('drops an answer that lands after the screen gave up', function () {
    [, $promo] = promoOverHome();
    $source = Native::fakeFragmentSource();
    $request = $source->lastRequest('screen');

    $promo->wait(16)->assertWentBack();

    expect(ServerRegion::deliver($request, TIMEOUT_BODY))->toBeFalse()
        ->and(ServerRegion::failed($request, ServerRegion::SERVER, 500))->toBeFalse();
});

it('wakes the runloop at the deadline, with no input', function () {
    $promo = Native::visit('/promos/42');
    $screen = $promo->instance();

    // No polls on this screen: without the timer the loop would block for ever.
    expect(loopTimeout($screen))->toBe(15000);

    Carbon::setTestNow(Carbon::now()->addSeconds(10));
    expect(loopTimeout($screen))->toBe(5000);

    Carbon::setTestNow(Carbon::now()->addSeconds(6));
    expect(loopTimeout($screen))->toBe(0);
});

it('does not wake a screen for the timer of a screen under it', function () {
    $covered = Native::visit('/promos/42')->instance();
    $top = Native::test(ScreenFailureLanding::class)->instance();

    expect(ServerRegion::nextDeadline([$covered]))->not->toBeNull()
        ->and(ServerRegion::nextDeadline([$top]))->toBeNull()
        ->and(loopTimeout($top))->toBe(-1);

    Carbon::setTestNow(Carbon::now()->addSeconds(30));

    expect(ServerRegion::expire([$top]))->toBeFalse()
        ->and(ServerRegion::awaitingBody($covered))->toBeTrue();
});

it('takes its timeout from the screen class', function () {
    $short = new class extends ServerScreen
    {
        public function screenTimeout(): float
        {
            return 2;
        }
    };

    config(['nativephp.asgard.screen' => $short::class]);
    NativeRouter::registerServerRoutes([['uri' => '/promos/{id}', 'screen' => 'promos.show']]);

    expect(NativeRouter::resolve('/promos/1')['class'])->toBe($short::class);

    $promo = Native::visit('/promos/1');

    expect($promo->instance())->toBeInstanceOf($short::class);

    $promo->wait(1.5)->assertNoNavigation();
    $promo->wait(1)->assertReplacedWith('/');
});

it('ignores a screen class that is not a ServerScreen', function () {
    config(['nativephp.asgard.screen' => ScreenFailureLanding::class]);

    expect(ServerScreen::screenClass())->toBe(ServerScreen::class);
});

it('waits for ever with a timeout of 0, but still gives up on a failure', function () {
    config(['nativephp.asgard.screen_timeout' => 0]);
    $promo = Native::visit('/promos/42');

    $promo->wait(3600)->assertNoNavigation();

    Native::fakeFragmentSource()->fail('screen', ServerRegion::NETWORK);

    $promo->wait()->assertReplacedWith('/');
});

// ── Server actions ──────────────────────────────────

it('fires ServerActionFailed on its screen when an action times out', function () {
    $heard = [];
    Event::listen(ServerActionFailed::class, function ($e) use (&$heard) {
        $heard[] = $e;
    });

    $screen = actionScreen()->tap('Like');
    $action = Native::fakeFragmentSource()->lastAction('picks');

    $screen->wait(14)->assertNotSet('likeFailures', ['timeout']);
    expect($screen->get('failures'))->toBe([]);

    $before = $screen->renderCount();
    $screen->wait(2);

    expect($screen->get('failures'))->toBe([[
        'action' => 'like',
        'component' => 'tests.fixtures.edge.action_failure_screen',
        'region' => 'picks',
        'reason' => 'timeout',
        'status' => null,
        'requestId' => $action->id,
    ]])->and($screen->get('likeFailures'))->toBe(['timeout']);

    expect($screen->renderCount())->toBeGreaterThan($before);
    expect($heard)->toHaveCount(1)->and($heard[0]->action)->toBe('like');

    // Late: dropped.
    expect(Native::fakeFragmentSource()->respondToAction($action, ['type' => 'text', 'props' => ['text' => 'Liked']]))->toBeFalse();
    $screen->assertDontSee('Liked');
});

it('fires at once for a failed action, and the when filter picks the listener', function () {
    $screen = actionScreen()->tap('Shuffle');

    expect(Native::fakeFragmentSource()->failAction('picks', ServerRegion::SERVER, 500, 'boom'))->toBeTrue();

    $screen->wait();

    expect($screen->get('failures'))->toHaveCount(1)
        ->and($screen->get('failures')[0]['action'])->toBe('shuffle')
        ->and($screen->get('failures')[0]['reason'])->toBe('server')
        ->and($screen->get('failures')[0]['status'])->toBe(500)
        ->and($screen->get('likeFailures'))->toBe([]);
});

it('fails an action whose answer cannot be rendered', function () {
    $screen = actionScreen()->tap('Like');

    expect(Native::fakeFragmentSource()->respondToAction('picks', ['type' => 'no_such_element']))->toBeFalse();

    $screen->wait();

    expect($screen->get('likeFailures'))->toBe(['invalid']);
});

it('fails an action whose source throws, as a network failure', function () {
    app()->instance(\Native\Mobile\Edge\Asgard\Contracts\FragmentSource::class, new class extends \Native\Mobile\Edge\Asgard\NullFragmentSource
    {
        public function action(\Native\Mobile\Edge\Asgard\ActionRequest $request): void
        {
            throw new RuntimeException('offline');
        }
    });

    $screen = Native::test(ActionFailureScreen::class);
    ServerRegion::applyFragment('picks', serverButton('like', 'Like'), $screen->instance());

    $screen->set('range', '30d')->tap('Like')->wait();

    expect($screen->get('likeFailures'))->toBe(['network']);
});

it('takes the action timeout from the component', function () {
    $screen = actionScreen(timeout: 2)->tap('Like');

    $screen->wait(1.9);
    expect($screen->get('likeFailures'))->toBe([]);

    $screen->wait(0.2);
    expect($screen->get('likeFailures'))->toBe(['timeout']);
});

it('reports nothing for a superseded action', function () {
    $screen = actionScreen()->tap('Like')->tap('Shuffle');

    $screen->wait(16);

    expect(array_column($screen->get('failures'), 'action'))->toBe(['shuffle'])
        ->and($screen->get('likeFailures'))->toBe([]);
});

// ── Server regions ──────────────────────────────────

const STATS_FRAGMENT = ['type' => 'text', 'key' => 'total', 'props' => ['text' => 'Total £120']];

function regionScreen(?float $timeout = null): TestableComponent
{
    ComponentRegistry::components(['fragment-source-child' => FragmentSourceChild::class]);

    $screen = Native::test(RegionFailureScreen::class);

    if ($timeout !== null) {
        // The first fetch went out with the config's timer. Ask again with this one.
        $screen->set('timeout', $timeout);
        $screen->instance()->refreshServerRegions();
        $screen->set('timeout', $timeout);
    }

    return $screen;
}

it('fires ServerRegionFailed when a region gets no fragment in time, with the placeholder still up', function () {
    $heard = [];
    Event::listen(ServerRegionFailed::class, function ($e) use (&$heard) {
        $heard[] = $e;
    });

    $screen = regionScreen();
    $source = Native::fakeFragmentSource();
    $stats = $source->lastRequest('stats');
    $source->respond('feed', ['type' => 'text', 'props' => ['text' => 'Feed']]);

    $screen->wait(14.9);
    expect($screen->get('failures'))->toBe([]);

    $before = $screen->renderCount();
    $screen->wait(0.2);

    expect($screen->get('failures'))->toBe([[
        'region' => 'stats',
        'component' => $stats->component,
        'reason' => 'timeout',
        'status' => null,
        'requestId' => $stats->id,
        'placeholder' => true,
    ]])->and($screen->get('statsFailures'))->toBe([true]);

    expect($screen->renderCount())->toBeGreaterThan($before);
    expect($heard)->toHaveCount(1)
        ->and($heard[0]->message)->toContain('no answer within 15');

    // Late: dropped, and the placeholder stays.
    expect($source->respond($stats, STATS_FRAGMENT))->toBeFalse();
    $screen->wait()->assertSee('Loading stats…')->assertDontSee('Total £120');
});

it('wakes the runloop for a region timer', function () {
    $screen = regionScreen();

    expect(loopTimeout($screen->instance()))->toBe(15000);

    Native::fakeFragmentSource()->respond('stats', STATS_FRAGMENT);
    Native::fakeFragmentSource()->respond('feed', STATS_FRAGMENT);
    $screen->wait();

    // Both answered: nothing left to wake for.
    expect(loopTimeout($screen->instance()))->toBe(-1);
});

it('keeps the fragment when a refresh fails, and says so', function () {
    $screen = regionScreen();
    $source = Native::fakeFragmentSource();

    $source->respond('stats', STATS_FRAGMENT);
    $source->respond('feed', ['type' => 'text', 'props' => ['text' => 'Feed']]);
    $screen->wait()->assertSee('Total £120');

    $screen->tap('Retry');
    $source->assertRequested('stats', 2);

    expect($source->fail('stats', ServerRegion::SERVER, 500, 'boom'))->toBeTrue();

    $screen->wait()->assertSee('Total £120')->assertDontSee('Loading stats…');

    expect($screen->get('failures'))->toHaveCount(1)
        ->and($screen->get('failures')[0]['reason'])->toBe('server')
        ->and($screen->get('failures')[0]['status'])->toBe(500)
        ->and($screen->get('failures')[0]['placeholder'])->toBeFalse()
        ->and($screen->get('statsFailures'))->toBe([false]);
});

it('fires at once on a hard failure, and the when filter picks the listener', function () {
    $screen = regionScreen();

    expect(Native::fakeFragmentSource()->fail('feed', ServerRegion::NETWORK))->toBeTrue();

    $screen->wait();

    expect($screen->get('failures'))->toHaveCount(1)
        ->and($screen->get('failures')[0]['region'])->toBe('feed')
        ->and($screen->get('failures')[0]['reason'])->toBe('network')
        ->and($screen->get('failures')[0]['placeholder'])->toBeTrue()
        ->and($screen->get('statsFailures'))->toBe([]);

    Native::fakeFragmentSource()->fail('stats', ServerRegion::SERVER, 503);
    $screen->wait();

    expect($screen->get('statsFailures'))->toBe([true])
        ->and(array_column($screen->get('failures'), 'region'))->toBe(['feed', 'stats']);
});

it('fails a region whose fragment cannot be rendered', function () {
    $screen = regionScreen();

    expect(Native::fakeFragmentSource()->respond('stats', ['type' => 'no_such_element']))->toBeFalse();

    $screen->wait()->assertSee('Loading stats…');

    expect($screen->get('failures')[0]['reason'])->toBe('invalid');
});

it('still throws for a region fragment that cannot be rendered while debugging, and fires the event', function () {
    $screen = regionScreen();
    config(['app.debug' => true]);

    expect(fn () => Native::fakeFragmentSource()->respond('stats', ['type' => 'no_such_element']))
        ->toThrow(RuntimeException::class, "region 'stats' cannot be rendered");

    $screen->wait();

    expect($screen->get('statsFailures'))->toBe([true]);
});

it('fails a region whose source throws, as a network failure', function () {
    app()->instance(\Native\Mobile\Edge\Asgard\Contracts\FragmentSource::class, new class extends \Native\Mobile\Edge\Asgard\NullFragmentSource
    {
        public function request(\Native\Mobile\Edge\Asgard\RegionRequest $request): void
        {
            throw new RuntimeException('offline');
        }

        // The screen's two regions go out together.
        public function batch(\Native\Mobile\Edge\Asgard\RegionBatch $batch): void
        {
            throw new RuntimeException('offline');
        }
    });

    $screen = regionScreen()->wait();

    expect(array_column($screen->get('failures'), 'reason'))->toBe(['network', 'network']);
});

it('fires on the screen for a region in a child component', function () {
    $screen = regionScreen();
    $source = Native::fakeFragmentSource();
    $source->respond('stats', STATS_FRAGMENT);
    $source->respond('feed', STATS_FRAGMENT);

    $screen->set('withChild', true);
    $child = $source->lastRequest('sales-summary');

    expect($child->componentClass)->toBe(FragmentSourceChild::class);

    $screen->wait(16);

    expect($screen->get('failures'))->toBe([[
        'region' => 'sales-summary',
        'component' => $child->component,
        'reason' => 'timeout',
        'status' => null,
        'requestId' => $child->id,
        'placeholder' => true,
    ]]);
});

it('takes the region timeout from the component, and 0 turns it off', function () {
    $screen = regionScreen(timeout: 2);

    $screen->wait(1.9);
    expect($screen->get('failures'))->toBe([]);

    $screen->wait(0.2);
    expect(array_column($screen->get('failures'), 'region'))->toBe(['stats', 'feed']);

    config(['nativephp.asgard.region_timeout' => 0]);
    $off = regionScreen();

    $off->wait(3600);
    expect($off->get('failures'))->toBe([]);

    // A hard failure still fires.
    Native::fakeFragmentSource()->fail('stats');
    $off->wait();
    expect($off->get('statsFailures'))->toBe([true]);
});

it('reports nothing for a superseded region request', function () {
    $screen = regionScreen();
    $source = Native::fakeFragmentSource();
    $first = $source->lastRequest('stats');

    $screen->tap('Retry');
    $second = $source->lastRequest('stats');

    expect($second->id)->not->toBe($first->id)
        ->and($source->fail($first, ServerRegion::SERVER, 500))->toBeFalse();

    $source->respond($second, STATS_FRAGMENT);
    $source->respond('feed', STATS_FRAGMENT);

    $screen->wait(16)->assertSee('Total £120');

    expect($screen->get('failures'))->toBe([]);
});

it('does not ask again on its own after a failure, but a refresh retries it', function () {
    $screen = regionScreen();
    $source = Native::fakeFragmentSource();
    $source->respond('feed', STATS_FRAGMENT);

    $screen->wait(16);
    expect($screen->get('statsFailures'))->toBe([true]);

    // Frames after the failure leave it alone, so a dead server is not asked on every one.
    $screen->set('withChild', false)->wait()->wait();
    $source->assertRequested('stats', 1);

    $screen->tap('Retry');
    $source->assertRequested('stats', 2);

    expect($source->respond('stats', STATS_FRAGMENT))->toBeTrue();

    $screen->wait()->assertSee('Total £120')->assertDontSee('Loading stats…');

    // The retry has a timer of its own, which the answer stopped.
    $screen->wait(30);
    expect($screen->get('statsFailures'))->toBe([true]);
});

it('acts on a region timer of a covered screen only once it is on top again', function () {
    $covered = regionScreen()->instance();
    $top = Native::test(ScreenFailureLanding::class)->instance();

    Carbon::setTestNow(Carbon::now()->addSeconds(30));

    expect(ServerRegion::nextDeadline([$top]))->toBeNull()
        ->and(ServerRegion::expire([$top]))->toBeFalse()
        ->and(ServerRegion::nextDeadline([$covered]))->not->toBeNull()
        ->and(ServerRegion::expire([$covered]))->toBeTrue();
});

it('never fires ServerRegionFailed for a server screen body', function () {
    $heard = [];
    Event::listen(ServerRegionFailed::class, function ($e) use (&$heard) {
        $heard[] = $e;
    });

    // Timed out before the body came: the screen gives up, nothing else.
    [, $promo] = promoOverHome();
    $promo->wait(16)->assertWentBack();
    $promo->goBack();

    // Failed at once.
    [, $promo] = promoOverHome();
    Native::fakeFragmentSource()->fail('screen', ServerRegion::SERVER, 500);
    $promo->wait()->assertWentBack();

    // A refresh that fails after the body landed keeps the body.
    [, $promo] = promoOverHome();
    $source = Native::fakeFragmentSource();
    $source->respond('screen', TIMEOUT_BODY);
    ServerRegion::refresh($promo->instance());
    $promo->wait();

    expect(ServerRegion::nextDeadline([$promo->instance()]))->toBeNull();

    $source->fail('screen', ServerRegion::SERVER, 500);
    $promo->wait(60)->assertNoNavigation()->assertSee('Promo 42');

    expect($heard)->toBe([]);
});

// ── #[Server] methods ───────────────────────────────

it('times out a #[Server] call: the closure runs failed and the event fires', function () {
    $server = Native::fakeServerTransport();
    $screen = Native::test(ServerMethodFailureScreen::class)->tap('Refresh');
    $id = $server->lastId();

    $screen->wait(16);

    expect($screen->get('loading'))->toBeFalse()
        ->and($screen->get('completions'))->toBe(1)
        ->and($screen->get('error'))->toContain('No answer within 15')
        ->and($screen->get('failures'))->toBe([['action' => 'refreshChart', 'region' => null, 'reason' => 'timeout', 'status' => null, 'requestId' => $id]])
        ->and($screen->get('pingFailures'))->toBe([]);

    // Late: dropped, closure and all.
    expect($server->resolve($id, ['total' => '£1']))->toBeFalse();
    expect($screen->get('completions'))->toBe(1);
});

it('fires the event for a failed #[Server] answer, with a status when the server gave one', function () {
    $server = Native::fakeServerTransport();
    $screen = Native::test(ServerMethodFailureScreen::class)->tap('Refresh');

    expect($server->fail(error: 'Server error', code: 500))->toBeTrue();

    expect($screen->get('error'))->toBe('Server error')
        ->and($screen->get('failures')[0]['reason'])->toBe('server')
        ->and($screen->get('failures')[0]['status'])->toBe(500);
});

it('filters #[Server] failures by method with when', function () {
    $server = Native::fakeServerTransport();
    $screen = Native::test(ServerMethodFailureScreen::class);

    $screen->instance()->callServerMethod('ping');
    $server->fail();

    expect($screen->get('pingFailures'))->toBe(['network'])
        ->and($screen->get('failures')[0]['action'])->toBe('ping');
});

it('fires the event when a #[Server] call cannot be sent', function () {
    Native::fakeServerTransport()->failSending('offline');

    $screen = Native::test(ServerMethodFailureScreen::class)->tap('Refresh');

    expect($screen->get('error'))->toBe('offline')
        ->and($screen->get('failures')[0]['reason'])->toBe('network');
});

// ── The real runloop ────────────────────────────────

/**
 * A bridge with no user at all: a wait with a timeout lets that much time
 * pass and wakes empty, and a wait for ever shuts the app down, so the
 * router's session ends with the test.
 */
class IdleBridge extends \Native\Mobile\Testing\FakeBridge
{
    /** @var list<int> */
    public array $waits = [];

    public function elementWaitEvent(int $timeoutMs): ?array
    {
        $event = parent::elementWaitEvent($timeoutMs);

        if ($event !== null || $timeoutMs === 0) {
            return $event;
        }

        $this->waits[] = $timeoutMs;

        if ($timeoutMs > 0 && count($this->waits) < 20) {
            Carbon::setTestNow(Carbon::now()->addMilliseconds($timeoutMs));

            return null;
        }

        return ['type' => NativeComponent::EVENT_SHUTDOWN];
    }
}

it('wakes on its own at the deadline in the runloop, goes back, and the screen below hears it', function () {
    $bridge = new IdleBridge;
    app()->instance(\Native\Mobile\Testing\FakeBridge::class, $bridge);

    $router = new NativeRouter;
    $router->preloadStack([['uri' => '/']]);
    $home = Closure::bind(fn () => $this->stack[0]['component'], $router, NativeRouter::class)();

    $router->start(ServerScreen::class, ['id' => '42'], '/promos/42');

    // The server screen slept exactly until its deadline, with no input.
    expect($bridge->waits[0])->toBe(15000);

    expect($home->heard)->toBe([['uri' => '/promos/42', 'address' => 'promos.show', 'reason' => 'timeout', 'status' => null]]);
});
