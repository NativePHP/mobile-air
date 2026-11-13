<?php

use Illuminate\Support\Facades\Event;
use Native\Mobile\Edge\Asgard\ComponentAddress;
use Native\Mobile\Edge\Asgard\Contracts\ServerTransport;
use Native\Mobile\Edge\Asgard\ServerMethods;
use Native\Mobile\Edge\Asgard\ServerRegion;
use Native\Mobile\Edge\Asgard\ServerRequest;
use Native\Mobile\Edge\Asgard\ServerResponse;
use Native\Mobile\Edge\Exceptions\DirectlyCallingLifecycleHooksNotAllowedException;
use Native\Mobile\Events\Asgard\ServerActionFailed;
use Native\Mobile\Testing\FakeServerTransport;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ServerMethodScreen;

/**
 * `#[Server]` methods (docs/ASGARD-SERVER-METHODS.md): the client body
 * runs locally, the state it leaves behind is what gets sent, and the
 * closure it returned runs when the answer comes back.
 *
 * No transport is involved — `FakeServerTransport` records what would
 * have been sent and the test decides when, and whether, an answer
 * arrives. That is the only way to write the cases that matter: a
 * response that overtakes an older one, one for a screen the user has
 * already left, one that never comes.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');

    // The pending-request table is static and outlives a test's
    // container, so start every test with an empty one.
    ServerMethods::flush();
    ServerRegion::flush();
});

afterEach(function () {
    ServerMethods::flush();
    ServerRegion::flush();
});

// ── What crosses the wire (§2) ──────────────────────

it('sends the state the client body left behind, not the state before it', function () {
    $server = Native::fakeServerTransport();

    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    $request = $server->lastRequest();

    expect($request->method)->toBe('refreshChart');
    expect($request->args)->toBe(['30d']);

    // The body set both of these on its way out; the snapshot is taken
    // after it returned, so they are part of the request.
    expect($request->state['loading'])->toBeTrue();
    expect($request->state['range'])->toBe('30d');

    // And the client really is in that state, waiting.
    expect($screen->get('loading'))->toBeTrue();
    $screen->assertSee('Loading…');
});

it('addresses the component in dot notation, never by class name', function () {
    $server = Native::fakeServerTransport();

    Native::test(ServerMethodScreen::class)->tap('Refresh');

    expect($server->lastRequest()->component)->toBe('tests.fixtures.edge.server_method_screen');
    expect(ComponentAddress::for('App\Native\Components\SalesSummary'))
        ->toBe('app.native.components.sales_summary');
});

it('sends the method, its arguments and state, and nothing else', function () {
    $server = Native::fakeServerTransport();

    Native::test(ServerMethodScreen::class)->tap('Refresh');

    $body = $server->lastRequest()->toArray();

    expect(array_keys($body))->toBe(['component', 'method', 'args', 'state', 'platform']);

    // Off a device System reports no platform, and the request says so
    // rather than guessing, as a region request does.
    expect($body['platform'])->toBe('unknown');

    // The closure is a PHP value held on the device: never serialised,
    // inspected or transmitted. If one had leaked into the request, this
    // would throw.
    expect(serialize($body))->toBeString();

    // Component internals are protected, so a request cannot carry them
    // even by accident.
    expect($body['state'])->not->toHaveKey('nativeCallbacks');
    expect($body['state'])->not->toHaveKey('nativeRouter');
});

it('refuses to call a method that is not marked #[Server]', function () {
    Native::fakeServerTransport();

    $screen = Native::test(ServerMethodScreen::class);

    expect(fn () => $screen->instance()->callServerMethod('deleteAccount'))
        ->toThrow(RuntimeException::class, 'is not marked #[Server]');

    expect($screen->get('note'))->toBe('');
});

it('routes a tapped #[Server] method through the interaction entry point', function () {
    $server = Native::fakeServerTransport();

    $screen = Native::test(ServerMethodScreen::class);

    $screen->instance()->__invokeInteraction('refreshChart', ['90d']);

    expect($server->lastRequest()->args)->toBe(['90d']);
    expect($screen->get('loading'))->toBeTrue();
});

it('keeps the method guard in front of a #[Server] method', function () {
    $server = Native::fakeServerTransport();

    $screen = Native::test(ServerMethodScreen::class);

    expect(fn () => $screen->instance()->__invokeInteraction('dehydrateChart', []))
        ->toThrow(DirectlyCallingLifecycleHooksNotAllowedException::class);

    expect($screen->get('note'))->toBe('');
    expect($server->sent())->toBe([]);
});

// ── Delivery (§4) ───────────────────────────────────

it('runs the closure bound to the component when the response arrives', function () {
    $server = Native::fakeServerTransport();

    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    $screen->emitNative(ServerMethods::RESPONSE_EVENT, $server->payload(
        delta: ['total' => '£12,400'],
    ));

    expect($screen->get('loading'))->toBeFalse();
    expect($screen->get('completions'))->toBe(1);

    // `$this` inside the closure is the component instance itself, not a
    // copy and not a fresh one.
    expect($screen->get('closureThis'))->toBe(spl_object_id($screen->instance()));

    // The runloop re-renders after a native event, so the delta paints.
    $screen->assertSee('Total £12,400')->assertDontSee('Loading…');
});

it('discards a response whose request id is unknown', function () {
    Native::fakeServerTransport();

    Native::test(ServerMethodScreen::class);

    expect(ServerMethods::deliver(['id' => 'asgard-99', 'ok' => true, 'delta' => ['total' => 'X']]))
        ->toBeFalse();
});

it('clears the pending request before the closure runs', function () {
    $server = Native::fakeServerTransport();

    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');
    $id = $server->lastId();

    expect(ServerMethods::pending())->toBe([$id]);

    $server->resolve($id, ['total' => 'done']);

    expect(ServerMethods::pending())->toBe([]);
    expect($screen->get('completions'))->toBe(1);
});

// ── Reconciliation (§5) ─────────────────────────────

it('keeps a prop the user changed in flight and applies one that did not move', function () {
    $server = Native::fakeServerTransport();

    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    // The user types while the request is out.
    $screen->set('note', 'typed while waiting');

    $server->resolve(delta: [
        'note' => 'server note',
        'total' => '£12,400',
    ]);

    expect($screen->get('note'))->toBe('typed while waiting');
    expect($screen->get('total'))->toBe('£12,400');

    // And the closure is told, rather than the developer finding out by
    // looking at the screen.
    expect($screen->get('refused'))->toBe(['note']);
});

it('resolves out-of-order responses without sequence numbers', function () {
    $server = Native::fakeServerTransport();

    $screen = Native::test(ServerMethodScreen::class)
        ->tap('Refresh')
        ->tap('Refresh');

    [$first, $second] = array_keys($server->sent());

    // The newer request answers first.
    $server->resolve($second, ['total' => 'newer']);
    expect($screen->get('total'))->toBe('newer');

    // The older one answers late. Its value lost the compare-and-set,
    // because `total` no longer holds what that request sent.
    $server->resolve($first, ['total' => 'older']);
    expect($screen->get('total'))->toBe('newer');

    // Both closures still ran — a stale response is not a dropped one.
    expect($screen->get('completions'))->toBe(2);
    expect($screen->get('refused'))->toBe(['total']);
});

it('lets the server win an ABA change', function () {
    $server = Native::fakeServerTransport();

    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    // Changed, and changed back to exactly what the request sent.
    $screen->set('total', 'something else');
    $screen->set('total', '—');

    $server->resolve(delta: ['total' => 'server value']);

    expect($screen->get('total'))->toBe('server value');
    expect($screen->get('refused'))->toBe([]);
});

it('compares an object prop by value, not by identity', function () {
    $server = Native::fakeServerTransport();

    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    // Mutated in place: same instance, different value. A snapshot that
    // held the instance would compare it against itself and let the
    // server clobber this.
    $screen->instance()->box->value = 2;

    $server->resolve(delta: ['box' => (object) ['value' => 9]]);

    expect($screen->get('box')->value)->toBe(2);
    expect($screen->get('refused'))->toBe(['box']);
});

it('applies an object prop that was rebuilt to an equal value', function () {
    $server = Native::fakeServerTransport();

    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    // A different instance holding the same value has not "moved".
    $screen->instance()->box = (object) ['value' => 1];

    $server->resolve(delta: ['box' => (object) ['value' => 9]]);

    expect($screen->get('box')->value)->toBe(9);
});

it('ignores a delta prop this half of the component does not have', function () {
    $server = Native::fakeServerTransport();

    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    $server->resolve(delta: ['chartData' => [1, 2, 3], 'total' => '£1']);

    expect($screen->get('total'))->toBe('£1');
    expect($screen->get('refused'))->toBe(['chartData']);
    expect(property_exists($screen->instance(), 'chartData'))->toBeFalse();
});

// ── Unmount (§4) ────────────────────────────────────

it('discards a response for a component that has unmounted', function () {
    $server = Native::fakeServerTransport();

    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');
    $id = $server->lastId();

    $screen->instance()->unmount();

    // The table is empty, so nothing holds the screen or its closure.
    expect(ServerMethods::pending())->toBe([]);

    expect($server->resolve($id, ['total' => '£12,400']))->toBeFalse();

    expect($screen->get('total'))->toBe('—');
    expect($screen->get('completions'))->toBe(0);
    expect($screen->get('loading'))->toBeTrue();
});

it('discards a response for a component that has been collected', function () {
    $server = FakeServerTransport::enable();

    $screen = new ServerMethodScreen;
    $screen->mount();

    // No closure, so the pending entry holds only a weak reference.
    $id = $screen->callServerMethod('ping');
    expect($id)->not->toBeNull();

    unset($screen);
    gc_collect_cycles();

    expect($server->resolve($id, ['note' => 'from the server']))->toBeFalse();
});

it('applies a delta for a #[Server] method that returns no continuation', function () {
    $server = FakeServerTransport::enable();

    $screen = new ServerMethodScreen;
    $screen->mount();

    // Fire and forget: the body runs, the request goes, and there is no
    // closure to run when it answers. The delta still lands.
    $id = $screen->callServerMethod('ping');

    expect($server->resolve($id, ['note' => 'from the server']))->toBeTrue();
    expect($screen->note)->toBe('from the server');
});

// ── Failure (§7) ────────────────────────────────────

it('runs the closure on failure and applies no delta', function () {
    $server = Native::fakeServerTransport();

    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    // A failed response carrying a delta anyway: none of it lands.
    ServerMethods::deliver([
        'id' => $server->lastId(),
        'ok' => false,
        'error' => 'The warehouse is down.',
        'delta' => ['total' => '£12,400'],
    ]);

    expect($screen->get('completions'))->toBe(1);
    expect($screen->get('loading'))->toBeFalse();
    expect($screen->get('error'))->toBe('The warehouse is down.');
    expect($screen->get('total'))->toBe('—');
});

it('fails fast when the request cannot even be sent', function () {
    Native::fakeServerTransport()->failSending('Network unreachable.');

    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    // The client body ran, the send failed, the closure ran. Nothing queues.
    expect($screen->get('range'))->toBe('30d');
    expect($screen->get('loading'))->toBeFalse();
    expect($screen->get('completions'))->toBe(1);
    expect($screen->get('error'))->toBe('Network unreachable.');
    expect(ServerMethods::pending())->toBe([]);
});

it('behaves like an offline device when no transport is bound', function () {
    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    expect($screen->get('loading'))->toBeFalse();
    expect($screen->get('completions'))->toBe(1);
    expect($screen->get('error'))->toContain('No Asgard transport is bound');
    expect(ServerMethods::pending())->toBe([]);
});

// ── A transport that answers inline (§3) ────────────

/**
 * A transport that answers before send() has returned, as a faked lane
 * does in a test, or a real one that fails before it starts.
 */
