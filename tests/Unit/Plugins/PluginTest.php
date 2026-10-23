<?php

namespace Tests\Unit\Plugins;

use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Plugin DTO class.
 *
 * The Plugin class is a Data Transfer Object that represents a discovered plugin.
 * It wraps the manifest and provides convenience methods for accessing plugin data.
 * All tests should FAIL before implementation exists (red phase of TDD).
 *
 * @see /Users/shanerosenthal/Herd/mobile/docs/PLUGIN_SYSTEM_DESIGN.md
 */
class PluginTest extends TestCase
{
    private Plugin $plugin;

    private Plugin $pluginWithNativeCode;

    private string $validPluginPath;

    private string $nativeCodePluginPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validPluginPath = __DIR__.'/../../Fixtures/plugins/valid-plugin';
        $this->nativeCodePluginPath = __DIR__.'/../../Fixtures/plugins/plugin-with-native-code';

        // Create a plugin from the valid fixture
        $manifest = new PluginManifest([
            'namespace' => 'ValidPlugin',
            'bridge_functions' => [
                [
                    'name' => 'ValidPlugin.Execute',
                    'android' => 'com.test.validplugin.ValidPluginFunctions.Execute',
                    'ios' => 'ValidPluginFunctions.Execute',
                ],
                [
                    'name' => 'ValidPlugin.GetStatus',
                    'android' => 'com.test.validplugin.ValidPluginFunctions.GetStatus',
                    'ios' => 'ValidPluginFunctions.GetStatus',
                ],
            ],
            'android' => [
                'permissions' => ['android.permission.VIBRATE'],
                'dependencies' => ['implementation' => ['com.example:test:1.0.0']],
            ],
            'ios' => [
                'info_plist' => ['NSCameraUsageDescription' => 'Test camera usage'],
                'dependencies' => ['swift_packages' => []],
            ],
            'events' => ['Test\\ValidPlugin\\Events\\TestEvent'],
        ]);

        $this->plugin = new Plugin(
            name: 'test/valid-plugin',
            version: '1.0.0',
            path: $this->validPluginPath,
            manifest: $manifest,
            description: 'A valid test plugin',
            serviceProvider: 'Test\\ValidPlugin\\ValidPluginServiceProvider'
        );

        // Create a plugin with native code
        $nativeManifest = new PluginManifest([
            'namespace' => 'NativeCodePlugin',
            'bridge_functions' => [
                [
                    'name' => 'NativeCode.Execute',
                    'android' => 'com.test.plugin.TestFunctions.Execute',
                    'ios' => 'TestFunctions.Execute',
                ],
            ],
            'android' => [
                'permissions' => ['android.permission.CAMERA', 'android.permission.RECORD_AUDIO'],
                'dependencies' => ['implementation' => ['com.google.mlkit:barcode-scanning:17.2.0']],
            ],
            'ios' => [
                'info_plist' => [
                    'NSCameraUsageDescription' => 'This plugin needs camera access',
                    'NSMicrophoneUsageDescription' => 'This plugin needs microphone access',
                ],
                'dependencies' => ['swift_packages' => []],
            ],
        ]);

