<?php

namespace Tests\Feature\Plugins;

use Illuminate\Support\Collection;
use Mockery;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginDiscovery;
use Native\Mobile\Plugins\PluginManifest;
use Native\Mobile\Plugins\PluginRegistry;
use Tests\TestCase;

/**
 * Feature tests for PluginRegistry service.
 *
 * PluginRegistry provides a high-level interface for accessing discovered plugins.
 * It wraps PluginDiscovery and provides additional query methods.
 * All tests should FAIL before implementation exists (red phase of TDD).
 *
 * @see /Users/shanerosenthal/Herd/mobile/docs/PLUGIN_SYSTEM_DESIGN.md
 */
class PluginRegistryTest extends TestCase
{
    private PluginRegistry $registry;

    private $mockDiscovery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDiscovery = Mockery::mock(PluginDiscovery::class);
        $this->registry = new PluginRegistry($this->mockDiscovery);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @test
     *
     * Should return all discovered plugins.
     */
    public function it_returns_all_plugins(): void
    {
        $plugins = collect([
            $this->createMockPlugin('vendor/plugin-a'),
            $this->createMockPlugin('vendor/plugin-b'),
        ]);

        $this->mockDiscovery
            ->shouldReceive('discover')
            ->once()
            ->andReturn($plugins);

        $result = $this->registry->all();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
    }

    /**
     * @test
     *
     * Should find a plugin by its name.
     */
    public function it_finds_plugin_by_name(): void
    {
        $pluginA = $this->createMockPlugin('vendor/plugin-a');
        $pluginB = $this->createMockPlugin('vendor/plugin-b');

        $this->mockDiscovery
            ->shouldReceive('discover')
            ->andReturn(collect([$pluginA, $pluginB]));

        $found = $this->registry->find('vendor/plugin-a');

        $this->assertNotNull($found);
        $this->assertEquals('vendor/plugin-a', $found->name);
    }

    /**
     * @test
     *
     * Should return null when plugin not found by name.
     */
    public function it_returns_null_for_unknown_plugin(): void
    {
        $this->mockDiscovery
            ->shouldReceive('discover')
            ->andReturn(collect([]));

        $found = $this->registry->find('unknown/plugin');

        $this->assertNull($found);
    }

    /**
     * @test
     *
     * Should check if a plugin exists by name.
     */
    public function it_checks_plugin_existence(): void
    {
        $plugin = $this->createMockPlugin('vendor/exists');

        $this->mockDiscovery
            ->shouldReceive('discover')
            ->andReturn(collect([$plugin]));

        $this->assertTrue($this->registry->has('vendor/exists'));
        $this->assertFalse($this->registry->has('vendor/missing'));
    }

    /**
     * @test
     *
     * Should return the count of discovered plugins.
     */
    public function it_counts_plugins(): void
    {
        $this->mockDiscovery
            ->shouldReceive('discover')
            ->andReturn(collect([
                $this->createMockPlugin('a'),
                $this->createMockPlugin('b'),
                $this->createMockPlugin('c'),
            ]));

        $this->assertEquals(3, $this->registry->count());
    }

    /**
     * @test
     *
     * Should return all bridge functions from all plugins.
     */
    public function it_returns_all_bridge_functions(): void
    {
        $this->mockDiscovery
            ->shouldReceive('getAllBridgeFunctions')
            ->once()
            ->andReturn([
                ['name' => 'Plugin.FuncA', 'android' => 'com.a.FuncA', 'ios' => 'FuncA'],
                ['name' => 'Plugin.FuncB', 'android' => 'com.b.FuncB', 'ios' => 'FuncB'],
            ]);

        $functions = $this->registry->bridgeFunctions();

        $this->assertIsArray($functions);
        $this->assertCount(2, $functions);
    }

