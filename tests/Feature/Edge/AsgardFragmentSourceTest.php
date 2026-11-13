<?php

use Native\Mobile\Edge\Asgard\ActionRequest;
use Native\Mobile\Edge\Asgard\ComponentAddress;
use Native\Mobile\Edge\Asgard\Contracts\FragmentSource;
use Native\Mobile\Edge\Asgard\NullFragmentSource;
use Native\Mobile\Edge\Asgard\RegionRequest;
use Native\Mobile\Edge\Asgard\ServerRegion;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Support\NativeCallbacks;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\FragmentSourceChild;
use Tests\Fixtures\Edge\FragmentSourceScreen;
use Tests\Fixtures\Edge\ServerRegionScreen;

/**
 * Asking for fragments (docs/ASGARD-PROTOCOL.md §3): a region that painted
 * its placeholder asks the bound FragmentSource once, refresh asks again,
 * and a delivery lands on the component that rendered the region and
 * repaints the screen.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
    ComponentRegistry::reset();
    ComponentRegistry::components(['fragment-source-child' => FragmentSourceChild::class]);
    ServerRegion::flush();
    NativeCallbacks::flush();
});

afterEach(function () {
    ComponentRegistry::reset();
    ServerRegion::flush();
    NativeCallbacks::flush();
});

const SUMMARY = ['type' => 'text', 'key' => 'total', 'props' => ['text' => 'Revenue: £12,400']];

// ── When a request goes out ─────────────────────────

it('requests a region once after the first frame shows its placeholder', function () {
    $source = Native::fakeFragmentSource();

    Native::test(FragmentSourceScreen::class)->assertSee('Loading sales…');

    $source->assertRequested('sales-summary', times: 1);
});

it('does not request again on later frames', function () {
    $source = Native::fakeFragmentSource();

    Native::test(FragmentSourceScreen::class)
        ->tap('tap')
        ->tap('tap')
        ->assertSee('Taps 2')
        ->assertSee('Loading sales…');

    $source->assertRequested('sales-summary', times: 1);
});

it('does not request a region that already holds a fragment', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class);
    $source->respond('sales-summary', SUMMARY);

    $screen->tap('tap')->assertSee('Revenue: £12,400');

    $source->assertRequested('sales-summary', times: 1);
});

it('re-requests on refresh with the state the action left, keeping the fragment up meanwhile', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class);
    $source->respond('sales-summary', SUMMARY);

    $screen->tap('refresh')->assertSee('Revenue: £12,400');

    $source->assertRequested('sales-summary', times: 2);
    expect($source->lastRequest()->state['range'])->toBe('30d');

    $source->respond('sales-summary', ['type' => 'text', 'props' => ['text' => 'Revenue: £40,100']]);

    $screen->tap('tap')
        ->assertSee('Revenue: £40,100')
        ->assertDontSee('Revenue: £12,400');
});

it('refreshes one region by name', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class);
    $screen->call('refreshSummary');

    $source->assertRequested('sales-summary', times: 2);
});

it('drops a late answer to a request a refresh superseded', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class);
    $first = $source->lastRequest();

    $screen->tap('refresh');
    $second = $source->lastRequest();

    expect($source->respond($second, SUMMARY))->toBeTrue();
    expect($source->respond($first, ['type' => 'text', 'props' => ['text' => 'Stale']]))->toBeFalse();

    $screen->tap('tap')->assertSee('Revenue: £12,400')->assertDontSee('Stale');
});

it('drops an answer that lands after the component unmounted', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class);
    $request = $source->lastRequest();

    $screen->instance()->unmount();

    expect($source->respond($request, SUMMARY))->toBeFalse();
});

// ── The null source ─────────────────────────────────

it('keeps a source another provider bound before this one registered', function () {
    $mine = new class implements FragmentSource
    {
        public function request(RegionRequest $request): void {}

        public function action(ActionRequest $request): void {}

        public function watch(array $subscriptions, string $platform): void {}
    };

    app()->forgetInstance(FragmentSource::class);
    app()->offsetUnset(FragmentSource::class);
    app()->instance(FragmentSource::class, $mine);

    // Registering mobile's provider again stands in for it running after
    // midgard's, which is the order that used to drop midgard's binding.
    $provider = new \Native\Mobile\NativeServiceProvider(app());
    Closure::bind(fn () => $this->registerAsgardTransport(), $provider, $provider::class)();

    expect(app(FragmentSource::class))->toBe($mine);
});

it('binds a source that asks nobody by default, so the placeholder stays', function () {
    expect(app(FragmentSource::class))->toBeInstanceOf(NullFragmentSource::class);

    Native::test(FragmentSourceScreen::class)
        ->tap('tap')
        ->tap('refresh')
        ->assertSee('Loading sales…');

    expect(ServerRegion::rendered()['sales-summary']['has_fragment'])->toBeFalse();
});

it('keeps the screen up when the source throws', function () {
    app()->instance(FragmentSource::class, new class implements FragmentSource
    {
        public function request(RegionRequest $request): void
        {
            throw new RuntimeException('offline');
        }

        public function action(ActionRequest $request): void {}

        public function watch(array $subscriptions, string $platform): void {}
    });

    Native::test(FragmentSourceScreen::class)
        ->assertSee('Loading sales…')
        ->tap('tap')
        ->assertSee('Taps 1');
});

// ── What a request carries ──────────────────────────

it('carries the component address, region, JSON-safe state and platform', function () {
    $source = Native::fakeFragmentSource();

    Native::test(FragmentSourceScreen::class, platform: 'ios');

    $request = $source->lastRequest();

    expect($request->component)->toBe(ComponentAddress::for(FragmentSourceScreen::class));
    expect($request->component)->toBe('tests.fixtures.edge.fragment_source_screen');
    expect($request->region)->toBe('sales-summary');
    expect($request->platform)->toBe('ios');
    expect($request->componentClass)->toBe(FragmentSourceScreen::class);

    expect($request->state)->toBe([
        'range' => '7d',
        'taps' => 0,
        'note' => null,
        'filters' => ['region' => 'eu', 'tags' => ['a', 'b']],
        'withChild' => false,
    ]);

    // The wire body has no class name and no local id.
    expect($request->toArray())->toBe([
        'component' => 'tests.fixtures.edge.fragment_source_screen',
        'region' => 'sales-summary',
        'state' => $request->state,
        'platform' => 'ios',
    ]);
    expect(json_encode($request->toArray(), JSON_THROW_ON_ERROR))->toBeString();
});

it('skips an uninitialised typed prop rather than reading it', function () {
    $component = new class extends \Native\Mobile\Edge\NativeComponent
    {
        public string $later;

        public int $count = 3;
    };

    expect(ServerRegion::stateOf($component))->toBe(['count' => 3]);
});

it('reports android, and unknown off a device', function () {
    $source = Native::fakeFragmentSource();

    Native::test(FragmentSourceScreen::class, platform: 'android');
    expect($source->lastRequest()->platform)->toBe('android');

    Native::test(FragmentSourceScreen::class);
    expect($source->lastRequest()->platform)->toBe('unknown');
});

// ── Delivery repaints ───────────────────────────────

it('repaints when the fragment arrives through an AsyncTask completion', function () {
    // What a transport does: fetch on the async lane, then hand the tree
    // back from ->finished(), which runs bound to the screen.
    app()->instance(FragmentSource::class, $source = new class implements FragmentSource
    {
        public array $tasks = [];

        public function request(RegionRequest $request): void
        {
            $id = 'task-'.count($this->tasks);
            $this->tasks[] = $id;

            NativeCallbacks::register($id, AsyncTaskFinished::class, function ($tree) use ($request) {
                /** @var \Native\Mobile\Edge\NativeComponent $this */
                $this->receiveFragment($request, $tree);
            }, durable: false);
        }

        public function action(ActionRequest $request): void {}

        public function watch(array $subscriptions, string $platform): void {}
    });

    $screen = Native::test(FragmentSourceScreen::class)->assertSee('Loading sales…');

    $screen->emitNative(AsyncTaskFinished::class, [
        'id' => $source->tasks[0],
        'result' => json_encode(SUMMARY),
    ]);

    $screen->assertSee('Revenue: £12,400')->assertDontSee('Loading sales…');
});