        $this->pluginWithNativeCode = new Plugin(
            name: 'test/native-code-plugin',
            version: '1.0.0',
            path: $this->nativeCodePluginPath,
            manifest: $nativeManifest
        );
    }

    /**
     * @test
     *
     * The plugin should expose its name (composer package name).
     */
    public function it_returns_name(): void
    {
        $this->assertEquals('test/valid-plugin', $this->plugin->name);
    }

    /**
     * @test
     *
     * The plugin should expose its version.
     */
    public function it_returns_version(): void
    {
        $this->assertEquals('1.0.0', $this->plugin->version);
    }

    /**
     * @test
     *
     * The plugin should expose its filesystem path.
     */
    public function it_returns_path(): void
    {
        $this->assertEquals($this->validPluginPath, $this->plugin->path);
    }

    /**
     * @test
     *
     * The plugin should provide access to its manifest.
     */
    public function it_returns_manifest(): void
    {
        $this->assertInstanceOf(PluginManifest::class, $this->plugin->manifest);
    }

    /**
     * @test
     *
     * The plugin should expose the namespace from its manifest.
     */
    public function it_returns_namespace(): void
    {
        $this->assertEquals('ValidPlugin', $this->plugin->getNamespace());
    }

    /**
     * @test
     *
     * The plugin should expose its bridge functions.
     */
    public function it_returns_bridge_functions(): void
    {
        $functions = $this->plugin->getBridgeFunctions();

        $this->assertCount(2, $functions);
        $this->assertEquals('ValidPlugin.Execute', $functions[0]['name']);
        $this->assertEquals('ValidPlugin.GetStatus', $functions[1]['name']);
    }

    /**
     * @test
     *
     * The plugin should expose Android permissions.
     */
    public function it_returns_android_permissions(): void
    {
        $permissions = $this->plugin->getAndroidPermissions();

        $this->assertIsArray($permissions);
        $this->assertContains('android.permission.VIBRATE', $permissions);
    }

    /**
     * @test
     *
     * Declared android.proguard_rules are returned verbatim; absent key
     * yields an empty list.
     */
    public function it_returns_android_proguard_rules(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'RulesPlugin',
            'android' => [
                'proguard_rules' => ['-dontwarn org.slf4j.impl.StaticLoggerBinder'],
            ],
        ]);
        $plugin = new Plugin('vendor/rules-plugin', '1.0.0', $this->validPluginPath, $manifest);

        $this->assertSame(
            ['-dontwarn org.slf4j.impl.StaticLoggerBinder'],
            $plugin->getAndroidProguardRules()
        );

        $this->assertSame([], $this->plugin->getAndroidProguardRules());
    }

    /** @test */
    public function it_returns_platform_project_files(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'FilesPlugin',
            'android' => [
                'project_files' => [
                    ['sources' => ['service.json'], 'destination' => 'app/service.json'],
                ],
            ],
            'ios' => [
                'project_files' => [
                    ['sources' => ['Service.plist'], 'destination' => 'NativePHP/Service.plist'],
                ],
            ],
        ]);
        $plugin = new Plugin('vendor/files-plugin', '1.0.0', $this->validPluginPath, $manifest);

        $this->assertSame($manifest->android['project_files'], $plugin->getProjectFiles('android'));
        $this->assertSame($manifest->ios['project_files'], $plugin->getProjectFiles('ios'));
        $this->assertSame([], $plugin->getProjectFiles('windows'));
    }

    /**
     * @test
     *
     * Multiple Android permissions should all be returned.
     */
    public function it_returns_multiple_android_permissions(): void
    {
        $permissions = $this->pluginWithNativeCode->getAndroidPermissions();

        $this->assertCount(2, $permissions);
        $this->assertContains('android.permission.CAMERA', $permissions);
        $this->assertContains('android.permission.RECORD_AUDIO', $permissions);
    }

    /**
     * @test
     *
     * The plugin should expose iOS Info.plist entries.
     */
    public function it_returns_ios_info_plist(): void
    {
        $infoPlist = $this->plugin->getIosInfoPlist();

        $this->assertIsArray($infoPlist);
        $this->assertArrayHasKey('NSCameraUsageDescription', $infoPlist);
        $this->assertEquals('Test camera usage', $infoPlist['NSCameraUsageDescription']);
    }

    /**
     * @test
     *
     * Multiple iOS Info.plist entries should all be returned.
     */
    public function it_returns_multiple_ios_info_plist_entries(): void
    {
        $infoPlist = $this->pluginWithNativeCode->getIosInfoPlist();

        $this->assertCount(2, $infoPlist);
        $this->assertArrayHasKey('NSCameraUsageDescription', $infoPlist);
        $this->assertArrayHasKey('NSMicrophoneUsageDescription', $infoPlist);
    }

    /**
     * @test
     *
     * The plugin should expose Android dependencies (gradle dependencies).
     */
    public function it_returns_android_dependencies(): void
    {
        $deps = $this->plugin->getAndroidDependencies();

        $this->assertIsArray($deps);
        $this->assertArrayHasKey('implementation', $deps);
        $this->assertContains('com.example:test:1.0.0', $deps['implementation']);
    }

    /**
     * @test
     *
     * The plugin should expose iOS dependencies (Swift packages).
     */
    public function it_returns_ios_dependencies(): void
    {
        $deps = $this->plugin->getIosDependencies();

        $this->assertIsArray($deps);
        $this->assertArrayHasKey('swift_packages', $deps);
    }

    /**
     * @test
     *
     * The plugin should return the expected Android source path.
     * Supports both nested (resources/android/src) and flat (resources/android) structures.
     */
    public function it_returns_android_source_path(): void
    {
        $path = $this->plugin->getAndroidSourcePath();

        $this->assertStringStartsWith($this->validPluginPath, $path);
        $this->assertTrue(
            str_ends_with($path, '/resources/android/src') || str_ends_with($path, '/resources/android'),
            "Path should end with /resources/android/src or /resources/android, got: $path"
        );
    }

    /**
     * @test
     *
     * The plugin should return the expected iOS source path.
     * Supports both nested (resources/ios/Sources) and flat (resources/ios) structures.
     */
    public function it_returns_ios_source_path(): void
    {
        $path = $this->plugin->getIosSourcePath();

        $this->assertStringStartsWith($this->validPluginPath, $path);
        $this->assertTrue(
            str_ends_with($path, '/resources/ios/Sources') || str_ends_with($path, '/resources/ios'),
            "Path should end with /resources/ios/Sources or /resources/ios, got: $path"
        );
    }

    /**
     * @test
     *
     * The plugin should detect whether it has Android native code.
     * The valid-plugin fixture does NOT have native code (no .kt files).
     */
    public function it_detects_absence_of_android_code(): void
    {
        $this->assertFalse($this->plugin->hasAndroidCode());
    }

    /**
     * @test
     *
     * The plugin should detect whether it has iOS native code.
     * The valid-plugin fixture does NOT have native code (no .swift files).
     */
    public function it_detects_absence_of_ios_code(): void
    {
        $this->assertFalse($this->plugin->hasIosCode());
    }

    /**
     * @test
     *
     * The plugin should detect presence of Android native code.
     * The plugin-with-native-code fixture HAS native code.
     */
    public function it_detects_presence_of_android_code(): void
    {
        $this->assertTrue($this->pluginWithNativeCode->hasAndroidCode());
    }

    /**
     * @test
     *
     * The plugin should detect presence of iOS native code.
     * The plugin-with-native-code fixture HAS native code.
     */
    public function it_detects_presence_of_ios_code(): void
    {
        $this->assertTrue($this->pluginWithNativeCode->hasIosCode());
    }

    /**
     * @test
     *
     * The plugin should expose its events.
     */
    public function it_returns_events(): void
    {
        $events = $this->plugin->getEvents();

        $this->assertIsArray($events);
        $this->assertContains('Test\\ValidPlugin\\Events\\TestEvent', $events);
    }

    /**
     * @test
     *
     * The plugin should expose its service provider class name.
     */
    public function it_returns_service_provider(): void
    {
        $provider = $this->plugin->getServiceProvider();

        $this->assertEquals('Test\\ValidPlugin\\ValidPluginServiceProvider', $provider);
    }

    /**
     * @test
     *
     * A plugin without a service provider should return null.
     */
    public function it_returns_null_for_missing_service_provider(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'NoProvider',
        ]);

        $plugin = new Plugin(
            name: 'test/no-provider',
            version: '1.0.0',
            path: '/tmp/test',
            manifest: $manifest
        );

        $this->assertNull($plugin->getServiceProvider());
    }

    /**
     * @test
     *
     * The plugin should return an empty array for bridge functions if none defined.
     */
    public function it_returns_empty_array_when_no_bridge_functions(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'NoFunctions',
        ]);

        $plugin = new Plugin(
            name: 'test/no-functions',
            version: '1.0.0',
            path: '/tmp/test',
            manifest: $manifest
        );

        $this->assertEquals([], $plugin->getBridgeFunctions());
    }

    /**
     * @test
     *
     * The plugin should return an empty array for permissions if none defined.
     */
    public function it_returns_empty_array_when_no_permissions(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'NoPerms',
        ]);

        $plugin = new Plugin(
            name: 'test/no-perms',
            version: '1.0.0',
            path: '/tmp/test',
            manifest: $manifest
        );

        $this->assertEquals([], $plugin->getAndroidPermissions());
        $this->assertEquals([], $plugin->getIosInfoPlist());
    }

    /**
     * @test
     *
     * The plugin should be convertible to an array for debugging/logging.
     */
    public function it_converts_to_array(): void
    {
        $array = $this->plugin->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('test/valid-plugin', $array['name']);
        $this->assertEquals('1.0.0', $array['version']);
        $this->assertEquals($this->validPluginPath, $array['path']);
        $this->assertArrayHasKey('manifest', $array);
    }

    /**
     * @test
     *
     * The plugin should provide the description from its manifest.
     */
    public function it_returns_description(): void
    {
        $this->assertEquals('A valid test plugin', $this->plugin->getDescription());
    }

    /**
     * @test
     *
     * The plugin should return all Android Kotlin source files.
     */
    public function it_returns_android_source_files(): void
    {
        $files = $this->pluginWithNativeCode->getAndroidSourceFiles();

        $this->assertIsArray($files);
        $this->assertNotEmpty($files);

        // Should find TestFunctions.kt
        $found = false;
        foreach ($files as $file) {
            if (str_ends_with($file, 'TestFunctions.kt')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Should find TestFunctions.kt in Android source files');
    }

    /**
     * @test
     *
     * The plugin should return all iOS Swift source files.
     */
    public function it_returns_ios_source_files(): void
    {
        $files = $this->pluginWithNativeCode->getIosSourceFiles();

        $this->assertIsArray($files);
        $this->assertNotEmpty($files);

        // Should find TestFunctions.swift
        $found = false;
        foreach ($files as $file) {
            if (str_ends_with($file, 'TestFunctions.swift')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Should find TestFunctions.swift in iOS source files');
    }

    /**
     * @test
     *
     * Android source files should be empty for plugin without native code.
     */
    public function it_returns_empty_android_source_files_when_none_exist(): void
    {
        $files = $this->plugin->getAndroidSourceFiles();

        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }

    /**
     * @test
     *
     * iOS source files should be empty for plugin without native code.
     */
    public function it_returns_empty_ios_source_files_when_none_exist(): void
    {
        $files = $this->plugin->getIosSourceFiles();

        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }

    /**
     * @test
     *
     * A manifest that says nothing about platforms supports both, so plugins
     * written before the key was read keep working.
     */
    public function it_defaults_to_both_platforms(): void
    {
        $this->assertEquals(['android', 'ios'], $this->plugin->getPlatforms());
        $this->assertTrue($this->plugin->supportsPlatform('android'));
        $this->assertTrue($this->plugin->supportsPlatform('ios'));
    }

    /**
     * @test
     */
    public function it_reports_the_declared_platforms(): void
    {
        $plugin = $this->pluginForPlatforms(['android']);

        $this->assertEquals(['android'], $plugin->getPlatforms());
        $this->assertTrue($plugin->supportsPlatform('android'));
        $this->assertFalse($plugin->supportsPlatform('ios'));
    }

    /**
     * @test
     *
     * Swift under resources/ios/ does not make a plugin an iOS plugin. The
     * manifest decides.
     */
    public function it_has_no_ios_code_when_ios_is_not_a_declared_platform(): void
    {
        $plugin = $this->pluginForPlatforms(['android'], $this->nativeCodePluginPath);

        $this->assertTrue($plugin->hasAndroidCode());
        $this->assertFalse($plugin->hasIosCode());
        $this->assertEmpty($plugin->getIosSourceFiles());
    }

    /**
     * @test
     */
    public function it_has_no_android_code_when_android_is_not_a_declared_platform(): void
    {
        $plugin = $this->pluginForPlatforms(['ios'], $this->nativeCodePluginPath);

        $this->assertFalse($plugin->hasAndroidCode());
        $this->assertTrue($plugin->hasIosCode());
        $this->assertEmpty($plugin->getAndroidSourceFiles());
    }

    /**
     * @test
     *
     * A typo is rejected rather than ignored: silently dropping "IOS" would
     * turn a spelling mistake into "this plugin has no iOS code".
     */
    public function it_rejects_an_unknown_platform(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown platform: windows');

        new PluginManifest([
            'namespace' => 'Test',
            'platforms' => ['windows'],
        ]);
    }

    /**
     * @test
     */
    public function it_normalizes_platform_casing_and_duplicates(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'Test',
            'platforms' => ['iOS', ' ios ', 'Android'],
        ]);

        $this->assertEquals(['ios', 'android'], $manifest->platforms);
    }

    /**
     * @test
     */
    public function it_returns_an_empty_ios_sources_list_by_default(): void
    {
        $this->assertEquals([], $this->plugin->getIosSources());
    }

    /**
     * @test
     */
    public function it_returns_the_declared_ios_sources(): void
    {
        $manifest = new PluginManifest([
            'namespace' => 'Test',
            'ios' => ['sources' => ['TestFunctions.swift', 'Renderers', '', 5]],
        ]);

        $plugin = new Plugin(
            name: 'test/plugin',
            version: '1.0.0',
            path: $this->nativeCodePluginPath,
            manifest: $manifest
        );

        $this->assertEquals(['TestFunctions.swift', 'Renderers'], $plugin->getIosSources());
    }

    /**
     * @param  list<string>  $platforms
     */
    private function pluginForPlatforms(array $platforms, ?string $path = null): Plugin
    {
        return new Plugin(
            name: 'test/plugin',
            version: '1.0.0',
            path: $path ?? $this->validPluginPath,
            manifest: new PluginManifest([
                'namespace' => 'Test',
                'platforms' => $platforms,
            ])
        );
    }
}