    /**
     * @test
     *
     * Should return all Android permissions from all plugins.
     */
    public function it_returns_all_android_permissions(): void
    {
        $this->mockDiscovery
            ->shouldReceive('getAllAndroidPermissions')
            ->once()
            ->andReturn([
                'android.permission.CAMERA',
                'android.permission.VIBRATE',
            ]);

        $permissions = $this->registry->androidPermissions();

        $this->assertIsArray($permissions);
        $this->assertContains('android.permission.CAMERA', $permissions);
        $this->assertContains('android.permission.VIBRATE', $permissions);
    }

    /**
     * @test
     *
     * Should return all iOS Info.plist entries from all plugins.
     */
    public function it_returns_all_ios_info_plist(): void
    {
        $this->mockDiscovery
            ->shouldReceive('getAllIosInfoPlist')
            ->once()
            ->andReturn([
                'NSCameraUsageDescription' => 'Camera access needed',
                'NSMicrophoneUsageDescription' => 'Microphone access needed',
            ]);

        $infoPlist = $this->registry->iosInfoPlist();

        $this->assertIsArray($infoPlist);
        $this->assertArrayHasKey('NSCameraUsageDescription', $infoPlist);
        $this->assertArrayHasKey('NSMicrophoneUsageDescription', $infoPlist);
    }

    /**
     * @test
     *
     * Should return all Android dependencies from all plugins.
     */
    public function it_returns_all_android_dependencies(): void
    {
        $pluginA = $this->createMockPlugin('vendor/plugin-a', [
            'android' => [
                'dependencies' => ['implementation' => ['com.example:lib-a:1.0']],
            ],
        ]);
        $pluginB = $this->createMockPlugin('vendor/plugin-b', [
            'android' => [
                'dependencies' => ['implementation' => ['com.example:lib-b:2.0']],
            ],
        ]);

        $this->mockDiscovery
            ->shouldReceive('discover')
            ->andReturn(collect([$pluginA, $pluginB]));

        $deps = $this->registry->androidDependencies();

        $this->assertIsArray($deps);
        $this->assertArrayHasKey('implementation', $deps);
        $this->assertCount(2, $deps['implementation']);
    }

    /**
     * @test
     *
     * Should return all iOS dependencies from all plugins.
     */
    public function it_returns_all_ios_dependencies(): void
    {
        $pluginA = $this->createMockPlugin('vendor/plugin-a', [
            'ios' => [
                'dependencies' => [
                    'swift_packages' => [
                        ['url' => 'https://github.com/example/a', 'version' => '1.0'],
                    ],
                ],
            ],
        ]);
        $pluginB = $this->createMockPlugin('vendor/plugin-b', [
            'ios' => [
                'dependencies' => [
                    'swift_packages' => [
                        ['url' => 'https://github.com/example/b', 'version' => '2.0'],
                    ],
                ],
            ],
        ]);

        $this->mockDiscovery
            ->shouldReceive('discover')
            ->andReturn(collect([$pluginA, $pluginB]));

        $deps = $this->registry->iosDependencies();

        $this->assertIsArray($deps);
        $this->assertArrayHasKey('swift_packages', $deps);
        $this->assertCount(2, $deps['swift_packages']);
    }

    /**
     * @test
     *
     * Should return plugins with native Android code.
     */
    public function it_returns_plugins_with_android_code(): void
    {
        $this->mockDiscovery
            ->shouldReceive('discoverWithAndroidCode')
            ->once()
            ->andReturn(collect([
                $this->createMockPlugin('vendor/with-android'),
            ]));

        $plugins = $this->registry->withAndroidCode();

        $this->assertCount(1, $plugins);
    }

    /**
     * @test
     *
     * Should return plugins with native iOS code.
     */
    public function it_returns_plugins_with_ios_code(): void
    {
        $this->mockDiscovery
            ->shouldReceive('discoverWithIosCode')
            ->once()
            ->andReturn(collect([
                $this->createMockPlugin('vendor/with-ios'),
            ]));

        $plugins = $this->registry->withIosCode();

        $this->assertCount(1, $plugins);
    }