function inlineTransport(array $answer): ServerTransport
{
    $transport = new class($answer) implements ServerTransport
    {
        public function __construct(public array $answer) {}

        public function send(ServerRequest $request): string
        {
            ServerMethods::deliver(['id' => 'inline-1', ...$this->answer]);

            return 'inline-1';
        }
    };

    app()->instance(ServerTransport::class, $transport);

    return $transport;
}

it('holds an answer that arrives before send() returns and delivers it', function () {
    inlineTransport(['ok' => true, 'delta' => ['total' => '£9']]);

    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    expect($screen->get('completions'))->toBe(1);
    expect($screen->get('loading'))->toBeFalse();
    expect($screen->get('total'))->toBe('£9');
    expect(ServerMethods::pending())->toBe([]);
});

it('holds a failure that arrives before send() returns and reports it', function () {
    Event::fake([ServerActionFailed::class]);
    inlineTransport(['ok' => false, 'error' => 'No lane.', 'code' => null]);

    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    expect($screen->get('completions'))->toBe(1);
    expect($screen->get('error'))->toBe('No lane.');
    expect(ServerMethods::pending())->toBe([]);

    Event::assertDispatched(ServerActionFailed::class, fn ($e) => $e->action === 'refreshChart' && $e->reason === 'network');
});