it('flags a repaint for a fragment that lands outside an event, until the next frame', function () {
    app()->instance(FragmentSource::class, new class implements FragmentSource
    {
        public function request(RegionRequest $request): void
        {
            // A source answering at once, from a cache say.
            ServerRegion::deliver($request, SUMMARY);
        }

        public function action(ActionRequest $request): void {}

        public function watch(array $subscriptions, string $platform): void {}
    });

    $screen = Native::test(FragmentSourceScreen::class);

    // The first frame drew the placeholder and asked; the answer is held
    // and the runloop is told to take another turn.
    $screen->assertSee('Loading sales…');
    expect(ServerRegion::repaintPending())->toBeTrue();

    $screen->tap('tap')->assertSee('Revenue: £12,400');
    expect(ServerRegion::repaintPending())->toBeFalse();
});

// ── Ownership and keying ────────────────────────────

it('keeps two screens with the same region name apart', function () {
    $source = Native::fakeFragmentSource();

    $first = Native::test(FragmentSourceScreen::class);
    $source->respond('sales-summary', SUMMARY);
    $first->tap('tap')->assertSee('Revenue: £12,400');

    // Another screen, another component: its region is its own.
    Native::test(ServerRegionScreen::class)
        ->assertSee('Loading sales…')
        ->assertDontSee('Revenue: £12,400');
});

