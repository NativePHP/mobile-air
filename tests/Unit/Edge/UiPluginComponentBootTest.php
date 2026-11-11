<?php

use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Exceptions\ElementRegistrationException;
use Native\Mobile\Edge\NativeTagPrecompiler;
use Native\Mobile\NativeServiceProvider;
use Native\Mobile\Plugins\PluginRegistry;
use Tests\Fixtures\Edge\CustomEventElement;
use Tests\Fixtures\Edge\ErrorProbeElement;

afterEach(function () {
    NativeTagPrecompiler::resetElementEvents();
    Mockery::close();
});

/**
 * Run the provider's plugin-component boot step against a fake set of
 * manifest components.
 */
function bootUiPluginComponents(array $components): void
{
    $registry = Mockery::mock(PluginRegistry::class);
    $registry->shouldReceive('components')->once()->andReturn($components);
    app()->instance(PluginRegistry::class, $registry);

    $boot = new ReflectionMethod(NativeServiceProvider::class, 'registerUiPluginComponents');
    $boot->invoke(new NativeServiceProvider(app()));
}

it('registers element_events from a plugin component manifest', function () {
    NativeTagPrecompiler::resetElementEvents();

    bootUiPluginComponents([
        [
            'type' => 'signature_pad',
            'element' => 'Vendor\\Missing\\SignaturePad',
            'blade' => 'Vendor\\Missing\\SignaturePadComponent',
            'element_events' => ['signed'],
        ],
    ]);

    expect(NativeTagPrecompiler::customElementEvents())->toContain('signed');
});

it('registers manifest element_events in addition to Element::elementEvents', function () {
    NativeTagPrecompiler::resetElementEvents();

    // The app has already booted and locked the registry; start from an
    // empty one, as a fresh boot would.
    ElementRegistry::reset();

    bootUiPluginComponents([
        [
            'type' => 'custom_widget_boot',
            'element' => CustomEventElement::class,
            'blade' => 'Vendor\\Missing\\Blade',
            'element_events' => ['signed'],
        ],
    ]);

    expect(NativeTagPrecompiler::customElementEvents())->toContain('link')
        ->and(NativeTagPrecompiler::customElementEvents())->toContain('scan')
        ->and(NativeTagPrecompiler::customElementEvents())->toContain('signed');
});

it('registers a plugin component under the plugin that declared it', function () {
    ElementRegistry::reset();

    bootUiPluginComponents([
        [
            'type' => 'acme_widget',
            'element' => CustomEventElement::class,
            'blade' => 'Vendor\\Missing\\Blade',
            'plugin' => 'acme/widgets',
        ],
    ]);

    expect(ElementRegistry::get('acme_widget'))->toBe(CustomEventElement::class)
        ->and(ElementRegistry::owner('acme_widget'))->toBe('acme/widgets');
});

it('lets a plugin pair its renderer with core\'s own element class', function () {
    // mobile-ui declares `text`, `column`, ... with core's Element classes so
    // it can supply their native renderers. That takes nothing from core.
    bootUiPluginComponents([
        ['type' => 'text', 'element' => Text::class, 'blade' => 'X', 'plugin' => 'nativephp/mobile-ui'],
        ['type' => 'column', 'element' => '\\'.Column::class, 'blade' => 'X', 'plugin' => 'nativephp/mobile-ui'],
    ]);

    expect(ElementRegistry::owner('text'))->toBe(ElementRegistry::CORE)
        ->and(ElementRegistry::get('text'))->toBe(Text::class)
        ->and(ElementRegistry::owner('column'))->toBe(ElementRegistry::CORE);
});

it('refuses a plugin that brings its own class for a core element type', function () {
    bootUiPluginComponents([
        ['type' => 'text', 'element' => ErrorProbeElement::class, 'blade' => 'X', 'plugin' => 'acme/text'],
    ]);
})->throws(
    ElementRegistrationException::class,
    "Element type 'text' is already registered by core as ".Text::class.'. acme/text tried to register '.ErrorProbeElement::class.' for it.'
);

it('refuses a second plugin claiming a type another plugin holds', function () {
    ElementRegistry::reset();

    expect(fn () => bootUiPluginComponents([
        ['type' => 'acme_widget', 'element' => CustomEventElement::class, 'blade' => 'X', 'plugin' => 'acme/widgets'],
        ['type' => 'acme_widget', 'element' => ErrorProbeElement::class, 'blade' => 'X', 'plugin' => 'evil/widgets'],
    ]))->toThrow(function (ElementRegistrationException $e) {
        expect($e->getMessage())
            ->toContain("Element type 'acme_widget' is already registered by acme/widgets")
            ->toContain('To boot without it, remove evil/widgets from plugins() in app/Providers/NativeServiceProvider.php.');
    });

    expect(ElementRegistry::get('acme_widget'))->toBe(CustomEventElement::class);
});

it('boots twice without complaint', function () {
    ElementRegistry::reset();

    $components = [
        ['type' => 'acme_widget', 'element' => CustomEventElement::class, 'blade' => 'X', 'plugin' => 'acme/widgets'],
    ];

    bootUiPluginComponents($components);
    ElementRegistry::lock();
    bootUiPluginComponents($components);

    expect(ElementRegistry::owner('acme_widget'))->toBe('acme/widgets');
});
