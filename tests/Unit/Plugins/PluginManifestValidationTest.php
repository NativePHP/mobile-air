<?php

namespace Tests\Unit\Plugins;

use InvalidArgumentException;
use Native\Mobile\Plugins\PluginManifest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PluginManifest validation.
 *
 * These tests define the contract for the PluginManifest class which parses
 * and validates nativephp.json manifest files. All tests should FAIL before
 * implementation exists (red phase of TDD).
 *
 * @see /Users/shanerosenthal/Herd/mobile/docs/PLUGIN_SYSTEM_DESIGN.md
 */
class PluginManifestValidationTest extends TestCase
{
    /**
     * @test
     *
     * The manifest must have a 'namespace' field - this is used for code
     * generation and organizing plugin code.
     */
    public function it_requires_namespace_field(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('namespace');

        new PluginManifest([]);
    }

    /**
     * @test
     *
     * When all required fields are present, the manifest should parse correctly
     * and expose all properties.
     */
    public function it_parses_valid_manifest(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'TestPlugin',
            'bridge_functions' => [
                [
                    'name' => 'TestPlugin.Execute',
                    'android' => 'com.test.TestFunctions.Execute',
                    'ios' => 'TestFunctions.Execute',
                ],
            ],
        ]);

        $this->assertEquals('TestPlugin', $manifest->namespace);
        $this->assertCount(1, $manifest->bridgeFunctions);
    }

    /**
     * @test
     *
     * When optional fields are omitted, sensible defaults should be applied.
     */
    public function it_sets_default_values(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'TestPlugin',
        ]);

        $this->assertEquals([], $manifest->bridgeFunctions);
        $this->assertEquals([], $manifest->android);
        $this->assertEquals([], $manifest->ios);
        $this->assertEquals([], $manifest->events);
    }

    /**
     * @test
     *
     * Bridge functions must have a 'name' property that identifies how PHP
     * calls the function via nativephp_call().
     */
    public function it_validates_bridge_function_names(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name');

        new PluginManifest([
            'namespace' => 'TestPlugin',
            'bridge_functions' => [
                ['android' => 'com.test.Test'],  // Missing name
            ],
        ]);
    }

    /**
     * @test
     *
     * Bridge functions must have at least one platform implementation
     * (android or ios).
     */
    public function it_validates_bridge_function_has_platform_implementation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('implementation');

        new PluginManifest([
            'namespace' => 'TestPlugin',
            'bridge_functions' => [
                ['name' => 'Test.Execute'],  // Missing android and ios
            ],
        ]);
    }

    /**
     * @test
     *
     * The manifest can be loaded directly from a JSON file path.
     */
    public function it_loads_from_file(): void
    {
        $path = __DIR__.'/../../Fixtures/plugins/valid-plugin/nativephp.json';

        $manifest = PluginManifest::fromFile($path);

        $this->assertEquals('ValidPlugin', $manifest->namespace);
        $this->assertCount(2, $manifest->bridgeFunctions);
    }

    /**
     * @test
     *
     * Loading from a non-existent file should throw an exception.
     */
    public function it_throws_for_missing_file(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        PluginManifest::fromFile('/nonexistent/path/nativephp.json');
    }

    /**
     * @test
     *
     * Loading a file with invalid JSON should throw an exception.
     */
    public function it_throws_for_invalid_json(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $path = __DIR__.'/../../Fixtures/plugins/invalid-plugin-bad-json/nativephp.json';
        PluginManifest::fromFile($path);
    }

    /**
     * @test
     *
     * The namespace must be a valid PHP/Kotlin/Swift identifier.
     */
    public function it_validates_namespace_format(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('namespace');

        new PluginManifest([
            'namespace' => '123InvalidNamespace',  // Cannot start with number
        ]);
    }

    /**
     * @test
     *
     * Android permissions should be an array of strings.
     */
    public function it_parses_android_permissions(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'TestPlugin',
            'android' => [
                'permissions' => [
                    'android.permission.CAMERA',
                    'android.permission.VIBRATE',
                ],
            ],
        ]);

        $this->assertArrayHasKey('permissions', $manifest->android);
        $this->assertContains('android.permission.CAMERA', $manifest->android['permissions']);
        $this->assertContains('android.permission.VIBRATE', $manifest->android['permissions']);
    }

    /**
     * @test
     *
     * iOS info_plist should be key-value pairs (key = Info.plist key, value = description or config).
     */
    public function it_parses_ios_info_plist(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'TestPlugin',
            'ios' => [
                'info_plist' => [
                    'NSCameraUsageDescription' => 'This app needs camera access',
                    'NSMicrophoneUsageDescription' => 'This app needs microphone access',
                ],
            ],
        ]);

        $this->assertArrayHasKey('info_plist', $manifest->ios);
        $this->assertArrayHasKey('NSCameraUsageDescription', $manifest->ios['info_plist']);
        $this->assertEquals('This app needs camera access', $manifest->ios['info_plist']['NSCameraUsageDescription']);
    }

    /**
     * @test
     *
     * Android dependencies should support implementation, api, and other gradle configurations.
     */
    public function it_parses_android_dependencies(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'TestPlugin',
            'android' => [
                'dependencies' => [
                    'implementation' => ['com.google.mlkit:barcode-scanning:17.2.0'],
                    'api' => ['androidx.core:core-ktx:1.12.0'],
                ],
            ],
        ]);

        $this->assertArrayHasKey('dependencies', $manifest->android);
        $this->assertArrayHasKey('implementation', $manifest->android['dependencies']);
        $this->assertContains('com.google.mlkit:barcode-scanning:17.2.0', $manifest->android['dependencies']['implementation']);
    }

    /**
     * @test
     *
     * iOS dependencies should support Swift Package Manager packages.
     */
    public function it_parses_ios_swift_packages(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'TestPlugin',
            'ios' => [
                'dependencies' => [
                    'swift_packages' => [
                        [
                            'url' => 'https://github.com/example/package',
                            'version' => '1.0.0',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertArrayHasKey('dependencies', $manifest->ios);
        $this->assertArrayHasKey('swift_packages', $manifest->ios['dependencies']);
        $this->assertCount(1, $manifest->ios['dependencies']['swift_packages']);
    }

    /**
     * @test
     *
     * Events should be fully qualified class names.
     */
    public function it_parses_events(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'TestPlugin',
            'events' => [
                'Test\\Plugin\\Events\\DataReceived',
                'Test\\Plugin\\Events\\ProcessComplete',
            ],
        ]);

        $this->assertCount(2, $manifest->events);
        $this->assertContains('Test\\Plugin\\Events\\DataReceived', $manifest->events);
    }

    /**
     * @test
     */
    public function it_parses_component_element_events(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'TestPlugin',
            'components' => [
                [
                    'type' => 'markdown',
                    'element' => 'Vendor\\Markdown\\Elements\\Markdown',
                    'blade' => 'Vendor\\Markdown\\Components\\Markdown',
                    'ios_renderer' => 'MarkdownRenderer',
                    'element_events' => ['link', 'scan'],
                ],
            ],
        ]);

        $this->assertSame(['link', 'scan'], $manifest->components[0]['element_events']);
    }

    /**
     * @test
     */
    public function it_rejects_invalid_element_events_names(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('element_events');

        new PluginManifest([
            'namespace' => 'TestPlugin',
            'components' => [
                [
                    'type' => 'markdown',
                    'element' => 'Vendor\\Markdown\\Elements\\Markdown',
                    'blade' => 'Vendor\\Markdown\\Components\\Markdown',
                    'ios_renderer' => 'MarkdownRenderer',
                    'element_events' => ['not a valid name'],
                ],
            ],
        ]);
    }

    /**
     * @test
     */
    public function it_rejects_non_array_element_events(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('element_events');

        new PluginManifest([
            'namespace' => 'TestPlugin',
            'components' => [
                [
                    'type' => 'markdown',
                    'element' => 'Vendor\\Markdown\\Elements\\Markdown',
                    'blade' => 'Vendor\\Markdown\\Components\\Markdown',
                    'ios_renderer' => 'MarkdownRenderer',
                    'element_events' => 'link',
                ],
            ],
        ]);
    }

    /**
     * @test
     *
     * The manifest should be convertible to an array for serialization.
     */
    public function it_converts_to_array(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'TestPlugin',
        ]);

        $array = $manifest->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('TestPlugin', $array['namespace']);
        $this->assertArrayHasKey('bridge_functions', $array);
        $this->assertArrayHasKey('android', $array);
        $this->assertArrayHasKey('ios', $array);
    }

    /**
     * @test
     *
     * The manifest should be JSON serializable for debugging/logging.
     */
    public function it_is_json_serializable(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'TestPlugin',
        ]);

        $json = json_encode($manifest);

        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertEquals('TestPlugin', $decoded['namespace']);
    }
}