it('requests and delivers a child component region as the child', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class)->set('withChild', true);

    $requests = $source->requests('sales-summary');
    expect($requests)->toHaveCount(2);

    $child = collect($requests)->firstWhere('componentClass', FragmentSourceChild::class);
    expect($child->component)->toBe('tests.fixtures.edge.fragment_source_child');
    expect($child->state)->toBe(['label' => 'orders']);

    $source->respond($child, ['type' => 'text', 'props' => ['text' => 'Orders: 12']]);

    $screen->tap('tap')
        ->assertSee('Orders: 12')
        ->assertSee('Loading sales…');
});

it('requests a region drawn on the streaming path', function () {
    require_once __DIR__.'/../../Support/streaming-writer.php';

    $source = Native::fakeFragmentSource();
    $screen = Native::test(FragmentSourceScreen::class);
    ServerRegion::flush();

    $GLOBALS['nphp_stream'] = [];
    $screen->instance()->streamFrame();

    $source->assertRequested('sales-summary', times: 2);
    expect($GLOBALS['nphp_stream'][0])->toBe(['frame_begin']);
});

// ── Pushed fragments ────────────────────────────────

it('paints a pushed fragment on every live instance that asked for the region with its state', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class)->assertSee('Loading sales…');

    $painted = ServerRegion::push(ComponentAddress::for(FragmentSourceScreen::class), $source->lastRequest()->state, 'sales-summary', SUMMARY);

    expect($painted)->toBe(1);
    $screen->tap('tap')->assertSee('Revenue: £12,400')->assertDontSee('Loading sales…');
});

it('keeps painting pushes after the first one', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class);
    $address = ComponentAddress::for(FragmentSourceScreen::class);
    $state = $source->lastRequest()->state;

    ServerRegion::push($address, $state, 'sales-summary', SUMMARY);
    ServerRegion::push($address, $state, 'sales-summary', ['type' => 'text', 'key' => 'total', 'props' => ['text' => 'Revenue: £99']]);

    $screen->tap('tap')->assertSee('Revenue: £99');
});

it('drops a push for a region the screen never declared, another component or other state', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class);
    $state = $source->lastRequest()->state;

    expect(ServerRegion::push(ComponentAddress::for(FragmentSourceScreen::class), $state, 'elsewhere', SUMMARY))->toBe(0);
    expect(ServerRegion::push('app.some.other.screen', $state, 'sales-summary', SUMMARY))->toBe(0);
    expect(ServerRegion::push(ComponentAddress::for(FragmentSourceScreen::class), ['range' => '30d'] + $state, 'sales-summary', SUMMARY))->toBe(0);

    $screen->tap('tap')->assertSee('Loading sales…')->assertDontSee('Revenue');
});

// ── The push stream set ─────────────────────────────

it('watches nothing until a region has a good answer', function () {
    $source = Native::fakeFragmentSource();

    Native::test(FragmentSourceScreen::class)->tap('tap');

    expect($source->watching())->toBeNull();
});