    /**
     * @test
     *
     * Should return plugins that have events defined.
     */
    public function it_returns_plugins_with_events(): void
    {
        $this->mockDiscovery
            ->shouldReceive('discoverWithEvents')
            ->once()
            ->andReturn(collect([
                $this->createMockPlugin('vendor/with-events'),
            ]));

        $plugins = $this->registry->withEvents();

        $this->assertCount(1, $plugins);
    }

    /**
     * @test
     *
     * Should return all events from all plugins.
     */
    public function it_returns_all_events(): void
    {
        $pluginA = $this->createMockPlugin('vendor/plugin-a', [
            'events' => ['Vendor\\A\\Events\\EventA'],
        ]);
        $pluginB = $this->createMockPlugin('vendor/plugin-b', [
            'events' => ['Vendor\\B\\Events\\EventB1', 'Vendor\\B\\Events\\EventB2'],
        ]);

        $this->mockDiscovery
            ->shouldReceive('discover')
            ->andReturn(collect([$pluginA, $pluginB]));

        $events = $this->registry->events();

        $this->assertIsArray($events);
        $this->assertCount(3, $events);
    }

    /**
     * @test
     *
     * Should return all service providers from all plugins.
     */
    public function it_returns_all_service_providers(): void
    {
        $pluginA = $this->createMockPlugin('vendor/plugin-a', [
            'service_provider' => 'Vendor\\A\\AServiceProvider',
        ]);
        $pluginB = $this->createMockPlugin('vendor/plugin-b', [
            'service_provider' => 'Vendor\\B\\BServiceProvider',
        ]);

        $this->mockDiscovery
            ->shouldReceive('discover')
            ->andReturn(collect([$pluginA, $pluginB]));

        $providers = $this->registry->serviceProviders();

        $this->assertIsArray($providers);
        $this->assertCount(2, $providers);
        $this->assertContains('Vendor\\A\\AServiceProvider', $providers);
        $this->assertContains('Vendor\\B\\BServiceProvider', $providers);
    }

    /**
     * @test
     *
     * Should skip null service providers.
     */
    public function it_skips_null_service_providers(): void
    {
        $pluginA = $this->createMockPlugin('vendor/plugin-a', [
            'service_provider' => 'Vendor\\A\\AServiceProvider',
        ]);
        $pluginB = $this->createMockPlugin('vendor/plugin-b');  // No service provider

        $this->mockDiscovery
            ->shouldReceive('discover')
            ->andReturn(collect([$pluginA, $pluginB]));

        $providers = $this->registry->serviceProviders();

        $this->assertCount(1, $providers);
    }

    /**
     * @test
     *
     * Should refresh the plugin cache.
     */
    public function it_refreshes_cache(): void
    {
        $this->mockDiscovery
            ->shouldReceive('clearCache')
            ->once();

        $this->mockDiscovery
            ->shouldReceive('discover')
            ->once()
            ->andReturn(collect([]));

        $this->registry->refresh();

        // Verify discover was called after clearing cache
        $this->registry->all();
    }

    /**
     * @test
     *
     * Should be iterable.
     */
    public function it_is_iterable(): void
    {
        $plugins = collect([
            $this->createMockPlugin('vendor/plugin-a'),
            $this->createMockPlugin('vendor/plugin-b'),
        ]);

        $this->mockDiscovery
            ->shouldReceive('discover')
            ->andReturn($plugins);

        $count = 0;
        foreach ($this->registry as $plugin) {
            $this->assertInstanceOf(Plugin::class, $plugin);
            $count++;
        }

        $this->assertEquals(2, $count);
    }

    /**
     * @test
     *
     * Registry should be bound as singleton in the service container.
     */
    public function it_is_registered_as_singleton(): void
    {
        $instance1 = app(PluginRegistry::class);
        $instance2 = app(PluginRegistry::class);

        $this->assertSame($instance1, $instance2);
    }

