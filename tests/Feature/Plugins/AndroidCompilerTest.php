<?php

namespace Tests\Feature\Plugins;

use Illuminate\Filesystem\Filesystem;
use Mockery;
use Native\Mobile\Plugins\Compilers\AndroidPluginCompiler;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use Native\Mobile\Plugins\PluginRegistry;
use Tests\TestCase;

/**
 * Feature tests for AndroidPluginCompiler.
 *
 * The Android compiler is responsible for:
 * - Generating PluginBridgeFunctionRegistration.kt with function registrations
 * - Copying Kotlin source files from plugins to the Android project
 * - Merging permissions into AndroidManifest.xml
 * - Adding Gradle dependencies to build.gradle.kts
 *
 * All tests should FAIL before implementation exists (red phase of TDD).
 *
 * @see /Users/shanerosenthal/Herd/mobile/docs/PLUGIN_SYSTEM_DESIGN.md
 */
class AndroidCompilerTest extends TestCase
{
    private AndroidPluginCompiler $compiler;

    private Filesystem $files;

    private string $testBasePath;

    private $mockRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->testBasePath = sys_get_temp_dir().'/nativephp-android-test-'.uniqid();
        $this->mockRegistry = Mockery::mock(PluginRegistry::class);

        // By default, assume no conflicts (individual tests can override)
        $this->mockRegistry->shouldReceive('detectConflicts')->andReturn([]);

        // Create test directory structure matching real Android project
        $this->files->ensureDirectoryExists(
            $this->testBasePath.'/android/app/src/main/java/com/nativephp/mobile/bridge'
        );
        $this->files->ensureDirectoryExists(
            $this->testBasePath.'/android/app/src/main'
        );

        // Create minimal AndroidManifest.xml
        $this->files->put(
            $this->testBasePath.'/android/app/src/main/AndroidManifest.xml',
            '<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <application android:label="TestApp">
        <activity android:name=".MainActivity">
        </activity>
    </application>
</manifest>'
        );