it('watches an answered region under its address, state and version', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class);
    $request = $source->lastRequest();
    $source->respond('sales-summary', SUMMARY, 'v1');
    $screen->tap('tap');

    expect($source->watching())->toBe([[
        'id' => ServerRegion::subscriptionId($request->component, $request->state),
        'component' => $request->component,
        'regions' => ['sales-summary'],
        'state' => $request->state,
        'versions' => ['sales-summary' => 'v1'],
    ]]);
});

it('derives the same subscription id from the same state, whatever its key order', function () {
    expect(ServerRegion::subscriptionId('a.b', ['x' => 1, 'y' => ['q' => 1, 'p' => [2, 1]]]))
        ->toBe(ServerRegion::subscriptionId('a.b', ['y' => ['p' => [2, 1], 'q' => 1], 'x' => 1]))
        ->toMatch('/^[0-9a-f]{16}$/')
        ->not->toBe(ServerRegion::subscriptionId('a.b', ['x' => 1, 'y' => ['q' => 1, 'p' => [1, 2]]]))
        ->not->toBe(ServerRegion::subscriptionId('a.c', ['x' => 1, 'y' => ['q' => 1, 'p' => [2, 1]]]));
});

it('hands over changes that land together in one watch() call', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class)->set('withChild', true);
    [$own, $child] = [
        collect($source->requests())->firstWhere('componentClass', FragmentSourceScreen::class),
        collect($source->requests())->firstWhere('componentClass', FragmentSourceChild::class),
    ];

    $source->respond($own, SUMMARY);
    $source->respond($child, ['type' => 'text', 'props' => ['text' => 'Orders: 12']]);
    $screen->tap('tap');

    $source->assertWatched(1);
    expect(array_column($source->watching(), 'component'))->toEqualCanonicalizing([$own->component, $child->component]);
});

it('does not hand over a set that has not changed', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class);
    $request = $source->lastRequest();
    $source->respond('sales-summary', SUMMARY, 'v1');
    $screen->tap('tap')->tap('tap');

    // A push only moves the version, which is not a change of set.
    ServerRegion::push($request->component, $request->state, 'sales-summary', SUMMARY, null, 'v2');
    $screen->tap('tap');

    $source->assertWatched(1);
    expect(ServerRegion::subscriptions()[0]['versions'])->toBe(['sales-summary' => 'v2']);
});

it('moves a refreshed region to the subscription for its new state', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class);
    $source->respond('sales-summary', SUMMARY);
    $screen->tap('refresh');
    $source->respond('sales-summary', SUMMARY);
    $screen->tap('tap');

    $source->assertWatched(2);
    expect($source->watching())->toHaveCount(1)
        ->and($source->watching()[0]['state']['range'])->toBe('30d');
});

it('shares one subscription between instances with the same state', function () {
    $source = Native::fakeFragmentSource();

    $first = Native::test(FragmentSourceScreen::class);
    $source->respond($source->lastRequest(), SUMMARY, 'v1');
    $second = Native::test(FragmentSourceScreen::class);
    $source->respond($source->lastRequest(), SUMMARY, 'v2');
    $second->tap('tap');

    // The second joined the first's subscription, so the set did not change.
    $source->assertWatched(1);

    // They hold different versions, so the next open sends the region again.
    expect(ServerRegion::subscriptions())->toHaveCount(1)
        ->and(ServerRegion::subscriptions()[0]['versions'])->toBe([]);

    expect(ServerRegion::push($source->lastRequest()->component, $source->lastRequest()->state, 'sales-summary', SUMMARY))->toBe(2);
});

it('drops a subscription when its last owner unmounts', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class)->set('withChild', true);
    foreach ($source->requests() as $request) {
        $source->respond($request, SUMMARY);
    }
    $screen->tap('tap');
    expect($source->watching())->toHaveCount(2);

    $screen->set('withChild', false);

    $source->assertWatched(2);
    expect(array_column($source->watching(), 'component'))->toBe([ComponentAddress::for(FragmentSourceScreen::class)]);
});

it('hands over an empty set, closing the stream, once nothing is watched', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class);
    $source->respond('sales-summary', SUMMARY);
    $screen->tap('tap');

    $screen->instance()->unmount();
    ServerRegion::sync();

    $source->assertWatched(2);
    expect($source->watching())->toBe([]);
});