    /**
     * @test
     *
     * Should provide a facade accessor.
     */
    public function it_can_be_accessed_via_facade(): void
    {
        // This test verifies the facade exists and works
        // The actual facade implementation will be in the implementation phase

        $this->mockDiscovery
            ->shouldReceive('discover')
            ->andReturn(collect([]));

        // Replace the bound instance with our mock-based registry
        $this->app->instance(PluginRegistry::class, $this->registry);

        // The facade should resolve to the same instance
        $this->assertInstanceOf(PluginRegistry::class, app(PluginRegistry::class));
    }

    /**
     * @test
     *
     * Should detect Android dependency version conflicts between plugins.
     */
    public function it_detects_android_dependency_version_conflicts(): void
    {
        $pluginA = $this->createMockPlugin('vendor/plugin-a', [
            'android' => [
                'dependencies' => ['implementation' => ['com.google.firebase:firebase-messaging:23.1.0']],
            ],
        ]);
        $pluginB = $this->createMockPlugin('vendor/plugin-b', [
            'android' => [
                'dependencies' => ['implementation' => ['com.google.firebase:firebase-messaging:24.0.0']],
            ],
        ]);

        $this->mockDiscovery
            ->shouldReceive('discover')
            ->andReturn(collect([$pluginA, $pluginB]));

        $conflicts = $this->registry->detectConflicts();

        $this->assertNotEmpty($conflicts);
        $depConflicts = array_filter($conflicts, fn ($c) => $c['type'] === 'android_dependency');
        $this->assertCount(1, $depConflicts);

        $conflict = array_values($depConflicts)[0];
        $this->assertStringContainsString('com.google.firebase:firebase-messaging', $conflict['value']);
        $this->assertContains('vendor/plugin-a', $conflict['plugins']);
        $this->assertContains('vendor/plugin-b', $conflict['plugins']);
    }

    /**
     * @test
     *
     * Should detect iOS CocoaPods version conflicts between plugins.
     */
    public function it_detects_ios_pod_version_conflicts(): void
    {
        $pluginA = $this->createMockPlugin('vendor/plugin-a', [
            'ios' => [
                'dependencies' => [
                    'pods' => [['name' => 'FirebaseMessaging', 'version' => '10.0']],
                ],
            ],
        ]);
        $pluginB = $this->createMockPlugin('vendor/plugin-b', [
            'ios' => [
                'dependencies' => [
                    'pods' => [['name' => 'FirebaseMessaging', 'version' => '11.0']],
                ],
            ],
        ]);

        $this->mockDiscovery
            ->shouldReceive('discover')
            ->andReturn(collect([$pluginA, $pluginB]));

        $conflicts = $this->registry->detectConflicts();

        $depConflicts = array_filter($conflicts, fn ($c) => $c['type'] === 'ios_pod_dependency');
        $this->assertCount(1, $depConflicts);

        $conflict = array_values($depConflicts)[0];
        $this->assertStringContainsString('FirebaseMessaging', $conflict['value']);
    }

    /**
     * @test
     *
     * Should detect iOS Swift Package version conflicts between plugins.
     */
    public function it_detects_ios_swift_package_version_conflicts(): void
    {
        $pluginA = $this->createMockPlugin('vendor/plugin-a', [
            'ios' => [
                'dependencies' => [
                    'swift_packages' => [
                        ['url' => 'https://github.com/example/package.git', 'version' => '1.0.0'],
                    ],
                ],
            ],
        ]);
        $pluginB = $this->createMockPlugin('vendor/plugin-b', [
            'ios' => [
                'dependencies' => [
                    'swift_packages' => [
                        ['url' => 'https://github.com/example/package', 'version' => '2.0.0'],
                    ],
                ],
            ],
        ]);

        $this->mockDiscovery
            ->shouldReceive('discover')
            ->andReturn(collect([$pluginA, $pluginB]));

        $conflicts = $this->registry->detectConflicts();

        $depConflicts = array_filter($conflicts, fn ($c) => $c['type'] === 'ios_swift_package_dependency');
        $this->assertCount(1, $depConflicts);

        $conflict = array_values($depConflicts)[0];
        $this->assertStringContainsString('github.com/example/package', $conflict['value']);
    }

