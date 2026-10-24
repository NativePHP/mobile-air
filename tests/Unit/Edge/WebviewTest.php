<?php

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\Webview;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\TailwindParser;
use Native\Mobile\Edge\UnknownElementHint;
use Native\Mobile\NativeServiceProvider;
use Native\Mobile\Plugins\PluginRegistry;
use Tests\Fixtures\Edge\CustomEventElement;

/**
 * Attribute → wire-prop behavior of the webview element, driven through
 * NativeElementCollector exactly as compiled Blade drives it. The element
 * moved here from nativephp/mobile-ui, along with its renderers.
 */
beforeEach(function () {
    NativeElementCollector::reset();
    TailwindParser::clearCache();
    ElementRegistry::reset();
    ElementRegistry::register('webview', Webview::class);
});

afterEach(function () {
    NativeElementCollector::reset();
    ElementRegistry::reset();
    Mockery::close();
});

it('stays locked down by default', function () {
    NativeElementCollector::leaf('webview', [
        'src' => 'https://example.com',
    ]);

    $registry = new CallbackRegistry;
    $tree = NativeElementCollector::collect()->toArray($registry);

    expect($tree['type'])->toBe('webview');
    expect($tree['props']['src'])->toBe('https://example.com');
    expect($tree['props'])->not->toHaveKey('javascript');
    expect($tree['props'])->not->toHaveKey('dom_storage');
    expect($tree['props'])->not->toHaveKey('php');
    expect($tree['props'])->not->toHaveKey('fullscreen');
});

it('applies sandbox opt-ins', function () {
    NativeElementCollector::leaf('webview', [
        'src' => 'https://example.com',
        'javascript' => true,
        'dom-storage' => true,
    ]);

    $registry = new CallbackRegistry;
    $tree = NativeElementCollector::collect()->toArray($registry);

    expect($tree['props']['javascript'])->toBeTrue();
    expect($tree['props']['dom_storage'])->toBeTrue();
});

it('fullscreen sets the prop and fill layout', function () {
    // Bare Blade attribute (`<native:webview fullscreen />`) arrives as bool true.
    NativeElementCollector::leaf('webview', [
        'src' => 'https://example.com',
        'fullscreen' => true,
    ]);

    $registry = new CallbackRegistry;
    $tree = NativeElementCollector::collect()->toArray($registry);

    expect($tree['props']['fullscreen'])->toBeTrue();
    expect($tree['layout']['width'])->toBe('fill');
    expect($tree['layout']['height'])->toBe('fill');
});

it('fullscreen=false leaves layout alone', function () {
    NativeElementCollector::leaf('webview', [
        'src' => 'https://example.com',
        'fullscreen' => 'false',
    ]);

    $registry = new CallbackRegistry;
    $tree = NativeElementCollector::collect()->toArray($registry);

    expect($tree['props']['fullscreen'])->toBeFalse();
    expect($tree['layout']['width'] ?? null)->not->toBe('fill');
});

it('php mode passes the flag and route path', function () {
    NativeElementCollector::leaf('webview', [
        'php' => true,
        'src' => '/dashboard',
    ]);

    $registry = new CallbackRegistry;
    $tree = NativeElementCollector::collect()->toArray($registry);

    expect($tree['props']['php'])->toBeTrue();
    expect($tree['props']['src'])->toBe('/dashboard');
});

it('registers the @navigated callback', function () {
    NativeElementCollector::leaf('webview', [
        'src' => 'https://example.com',
        '_navigated' => 'onUrlChange',
    ]);

    $registry = new CallbackRegistry;
    $tree = NativeElementCollector::collect()->toArray($registry);

    expect($tree['props']['on_navigated'])->toBeInt();
    expect($registry->resolve($tree['props']['on_navigated']))
        ->toBe(['method' => 'onUrlChange', 'args' => []]);
});

it('is registered by mobile itself', function () {
    ElementRegistry::reset();

    $boot = new ReflectionMethod(NativeServiceProvider::class, 'registerCoreElements');
    $boot->invoke(new NativeServiceProvider(app()));

    expect(ElementRegistry::all()['webview'] ?? null)->toBe(Webview::class);
});

it('keeps its own class when an older mobile-ui still declares webview', function () {
    ElementRegistry::reset();

    $registry = Mockery::mock(PluginRegistry::class);
    $registry->shouldReceive('components')->once()->andReturn([
        [
            'type' => 'webview',
            'element' => CustomEventElement::class,
            'blade' => 'Vendor\\Missing\\Blade',
        ],
    ]);
    app()->instance(PluginRegistry::class, $registry);

    $provider = new NativeServiceProvider(app());
    (new ReflectionMethod(NativeServiceProvider::class, 'registerCoreElements'))->invoke($provider);
    (new ReflectionMethod(NativeServiceProvider::class, 'registerUiPluginComponents'))->invoke($provider);

    expect(ElementRegistry::all()['webview'])->toBe(Webview::class);
});

it('is not hinted as a mobile-ui element any more', function () {
    expect(UnknownElementHint::FIRST_PARTY['nativephp/mobile-ui'])->not->toContain('webview');
});
