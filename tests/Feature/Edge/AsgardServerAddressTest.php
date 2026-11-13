<?php

use Native\Mobile\Attributes\ServerAddress;
use Native\Mobile\Edge\Asgard\ComponentAddress;
use Native\Mobile\Edge\Asgard\ServerMethods;
use Native\Mobile\Edge\Asgard\ServerRegion;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Support\NativeCallbacks;
use Native\Mobile\Testing\Native;
use Tests\Fixtures\Edge\FragmentSourceChild;
use Tests\Fixtures\Edge\FragmentSourceScreen;
use Tests\Fixtures\Edge\NamedRegionScreen;
use Tests\Fixtures\Edge\NamedServerMethodScreen;

/**
 * #[ServerAddress] (docs/ASGARD-PROTOCOL.md §4): a component can name the
 * address its server half is found under, instead of its class name in
 * dot notation.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../../Fixtures/views');
    ComponentRegistry::reset();
    ComponentRegistry::components(['fragment-source-child' => FragmentSourceChild::class]);
    ServerRegion::flush();
    ServerMethods::flush();
    NativeCallbacks::flush();
});

afterEach(function () {
    ComponentRegistry::reset();
    ServerRegion::flush();
    ServerMethods::flush();
    NativeCallbacks::flush();
});

it('uses the address the class names', function () {
    expect(ComponentAddress::for(NamedRegionScreen::class))->toBe('sales');
    expect(ComponentAddress::for('\\'.NamedRegionScreen::class))->toBe('sales');
});

it('does not hand the address down to subclasses of a named class', function () {
    $child = new class extends NamedRegionScreen {};

    expect(ComponentAddress::for($child))->not->toBe('sales');
});

it('falls back to the class name without the attribute', function () {
    expect(ComponentAddress::for(FragmentSourceScreen::class))->toBe('tests.fixtures.edge.fragment_source_screen');
    expect(ComponentAddress::for('App\Native\Screens\Home'))->toBe('app.native.screens.home');
});

it('refuses an address that is not dot notation with snake_case segments', function (string $address) {
    new ServerAddress($address);
})->with(['', 'Home', 'app\\home', 'home.', '.home', 'home-screen', 'home..x'])
    ->throws(InvalidArgumentException::class);

it('sends region requests to the named address', function () {
    $source = Native::fakeFragmentSource();

    Native::test(NamedRegionScreen::class);

    expect($source->lastRequest()->component)->toBe('sales');
});

it('paints pushes for the named address and not the class name', function () {
    $source = Native::fakeFragmentSource();

    $screen = Native::test(NamedRegionScreen::class);
    $state = $source->lastRequest()->state;
    $fragment = ['type' => 'text', 'key' => 'total', 'props' => ['text' => 'Revenue: £12,400']];

    expect(ServerRegion::push('tests.fixtures.edge.named_region_screen', $state, 'sales-summary', $fragment))->toBe(0);
    expect(ServerRegion::push('sales', $state, 'sales-summary', $fragment))->toBe(1);

    $screen->tap('tap')->assertSee('Revenue: £12,400');
});

it('sends #[Server] calls to the named address', function () {
    $server = Native::fakeServerTransport();

    Native::test(NamedServerMethodScreen::class)->tap('Refresh');

    expect($server->lastRequest()->component)->toBe('reports.chart');
});