it('drops an early answer for an id send() never returned', function () {
    $transport = new class implements ServerTransport
    {
        public function send(ServerRequest $request): string
        {
            ServerMethods::deliver(['id' => 'someone-else', 'ok' => true, 'delta' => ['total' => '£1']]);

            return 'mine';
        }
    };
    app()->instance(ServerTransport::class, $transport);

    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    expect($screen->get('completions'))->toBe(0);
    expect($screen->get('total'))->toBe('—');
    expect(ServerMethods::pending())->toBe(['mine']);

    // Still dropped later: nothing held it past send().
    expect(ServerMethods::deliver(['id' => 'someone-else', 'ok' => true]))->toBeFalse();
});

// ── Fragments in a response (§9) ────────────────────

it('puts a fragment in a response through the same validation a region render gets', function () {
    config(['app.debug' => false]);

    $server = Native::fakeServerTransport();

    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    $screen->emitNative(ServerMethods::RESPONSE_EVENT, $server->payload(fragments: [
        'sales-summary' => [
            'type' => 'text',
            'key' => 'total',
            'props' => [
                'text' => 'Revenue £12,400',
                // A server writing this would be choosing which CLIENT
                // method runs (§9.3). It is stripped, here as anywhere.
                '_press' => 'deleteAccount',
            ],
        ],
    ]));

    $screen->assertSee('Revenue £12,400')->assertDontSee('Loading sales…');

    expect(ServerRegion::registryFor('sales-summary', $screen->instance())->expressions())->toBe([]);
    expect($screen->get('note'))->toBe('');
});

