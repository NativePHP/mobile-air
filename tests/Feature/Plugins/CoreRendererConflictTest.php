<?php

use Illuminate\Filesystem\Filesystem;
use Native\Mobile\Commands\PluginRegisterCommand;
use Native\Mobile\Edge\CoreRenderers;
use Native\Mobile\Exceptions\PluginConflictException;
use Native\Mobile\Plugins\Compilers\AndroidPluginCompiler;
use Native\Mobile\Plugins\Compilers\IOSPluginCompiler;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginDiscovery;
use Native\Mobile\Plugins\PluginManifest;
use Native\Mobile\Plugins\PluginRegistry;

/**
 * A plugin may supply renderers for core's Element classes (mobile-ui does,
 * for `text`, `column`, `top_bar`, ...), but not for the types core renders
 * natively itself. Declaring one would hand core's root chrome to the plugin
 * in the generated registration, so the build refuses it like a clash
 * between two plugins.
 */
function conflictPlugin(string $name, array $components, ?string $namespace = null): Plugin
{
    return new Plugin(
        name: $name,
        version: '1.0.0',
        path: '/test/path/'.$name,
        manifest: new PluginManifest([
            'namespace' => $namespace ?? str_replace(['/', '-'], '', ucwords($name, '/-')),
            'components' => $components,
        ]),
        composerType: 'nativephp-ui-plugin',
    );
}

function conflictComponent(string $type): array
{
    return [
        'type' => $type,
        'element' => 'Acme\\Elements\\Widget',
        'blade' => 'Acme\\Components\\Widget',
        'android_renderer' => 'com.acme.WidgetRenderer',
        'ios_renderer' => 'AcmeWidgetRenderer',
    ];
}

function mobileUiPlugin(): Plugin
{
    // A copy of nativephp/mobile-ui 0.9.0's manifest.
    return new Plugin(
        name: 'nativephp/mobile-ui',
        version: '0.9.0',
        path: '/test/path/nativephp/mobile-ui',
        manifest: PluginManifest::fromFile(__DIR__.'/../../Fixtures/plugins/mobile-ui/nativephp.json'),
        composerType: 'nativephp-ui-plugin',
    );
}

function registryWith(Plugin ...$plugins): PluginRegistry
{
    $discovery = Mockery::mock(PluginDiscovery::class);
    $discovery->shouldReceive('discover')->andReturn(collect($plugins));
    $discovery->shouldReceive('getAllBridgeFunctions')->andReturn([]);

    return new PluginRegistry($discovery);
}

afterEach(fn () => Mockery::close());

it('flags a plugin that declares a component for a type core renders', function (string $type) {
    $conflicts = registryWith(conflictPlugin('acme/chrome', [conflictComponent($type)]))->detectConflicts();

    expect($conflicts)->toBe([
        ['type' => 'core_component', 'value' => $type, 'plugins' => ['core', 'acme/chrome']],
    ]);
})->with(CoreRenderers::types());

it('finds no conflicts in mobile-ui\'s real manifest', function () {
    $plugin = mobileUiPlugin();

    expect($plugin->getComponents())->not->toBeEmpty()
        ->and(registryWith($plugin)->detectConflicts())->toBe([]);
});

it('still flags another plugin declaring a type mobile-ui provides', function () {
    $conflicts = registryWith(mobileUiPlugin(), conflictPlugin('acme/text', [conflictComponent('text')]))
        ->detectConflicts();

    expect($conflicts)->toBe([
        ['type' => 'component', 'value' => 'text', 'plugins' => ['nativephp/mobile-ui', 'acme/text']],
    ]);
});

it('explains a core conflict in the exception', function () {
    $e = new PluginConflictException([
        ['type' => 'core_component', 'value' => 'native_root_tabs', 'plugins' => ['core', 'acme/chrome']],
        ['type' => 'component', 'value' => 'text', 'plugins' => ['nativephp/mobile-ui', 'acme/text']],
    ]);

    expect($e->getMessage())
        ->toContain("Component 'native_root_tabs' is rendered by NativePHP core, so acme/chrome can't provide a renderer for it")
        ->toContain("Component 'text' is declared by both nativephp/mobile-ui and acme/text");
});

it('stops the build when a plugin claims a core-rendered type', function (string $compiler) {
    $registry = registryWith(conflictPlugin('acme/chrome', [conflictComponent('native_root_tabs')]));
    $basePath = sys_get_temp_dir().'/nativephp-core-conflict-'.uniqid();

    try {
        (new $compiler(new Filesystem, $registry, $basePath))->compile();
    } finally {
        (new Filesystem)->deleteDirectory($basePath);
    }
})->with([
    'ios' => IOSPluginCompiler::class,
    'android' => AndroidPluginCompiler::class,
])->throws(PluginConflictException::class, "Component 'native_root_tabs' is rendered by NativePHP core");

it('warns when native:plugin:register adds a plugin that claims a core-rendered type', function () {
    $command = new PluginRegisterCommand(new Filesystem, registryWith(mobileUiPlugin()));
    $detect = new ReflectionMethod($command, 'detectConflicts');

    expect($detect->invoke($command, conflictPlugin('acme/chrome', [conflictComponent('bottom_bar')])))
        ->toBe(["Component 'bottom_bar' is rendered by NativePHP core and can't be provided by a plugin"])
        ->and($detect->invoke($command, conflictPlugin('acme/text', [conflictComponent('text')])))
        ->toBe(["Component 'text' is already declared by 'nativephp/mobile-ui'"])
        ->and($detect->invoke($command, conflictPlugin('acme/widgets', [conflictComponent('acme_widget')])))
        ->toBe([]);
});
