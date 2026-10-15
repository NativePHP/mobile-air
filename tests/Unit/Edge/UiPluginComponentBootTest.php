<?php

use Native\Mobile\Edge\NativeTagPrecompiler;
use Native\Mobile\NativeServiceProvider;
use Native\Mobile\Plugins\PluginRegistry;
use Tests\Fixtures\Edge\CustomEventElement;

afterEach(function () {
    NativeTagPrecompiler::resetElementEvents();
    Mockery::close();
});

it('registers element_events from a plugin component manifest', function () {
    NativeTagPrecompiler::resetElementEvents();

    $registry = Mockery::mock(PluginRegistry::class);
    $registry->shouldReceive('components')->once()->andReturn([
        [
            'type' => 'signature_pad',
            'element' => 'Vendor\\Missing\\SignaturePad',
            'blade' => 'Vendor\\Missing\\SignaturePadComponent',
            'element_events' => ['signed'],
        ],
    ]);
    app()->instance(PluginRegistry::class, $registry);

    $boot = new ReflectionMethod(NativeServiceProvider::class, 'registerUiPluginComponents');
    $boot->invoke(new NativeServiceProvider(app()));

    expect(NativeTagPrecompiler::customElementEvents())->toContain('signed');
});

it('registers manifest element_events in addition to Element::elementEvents', function () {
    NativeTagPrecompiler::resetElementEvents();

    $registry = Mockery::mock(PluginRegistry::class);
    $registry->shouldReceive('components')->once()->andReturn([
        [
            'type' => 'custom_widget_boot',
            'element' => CustomEventElement::class,
            'blade' => 'Vendor\\Missing\\Blade',
            'element_events' => ['signed'],
        ],
    ]);
    app()->instance(PluginRegistry::class, $registry);

    $boot = new ReflectionMethod(NativeServiceProvider::class, 'registerUiPluginComponents');
    $boot->invoke(new NativeServiceProvider(app()));

    expect(NativeTagPrecompiler::customElementEvents())->toContain('link')
        ->and(NativeTagPrecompiler::customElementEvents())->toContain('scan')
        ->and(NativeTagPrecompiler::customElementEvents())->toContain('signed');
});
