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

it('paints a pushed fragment on every live instance that asked for the region', function () {
    Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class)->assertSee('Loading sales…');

    $painted = ServerRegion::push(ComponentAddress::for(FragmentSourceScreen::class), 'sales-summary', SUMMARY);

    expect($painted)->toBe(1);
    $screen->tap('tap')->assertSee('Revenue: £12,400')->assertDontSee('Loading sales…');
});

it('keeps painting pushes after the first one', function () {
    Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class);
    $address = ComponentAddress::for(FragmentSourceScreen::class);

    ServerRegion::push($address, 'sales-summary', SUMMARY);
    ServerRegion::push($address, 'sales-summary', ['type' => 'text', 'key' => 'total', 'props' => ['text' => 'Revenue: £99']]);

    $screen->tap('tap')->assertSee('Revenue: £99');
});

it('drops a push for a region the screen never declared or another component', function () {
    Native::fakeFragmentSource();

    $screen = Native::test(FragmentSourceScreen::class);

    expect(ServerRegion::push(ComponentAddress::for(FragmentSourceScreen::class), 'elsewhere', SUMMARY))->toBe(0);
    expect(ServerRegion::push('app.some.other.screen', 'sales-summary', SUMMARY))->toBe(0);

    $screen->tap('tap')->assertSee('Loading sales…')->assertDontSee('Revenue');
});