        // Create minimal build.gradle.kts
        $this->files->put(
            $this->testBasePath.'/android/app/build.gradle.kts',
            'plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "com.nativephp.mobile"
    compileSdk = 34
}

dependencies {
    implementation("androidx.core:core-ktx:1.12.0")
}'
        );

        $this->compiler = new AndroidPluginCompiler(
            $this->files,
            $this->mockRegistry,
            $this->testBasePath
        );
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->testBasePath);
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @test
     *
     * When no plugins are registered, should generate an empty registration file
     * with a placeholder comment.
     */
    public function it_generates_empty_registration_when_no_plugins(): void
    {
        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([]));

        $this->compiler->compile();

        $generatedPath = $this->testBasePath.'/android/app/src/main/java/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';

        $this->assertFileExists($generatedPath);

        $content = $this->files->get($generatedPath);
        $this->assertStringContainsString('// No plugins to register', $content);
        $this->assertStringContainsString('fun registerPluginBridgeFunctions', $content);
        $this->assertStringContainsString('package com.nativephp.mobile.bridge.plugins', $content);
    }

    /**
     * @test
     *
     * Should generate registration code for plugin bridge functions.
     */
    public function it_generates_registration_with_plugin_functions(): void
    {
        $plugin = $this->createTestPlugin([
            'bridge_functions' => [
                [
                    'name' => 'Test.Execute',
                    'android' => 'com.test.plugin.TestFunctions.Execute',
                    'ios' => 'TestFunctions.Execute',
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $generatedPath = $this->testBasePath.'/android/app/src/main/java/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';

        $content = $this->files->get($generatedPath);

        $this->assertStringContainsString('registry.register("Test.Execute"', $content);
        $this->assertStringContainsString('TestFunctions.Execute', $content);
    }

    /**
     * @test
     *
     * Should generate proper import statements for plugin classes.
     */
    public function it_generates_import_statements(): void
    {
        $plugin = $this->createTestPlugin([
            'bridge_functions' => [
                [
                    'name' => 'Test.Execute',
                    'android' => 'com.test.plugin.TestFunctions.Execute',
                    'ios' => 'TestFunctions.Execute',
                ],
                [
                    'name' => 'Test.GetData',
                    'android' => 'com.test.plugin.TestFunctions.GetData',
                    'ios' => 'TestFunctions.GetData',
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $generatedPath = $this->testBasePath.'/android/app/src/main/java/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';

        $content = $this->files->get($generatedPath);

        // Should import the TestFunctions object once
        $this->assertStringContainsString('import com.test.plugin.TestFunctions', $content);
    }

    /**
     * @test
     *
     * Should handle multiple plugins with multiple bridge functions.
     */
    public function it_generates_registration_for_multiple_plugins(): void
    {
        $pluginA = $this->createTestPlugin([
            'name' => 'vendor/plugin-a',
            'namespace' => 'PluginA',
            'bridge_functions' => [
                ['name' => 'PluginA.Func1', 'android' => 'com.vendor.a.FuncA1', 'ios' => 'PluginA.Func1'],
            ],
        ]);

        $pluginB = $this->createTestPlugin([
            'name' => 'vendor/plugin-b',
            'namespace' => 'PluginB',
            'bridge_functions' => [
                ['name' => 'PluginB.Func1', 'android' => 'com.vendor.b.FuncB1', 'ios' => 'PluginB.Func1'],
                ['name' => 'PluginB.Func2', 'android' => 'com.vendor.b.FuncB2', 'ios' => 'PluginB.Func2'],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$pluginA, $pluginB]));

        $this->compiler->compile();

        $generatedPath = $this->testBasePath.'/android/app/src/main/java/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';

        $content = $this->files->get($generatedPath);

        $this->assertStringContainsString('PluginA.Func1', $content);
        $this->assertStringContainsString('PluginB.Func1', $content);
        $this->assertStringContainsString('PluginB.Func2', $content);
    }

    /**
     * @test
     *
     * Should copy Kotlin source files from plugin to Android project.
     */
    public function it_copies_kotlin_source_files(): void
    {
        // Create plugin with Kotlin source
        $pluginPath = $this->testBasePath.'/plugins/test-plugin';
        $kotlinPath = $pluginPath.'/resources/android/src/com/test/plugin';
        $this->files->ensureDirectoryExists($kotlinPath);
        $this->files->put($kotlinPath.'/TestFunctions.kt', 'package com.test.plugin

object TestFunctions {
    class Execute : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            return mapOf("status" to "success")
        }
    }
}');

        $plugin = $this->createTestPlugin([], $pluginPath);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        // Check that source was copied (based on package declaration)
        $copiedPath = $this->testBasePath.'/android/app/src/main/java/com/test/plugin/TestFunctions.kt';

        $this->assertFileExists($copiedPath);
    }

    /**
     * @test
     *
     * Should preserve directory structure when copying Kotlin files.
     */
    public function it_preserves_directory_structure_when_copying(): void
    {
        $pluginPath = $this->testBasePath.'/plugins/test-plugin';
        $kotlinPath = $pluginPath.'/resources/android/src/com/test/plugin/subfolder';
        $this->files->ensureDirectoryExists($kotlinPath);
        $this->files->put($kotlinPath.'/NestedClass.kt', 'package com.test.plugin.subfolder');

        $plugin = $this->createTestPlugin([], $pluginPath);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        // File placed based on package declaration
        $copiedPath = $this->testBasePath.'/android/app/src/main/java/com/test/plugin/subfolder/NestedClass.kt';

        $this->assertFileExists($copiedPath);
    }

    /**
     * @test
     *
     * Should merge Android permissions into AndroidManifest.xml.
     */
    public function it_merges_android_permissions(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'permissions' => ['android.permission.CAMERA', 'android.permission.VIBRATE'],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $manifestPath = $this->testBasePath.'/android/app/src/main/AndroidManifest.xml';
        $content = $this->files->get($manifestPath);

        $this->assertStringContainsString('android.permission.CAMERA', $content);
        $this->assertStringContainsString('android.permission.VIBRATE', $content);
        $this->assertStringContainsString('<uses-permission', $content);
    }

    /**
     * @test
     *
     * Should not duplicate permissions that already exist.
     */
    public function it_does_not_duplicate_existing_permissions(): void
    {
        // Add a permission to the manifest first
        $manifestPath = $this->testBasePath.'/android/app/src/main/AndroidManifest.xml';
        $this->files->put($manifestPath, '<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <uses-permission android:name="android.permission.CAMERA" />
    <application android:label="TestApp">
    </application>
</manifest>');

        $plugin = $this->createTestPlugin([
            'android' => [
                'permissions' => ['android.permission.CAMERA'],  // Already exists
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $content = $this->files->get($manifestPath);

        // Should only appear once
        $count = substr_count($content, 'android.permission.CAMERA');
        $this->assertEquals(1, $count);
    }

    /**
     * @test
     *
     * Should not duplicate permissions when compiling multiple times.
     */
    public function it_does_not_duplicate_permissions_on_recompile(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'permissions' => ['android.permission.CAMERA'],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        // Compile twice
        $this->compiler->compile();
        $this->compiler->compile();

        $manifestPath = $this->testBasePath.'/android/app/src/main/AndroidManifest.xml';
        $content = $this->files->get($manifestPath);

        $count = substr_count($content, 'android.permission.CAMERA');
        $this->assertEquals(1, $count);
    }

    /**
     * @test
     *
     * Should add Gradle implementation dependencies.
     */
    public function it_adds_gradle_dependencies(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'dependencies' => [
                    'implementation' => ['com.example:library:1.0.0'],
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $gradlePath = $this->testBasePath.'/android/app/build.gradle.kts';
        $content = $this->files->get($gradlePath);

        $this->assertStringContainsString('implementation("com.example:library:1.0.0")', $content);
    }

    /**
     * @test
     *
     * Should add multiple Gradle dependency types (implementation, api, etc.).
     */
    public function it_adds_multiple_dependency_types(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'dependencies' => [
                    'implementation' => ['com.example:impl-lib:1.0.0'],
                    'api' => ['com.example:api-lib:2.0.0'],
                    'kapt' => ['com.example:kapt-lib:3.0.0'],
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $gradlePath = $this->testBasePath.'/android/app/build.gradle.kts';
        $content = $this->files->get($gradlePath);

        $this->assertStringContainsString('implementation("com.example:impl-lib:1.0.0")', $content);
        $this->assertStringContainsString('api("com.example:api-lib:2.0.0")', $content);
        $this->assertStringContainsString('kapt("com.example:kapt-lib:3.0.0")', $content);
    }

    /**
     * @test
     *
     * Should not duplicate Gradle dependencies.
     */
    public function it_does_not_duplicate_gradle_dependencies(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'dependencies' => [
                    'implementation' => ['com.example:library:1.0.0'],
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        // Compile twice
        $this->compiler->compile();
        $this->compiler->compile();

        $gradlePath = $this->testBasePath.'/android/app/build.gradle.kts';
        $content = $this->files->get($gradlePath);

        $count = substr_count($content, 'com.example:library:1.0.0');
        $this->assertEquals(1, $count);
    }

    /**
     * @test
     *
     * Should clean generated plugin files.
     */
    public function it_cleans_generated_files(): void
    {
        $plugin = $this->createTestPlugin();

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $pluginsDir = $this->testBasePath.'/android/app/src/main/java/com/nativephp/mobile/bridge/plugins';
        $this->assertDirectoryExists($pluginsDir);

        $this->compiler->clean();

        $this->assertDirectoryDoesNotExist($pluginsDir);
    }

    /**
     * @test
     *
     * Should return list of generated files.
     */
    public function it_returns_generated_files(): void
    {
        $plugin = $this->createTestPlugin([
            'bridge_functions' => [
                ['name' => 'Test.Execute', 'android' => 'com.test.Execute', 'ios' => 'Test.Execute'],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $files = $this->compiler->getGeneratedFiles();

        $this->assertIsArray($files);
        $this->assertNotEmpty($files);

        // Should include the registration file
        $registrationFile = $this->testBasePath.'/android/app/src/main/java/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';
        $this->assertContains($registrationFile, $files);
    }

    /**
     * @test
     *
     * Generated file should have proper AUTO-GENERATED header.
     */
    public function it_includes_auto_generated_header(): void
    {
        $plugin = $this->createTestPlugin([
            'bridge_functions' => [
                ['name' => 'Test.Execute', 'android' => 'com.test.Execute', 'ios' => 'Test.Execute'],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $generatedPath = $this->testBasePath.'/android/app/src/main/java/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';
        $content = $this->files->get($generatedPath);

        $this->assertStringContainsString('AUTO-GENERATED', $content);
        $this->assertStringContainsString('DO NOT EDIT', $content);
    }

    /**
     * @test
     *
     * Generated registration function should accept proper parameters.
     */
    public function it_generates_function_with_correct_signature(): void
    {
        $plugin = $this->createTestPlugin([
            'bridge_functions' => [
                ['name' => 'Test.Execute', 'android' => 'com.test.Execute', 'ios' => 'Test.Execute'],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $generatedPath = $this->testBasePath.'/android/app/src/main/java/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';
        $content = $this->files->get($generatedPath);

        // Should have FragmentActivity and Context parameters as per CLAUDE.md
        $this->assertStringContainsString('FragmentActivity', $content);
        $this->assertStringContainsString('Context', $content);
        $this->assertStringContainsString('BridgeFunctionRegistry', $content);
    }

    /**
     * @test
     *
     * Should handle bridge functions that need activity parameter.
     */
    public function it_generates_activity_parameter_functions(): void
    {
        $plugin = $this->createTestPlugin([
            'bridge_functions' => [
                [
                    'name' => 'Test.NeedsActivity',
                    'android' => 'com.test.TestFunctions.NeedsActivity',
                    'ios' => 'TestFunctions.NeedsActivity',
                    'android_params' => ['activity'],  // Indicates this needs activity
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $generatedPath = $this->testBasePath.'/android/app/src/main/java/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';
        $content = $this->files->get($generatedPath);

        // Should pass activity to the constructor
        $this->assertStringContainsString('(activity)', $content);
    }

    /**
     * @test
     *
     * Should handle bridge functions that need context parameter.
     */
    public function it_generates_context_parameter_functions(): void
    {
        $plugin = $this->createTestPlugin([
            'bridge_functions' => [
                [
                    'name' => 'Test.NeedsContext',
                    'android' => 'com.test.TestFunctions.NeedsContext',
                    'ios' => 'TestFunctions.NeedsContext',
                    'android_params' => ['context'],  // Indicates this needs context
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $generatedPath = $this->testBasePath.'/android/app/src/main/java/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';
        $content = $this->files->get($generatedPath);

        // Should pass context to the constructor
        $this->assertStringContainsString('(context)', $content);
    }

    /**
     * @test
     *
     * Should skip plugins without bridge functions for registration but still copy files.
     */
    public function it_handles_plugins_without_bridge_functions(): void
    {
        $plugin = $this->createTestPlugin([
            'bridge_functions' => [],  // No bridge functions
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        // Should still generate the file (even if mostly empty)
        $generatedPath = $this->testBasePath.'/android/app/src/main/java/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';
        $this->assertFileExists($generatedPath);
    }

    /**
     * @test
     *
     * Should add meta-data entries inside a service component using value attribute.
     */
    public function it_adds_meta_data_with_value_inside_service(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'permissions' => [],
                'dependencies' => [],
                'services' => [
                    [
                        'name' => 'com.example.MyService',
                        'exported' => false,
                        'meta_data' => [
                            ['name' => 'com.example.API_KEY', 'value' => 'abc123'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $manifestPath = $this->testBasePath.'/android/app/src/main/AndroidManifest.xml';
        $content = $this->files->get($manifestPath);

        $this->assertStringContainsString('<service', $content);
        $this->assertStringContainsString('<meta-data android:name="com.example.API_KEY" android:value="abc123" />', $content);
        // Service should use closing tag (not self-closing) when it has nested content
        $this->assertStringContainsString('</service>', $content);
    }

    /**
     * @test
     *
     * Should add meta-data entries inside a service component using resource attribute.
     */
    public function it_adds_meta_data_with_resource_inside_service(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'permissions' => [],
                'dependencies' => [],
                'services' => [
                    [
                        'name' => 'com.example.MyService',
                        'exported' => false,
                        'meta_data' => [
                            ['name' => 'com.example.ICON', 'resource' => '@drawable/icon'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $manifestPath = $this->testBasePath.'/android/app/src/main/AndroidManifest.xml';
        $content = $this->files->get($manifestPath);

        $this->assertStringContainsString('<meta-data android:name="com.example.ICON" android:resource="@drawable/icon" />', $content);
    }

    /**
     * @test
     *
     * Should handle boolean values in service meta-data.
     */
    public function it_handles_boolean_values_in_service_meta_data(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'permissions' => [],
                'dependencies' => [],
                'services' => [
                    [
                        'name' => 'com.example.MyService',
                        'exported' => false,
                        'meta_data' => [
                            ['name' => 'com.example.ENABLED', 'value' => true],
                            ['name' => 'com.example.DEBUG', 'value' => false],
                        ],
                    ],
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $manifestPath = $this->testBasePath.'/android/app/src/main/AndroidManifest.xml';
        $content = $this->files->get($manifestPath);

        $this->assertStringContainsString('android:value="true"', $content);
        $this->assertStringContainsString('android:value="false"', $content);
    }

    /**
     * @test
     *
     * Should support kebab-case meta-data key in service definitions.
     */
    public function it_supports_kebab_case_meta_data_key_in_service(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'permissions' => [],
                'dependencies' => [],
                'services' => [
                    [
                        'name' => 'com.example.MyService',
                        'exported' => false,
                        'meta-data' => [
                            ['name' => 'com.example.SETTING', 'value' => 'kebab-works'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $manifestPath = $this->testBasePath.'/android/app/src/main/AndroidManifest.xml';
        $content = $this->files->get($manifestPath);

        $this->assertStringContainsString('<meta-data android:name="com.example.SETTING" android:value="kebab-works" />', $content);
    }

    /**
     * @test
     *
     * Should support both intent-filters and meta-data nested inside a service.
     */
    public function it_supports_both_intent_filters_and_meta_data_in_service(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'permissions' => [],
                'dependencies' => [],
                'services' => [
                    [
                        'name' => 'com.example.MyService',
                        'exported' => true,
                        'intent_filters' => [
                            [
                                'action' => 'com.example.ACTION',
                                'category' => 'android.intent.category.DEFAULT',
                            ],
                        ],
                        'meta_data' => [
                            ['name' => 'com.example.API_KEY', 'value' => 'abc123'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $manifestPath = $this->testBasePath.'/android/app/src/main/AndroidManifest.xml';
        $content = $this->files->get($manifestPath);

        $this->assertStringContainsString('<intent-filter>', $content);
        $this->assertStringContainsString('<meta-data android:name="com.example.API_KEY" android:value="abc123" />', $content);
        $this->assertStringContainsString('</service>', $content);
    }

    /**
     * @test
     *
     * Service without meta-data or intent-filters should remain self-closing.
     */
    public function it_keeps_service_self_closing_without_nested_content(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'permissions' => [],
                'dependencies' => [],
                'services' => [
                    [
                        'name' => 'com.example.SimpleService',
                        'exported' => false,
                    ],
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $manifestPath = $this->testBasePath.'/android/app/src/main/AndroidManifest.xml';
        $content = $this->files->get($manifestPath);

        $this->assertStringContainsString('com.example.SimpleService', $content);
        // Self-closing service tag
        $this->assertMatchesRegularExpression('/<service[^>]*\/>/', $content);
        $this->assertStringNotContainsString('</service>', $content);
    }

    /**
     * @test
     *
     * Resource attribute should take precedence over value in service meta-data.
     */
    public function it_prefers_resource_over_value_in_service_meta_data(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'permissions' => [],
                'dependencies' => [],
                'services' => [
                    [
                        'name' => 'com.example.MyService',
                        'exported' => false,
                        'meta_data' => [
                            ['name' => 'com.example.ICON', 'resource' => '@drawable/icon', 'value' => 'ignored'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $manifestPath = $this->testBasePath.'/android/app/src/main/AndroidManifest.xml';
        $content = $this->files->get($manifestPath);

        // Resource should be used, not value
        $this->assertStringContainsString('android:resource="@drawable/icon"', $content);
        $this->assertStringNotContainsString('android:value="ignored"', $content);
    }

    /**
     * @test
     *
     * Should handle multiple meta-data entries inside a single service.
     */
    public function it_handles_multiple_meta_data_entries_in_service(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'permissions' => [],
                'dependencies' => [],
                'services' => [
                    [
                        'name' => 'com.example.MyService',
                        'exported' => false,
                        'meta_data' => [
                            ['name' => 'com.example.KEY_ONE', 'value' => 'first'],
                            ['name' => 'com.example.KEY_TWO', 'value' => 'second'],
                            ['name' => 'com.example.KEY_THREE', 'resource' => '@string/third'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $manifestPath = $this->testBasePath.'/android/app/src/main/AndroidManifest.xml';
        $content = $this->files->get($manifestPath);

        $this->assertStringContainsString('android:name="com.example.KEY_ONE" android:value="first"', $content);
        $this->assertStringContainsString('android:name="com.example.KEY_TWO" android:value="second"', $content);
        $this->assertStringContainsString('android:name="com.example.KEY_THREE" android:resource="@string/third"', $content);
    }

    /**
     * Helper method to create a test Plugin instance.
     */
    private function createTestPlugin(array $manifestData = [], ?string $path = null): Plugin
    {
        $defaultData = [
            'name' => 'test/plugin',
            'namespace' => 'TestPlugin',
            'bridge_functions' => [],
            'android' => ['permissions' => [], 'dependencies' => []],
            'ios' => ['info_plist' => [], 'dependencies' => []],
        ];

        $data = array_merge($defaultData, $manifestData);

        $manifest = new PluginManifest($data);

        return new Plugin(
            name: $data['name'],
            version: '1.0.0',
            path: $path ?? $this->testBasePath.'/plugins/test-plugin',
            manifest: $manifest
        );
    }
}
