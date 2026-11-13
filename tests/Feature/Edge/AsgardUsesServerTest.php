<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Edge\Asgard\Concerns\UsesServer;
use Native\Mobile\Edge\Asgard\ServerMethods;
use Native\Mobile\Edge\Asgard\ServerRegion;
use Native\Mobile\Edge\ComponentMethodInvoker;
use Native\Mobile\Edge\Exceptions\ComponentMethodNotFoundException;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\ActionFailureScreen;
use Tests\Fixtures\Edge\AliasedUsesServerScreen;
use Tests\Fixtures\Edge\RegionFailureScreen;
use Tests\Fixtures\Edge\ServerMethodScreen;
use Tests\Fixtures\Edge\TraitlessServerScreen;

/**
 * The UsesServer trait. Regions work in any component. The helpers a
 * component calls from its own code, and `#[Server]` methods, need the
 * trait. Its methods are never a tap target.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
    ServerMethods::flush();
    ServerRegion::flush();
    config(['app.debug' => false, 'nativephp.asgard.action_timeout' => 15, 'nativephp.asgard.region_timeout' => 15]);
    Carbon::setTestNow(Carbon::now());
});

afterEach(function () {
    ServerMethods::flush();
    ServerRegion::flush();
    Carbon::setTestNow();
});

/** @return list<string> */
function usesServerMethods(): array
{
    return array_map(
        fn (ReflectionMethod $m) => $m->getName(),
        (new ReflectionClass(UsesServer::class))->getMethods(),
    );
}

// ── Without the trait ───────────────────────────────

it('fetches and paints a region in a component without the trait', function () {
    $source = Native::fakeFragmentSource();
    $screen = Native::test(TraitlessServerScreen::class)->assertSee('Loading sales…');

    expect(method_exists($screen->instance(), 'refreshServerRegions'))->toBeFalse();
    $source->assertRequested('sales-summary', 1);

    $source->respond('sales-summary', ['type' => 'text', 'key' => 't', 'props' => ['text' => 'Revenue: £12,400']]);

    $screen->wait()->assertSee('Revenue: £12,400')->assertDontSee('Loading sales…');
});

it('times a region out on the config value in a component without the trait', function () {
    config(['nativephp.asgard.region_timeout' => 3, 'nativephp.asgard.action_timeout' => 4]);
    Native::fakeFragmentSource();
    $screen = Native::test(TraitlessServerScreen::class);

    expect(ServerRegion::regionTimeout($screen->instance()))->toBe(3.0)
        ->and(ServerRegion::actionTimeout($screen->instance()))->toBe(4.0);

    $screen->wait(2.9);
    expect($screen->get('failures'))->toBe([]);

    $screen->wait(0.2);
    expect($screen->get('failures'))->toBe(['timeout']);
});

it('lets a component with the trait override both timeouts', function () {
    $regions = Native::test(RegionFailureScreen::class)->set('timeout', 2.5);
    $actions = Native::test(ActionFailureScreen::class)->set('timeout', 7.0);

    expect(ServerRegion::regionTimeout($regions->instance()))->toBe(2.5)
        ->and(ServerRegion::actionTimeout($actions->instance()))->toBe(7.0)
        // The trait's own default reads the config.
        ->and(ServerRegion::actionTimeout($regions->instance()))->toBe(15.0);
});

// ── #[Server] without the trait ─────────────────────

it('refuses a #[Server] call on a component without the trait while debugging', function () {
    config(['app.debug' => true]);
    $server = Native::fakeServerTransport();
    $screen = Native::test(TraitlessServerScreen::class);

    expect(fn () => $screen->instance()->__invokeInteraction('ping', []))
        ->toThrow(LogicException::class, 'Add `use UsesServer;`');

    expect($screen->get('note'))->toBe('');
    $server->assertNothingSent();
});

it('logs a #[Server] call on a component without the trait in production, and sends it', function () {
    Log::spy();
    $server = Native::fakeServerTransport();
    $screen = Native::test(TraitlessServerScreen::class);

    $screen->instance()->__invokeInteraction('ping', []);

    expect($screen->get('note'))->toBe('pinged');
    $server->assertSent('ping');
    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'use UsesServer;'));
});

it('does not complain about a #[Server] call on a component with the trait', function () {
    config(['app.debug' => true]);
    Log::spy();
    $server = Native::fakeServerTransport();

    Native::test(ServerMethodScreen::class)->instance()->__invokeInteraction('ping', []);

    $server->assertSent('ping');
    Log::shouldNotHaveReceived('warning');
});

// ── The interaction guard ───────────────────────────

it('refuses every UsesServer method as a tap target', function (string $method) {
    $server = Native::fakeServerTransport();
    $screen = Native::test(ServerMethodScreen::class);

    expect(fn () => ComponentMethodInvoker::assertCallable($screen->instance(), $method))
        ->toThrow(ComponentMethodNotFoundException::class);

    expect(fn () => $screen->instance()->__invokeInteraction($method, ['ping']))
        ->toThrow(ComponentMethodNotFoundException::class);

    $server->assertNothingSent();
    expect($screen->get('note'))->toBe('');
})->with(usesServerMethods());

it('refuses a UsesServer method whatever case it is spelled in', function () {
    $server = Native::fakeServerTransport();
    $screen = Native::test(ServerMethodScreen::class);

    expect(fn () => $screen->instance()->__invokeInteraction('CALLSERVERMETHOD', ['ping']))
        ->toThrow(ComponentMethodNotFoundException::class);

    $server->assertNothingSent();
});

it('refuses a UsesServer method brought in under an alias', function () {
    Native::fakeFragmentSource();
    $screen = Native::test(AliasedUsesServerScreen::class);

    expect(fn () => ComponentMethodInvoker::assertCallable($screen->instance(), 'reloadRegions'))
        ->toThrow(ComponentMethodNotFoundException::class);
});

it('refuses an override of a UsesServer method', function () {
    Native::fakeFragmentSource();
    $screen = Native::test(RegionFailureScreen::class);

    expect(fn () => ComponentMethodInvoker::assertCallable($screen->instance(), 'serverRegionTimeout'))
        ->toThrow(ComponentMethodNotFoundException::class);
});

it('still lets a template tap the component\'s own methods', function () {
    Native::fakeFragmentSource();

    Native::test(AliasedUsesServerScreen::class)->tap('refresh')->assertSet('refreshes', 1);
});