it('reports a fragment the client cannot render instead of throwing out of delivery', function () {
    config(['app.debug' => true]);

    $server = Native::fakeServerTransport();

    $screen = Native::test(ServerMethodScreen::class)->tap('Refresh');

    $server->resolve(fragments: [
        'sales-summary' => ['type' => 'holographic_chart'],
    ]);

    // The closure still ran (§7) and the region kept its placeholder.
    expect($screen->get('completions'))->toBe(1);
    expect($screen->get('refusedFragments'))->toBe(['sales-summary']);
    expect(ServerRegion::hasFragment('sales-summary'))->toBeFalse();
});

// ── Shape of the response object (§7) ───────────────

it('describes the outcome on the response handed to the closure', function () {
    $ok = new ServerResponse(id: 'asgard-1', ok: true, applied: ['a' => 1], refused: ['b' => 2]);

    expect($ok->succeeded())->toBeTrue();
    expect($ok->failed())->toBeFalse();
    expect($ok->wasRefused('b'))->toBeTrue();
    expect($ok->wasRefused('a'))->toBeFalse();

    $failed = ServerResponse::failure('asgard-2', 'gone', 419);

    expect($failed->failed())->toBeTrue();
    expect($failed->applied)->toBe([]);
    expect($failed->code)->toBe(419);
});