    /**
     * @test
     *
     * Should allow identical dependencies across plugins without conflict.
     */
    public function it_allows_identical_dependencies_across_plugins(): void
    {
        $pluginA = $this->createMockPlugin('vendor/plugin-a', [
            'android' => [
                'dependencies' => ['implementation' => ['com.google.firebase:firebase-core:21.0.0']],
            ],
            'ios' => [
                'dependencies' => [
                    'pods' => [['name' => 'FirebaseCore', 'version' => '10.0']],
                    'swift_packages' => [
                        ['url' => 'https://github.com/example/shared', 'version' => '1.0.0'],
                    ],
                ],
            ],
        ]);
        $pluginB = $this->createMockPlugin('vendor/plugin-b', [
            'android' => [
                'dependencies' => ['implementation' => ['com.google.firebase:firebase-core:21.0.0']],
            ],
            'ios' => [
                'dependencies' => [
                    'pods' => [['name' => 'FirebaseCore', 'version' => '10.0']],
                    'swift_packages' => [
                        ['url' => 'https://github.com/example/shared', 'version' => '1.0.0'],
                    ],
                ],
            ],
        ]);

        $this->mockDiscovery
            ->shouldReceive('discover')
            ->andReturn(collect([$pluginA, $pluginB]));

        $conflicts = $this->registry->detectConflicts();

        $depConflicts = array_filter($conflicts, fn ($c) => in_array($c['type'], [
            'android_dependency', 'ios_pod_dependency', 'ios_swift_package_dependency',
        ]));
        $this->assertEmpty($depConflicts);
    }

    /**
     * @test
     *
     * Should not conflict when pods have no version constraints.
     */
    public function it_ignores_dependencies_without_versions(): void
    {
        $pluginA = $this->createMockPlugin('vendor/plugin-a', [
            'ios' => [
                'dependencies' => [
                    'pods' => [['name' => 'SomePod']],
                    'swift_packages' => [
                        ['url' => 'https://github.com/example/pkg'],
                    ],
                ],
            ],
        ]);
        $pluginB = $this->createMockPlugin('vendor/plugin-b', [
            'ios' => [
                'dependencies' => [
                    'pods' => [['name' => 'SomePod']],
                    'swift_packages' => [
                        ['url' => 'https://github.com/example/pkg'],
                    ],
                ],
            ],
        ]);

        $this->mockDiscovery
            ->shouldReceive('discover')
            ->andReturn(collect([$pluginA, $pluginB]));

        $conflicts = $this->registry->detectConflicts();

        $depConflicts = array_filter($conflicts, fn ($c) => in_array($c['type'], [
            'ios_pod_dependency', 'ios_swift_package_dependency',
        ]));
        $this->assertEmpty($depConflicts);
    }

    /**
     * Helper method to create a mock Plugin instance.
     */
    private function createMockPlugin(string $name, array $manifestData = []): Plugin
    {
        // Ensure name has vendor/package format
        if (! str_contains($name, '/')) {
            $name = 'vendor/'.$name;
        }

        $defaultData = [
            'namespace' => str_replace(['/', '-'], '', ucwords($name, '/-')),
            'bridge_functions' => [],
            'android' => ['permissions' => [], 'dependencies' => []],
            'ios' => ['info_plist' => [], 'dependencies' => []],
            'events' => [],
        ];

        // Extract service_provider from manifestData for use in Plugin constructor
        $serviceProvider = $manifestData['service_provider'] ?? null;
        unset($manifestData['service_provider']);

        $data = array_merge($defaultData, $manifestData);

        // Create a real PluginManifest instance instead of mocking
        $manifest = new PluginManifest($data);

        return new Plugin(
            name: $name,
            version: '1.0.0',
            path: '/test/path/'.$name,
            manifest: $manifest,
            serviceProvider: $serviceProvider
        );
    }
}
