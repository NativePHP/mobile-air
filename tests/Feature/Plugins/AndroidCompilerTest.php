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

        // Create minimal root build.gradle.kts (target for gradle plugin injection)
        $this->files->put(
            $this->testBasePath.'/android/build.gradle.kts',
            'plugins {
    alias(libs.plugins.android.application) apply false
    alias(libs.plugins.kotlin.android) apply false
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

        $generatedPath = $this->testBasePath.'/android/app/src/nativephp/kotlin/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';

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

        $generatedPath = $this->testBasePath.'/android/app/src/nativephp/kotlin/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';

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

        $generatedPath = $this->testBasePath.'/android/app/src/nativephp/kotlin/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';

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

        $generatedPath = $this->testBasePath.'/android/app/src/nativephp/kotlin/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';

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
        $copiedPath = $this->testBasePath.'/android/app/src/nativephp/kotlin/com/test/plugin/TestFunctions.kt';

        $this->assertFileExists($copiedPath);
    }

    /**
     * @test
     *
     * Copies in the generated source root from a plugin that is no longer
     * installed must be removed — a stale copy re-declares its classes and
     * breaks the Gradle build.
     */
    public function it_prunes_stale_generated_plugin_copies(): void
    {
        $staleDir = $this->testBasePath.'/android/app/src/nativephp/kotlin/com/nativephp/mobile/bridge/plugins/removed_plugin';
        $this->files->ensureDirectoryExists($staleDir);
        $this->files->put($staleDir.'/Zombie.kt', 'class Zombie {}');

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect());

        $this->compiler->compile();

        $this->assertDirectoryDoesNotExist($staleDir);
        $this->assertFileExists(
            $this->testBasePath.'/android/app/src/nativephp/kotlin/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt'
        );
    }

    /**
     * @test
     *
     * A copy left in the generated root from a source file the plugin has
     * since renamed or removed must not survive the next compile — this is
     * the rename scenario that produced ambiguous-declaration build errors.
     */
    public function it_prunes_stale_copies_when_plugin_source_is_renamed(): void
    {
        $pluginPath = $this->testBasePath.'/plugins/test-plugin';
        $kotlinPath = $pluginPath.'/resources/android/src';
        $this->files->ensureDirectoryExists($kotlinPath);
        $this->files->put($kotlinPath.'/NewName.kt', "package com.test.plugin\n\nclass Renderer {}");

        // Copy from a previous compile, before the file was renamed
        $rootPackageDir = $this->testBasePath.'/android/app/src/nativephp/kotlin/com/test/plugin';
        $this->files->ensureDirectoryExists($rootPackageDir);
        $this->files->put($rootPackageDir.'/OldName.kt', "package com.test.plugin\n\nclass Renderer {}");

        $plugin = $this->createTestPlugin([], $pluginPath);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $this->assertFileDoesNotExist($rootPackageDir.'/OldName.kt');
        $this->assertFileExists($rootPackageDir.'/NewName.kt');
    }

    /**
     * @test
     *
     * The pre-source-root generated dir under src/main/java (registrations
     * and no-package fallback copies) must be removed on compile.
     */
    public function it_removes_the_legacy_generated_dir_under_the_main_source_set(): void
    {
        $legacyDir = $this->testBasePath.'/android/app/src/main/java/com/nativephp/mobile/bridge/plugins';
        $this->files->ensureDirectoryExists($legacyDir.'/old_plugin');
        $this->files->put($legacyDir.'/PluginBridgeFunctionRegistration.kt', 'fun registerPluginBridgeFunctions() {}');
        $this->files->put($legacyDir.'/old_plugin/Zombie.kt', 'class Zombie {}');

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect());

        $this->compiler->compile();

        $this->assertDirectoryDoesNotExist($legacyDir);
    }

    /**
     * @test
     *
     * Copies that older compiler versions placed at package-derived paths
     * under src/main/java must be deleted (and their emptied package dirs
     * pruned) so they don't re-declare classes now copied into the
     * generated root.
     */
    public function it_removes_legacy_package_derived_copies(): void
    {
        $pluginPath = $this->testBasePath.'/plugins/test-plugin';
        $kotlinPath = $pluginPath.'/resources/android/src';
        $this->files->ensureDirectoryExists($kotlinPath);
        $this->files->put($kotlinPath.'/TestFunctions.kt', "package com.test.plugin\n\nobject TestFunctions {}");

        // Copy placed by a pre-source-root compiler version
        $legacyPackageDir = $this->testBasePath.'/android/app/src/main/java/com/test/plugin';
        $this->files->ensureDirectoryExists($legacyPackageDir);
        $this->files->put($legacyPackageDir.'/TestFunctions.kt', "package com.test.plugin\n\nobject TestFunctions {}");

        $plugin = $this->createTestPlugin([], $pluginPath);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $this->assertFileDoesNotExist($legacyPackageDir.'/TestFunctions.kt');
        // Emptied package dirs are pruned up to the source root
        $this->assertDirectoryDoesNotExist($this->testBasePath.'/android/app/src/main/java/com/test');
        // The copy now lives in the generated root
        $this->assertFileExists(
            $this->testBasePath.'/android/app/src/nativephp/kotlin/com/test/plugin/TestFunctions.kt'
        );
    }

    /**
     * @test
     *
     * Hand-written app code sharing a package dir with a legacy plugin copy
     * must survive the legacy cleanup — only the plugin's own files go.
     */
    public function it_preserves_user_code_next_to_legacy_copies(): void
    {
        $pluginPath = $this->testBasePath.'/plugins/test-plugin';
        $kotlinPath = $pluginPath.'/resources/android/src';
        $this->files->ensureDirectoryExists($kotlinPath);
        $this->files->put($kotlinPath.'/TestFunctions.kt', "package com.test.plugin\n\nobject TestFunctions {}");

        $legacyPackageDir = $this->testBasePath.'/android/app/src/main/java/com/test/plugin';
        $this->files->ensureDirectoryExists($legacyPackageDir);
        $this->files->put($legacyPackageDir.'/TestFunctions.kt', "package com.test.plugin\n\nobject TestFunctions {}");
        $this->files->put($legacyPackageDir.'/UserCode.kt', "package com.test.plugin\n\nclass UserCode {}");

        $plugin = $this->createTestPlugin([], $pluginPath);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $this->assertFileDoesNotExist($legacyPackageDir.'/TestFunctions.kt');
        $this->assertFileExists($legacyPackageDir.'/UserCode.kt');
    }

    /**
     * @test
     *
     * The generated source root must be declared in app/build.gradle.kts —
     * scaffolds installed before the root existed don't declare it, and
     * without it nothing in the root would compile. Recompiling must not
     * duplicate the declaration.
     */
    public function it_registers_the_generated_source_root_in_build_gradle(): void
    {
        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect());

        $this->compiler->compile();
        $this->compiler->compile();

        $content = $this->files->get($this->testBasePath.'/android/app/build.gradle.kts');

        $this->assertEquals(1, substr_count($content, 'java.srcDir("src/nativephp/kotlin")'));

        // Declaration must land inside the android {} block
        $this->assertMatchesRegularExpression(
            '/android\s*\{.*java\.srcDir\("src\/nativephp\/kotlin"\)/s',
            $content
        );
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
        $copiedPath = $this->testBasePath.'/android/app/src/nativephp/kotlin/com/test/plugin/subfolder/NestedClass.kt';

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
     * The dependency block is marker-delimited and rewritten in full, so a
     * project that is built repeatedly does not grow a header per build.
     *
     * The individual dependency lines were already guarded against
     * duplication; the header comment above them was not, so it was appended
     * on every single compile.
     */
    public function it_does_not_accumulate_a_dependency_header_per_build(): void
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

        $gradlePath = $this->testBasePath.'/android/app/build.gradle.kts';

        for ($i = 0; $i < 5; $i++) {
            $this->compiler->compile();
        }

        $content = $this->files->get($gradlePath);

        $this->assertEquals(1, substr_count($content, 'BEGIN nativephp-plugin-dependencies'));
        $this->assertEquals(1, substr_count($content, 'END nativephp-plugin-dependencies'));
        $this->assertStringNotContainsString('// NativePHP Plugin Dependencies', $content);
    }

    /**
     * @test
     *
     * Two consecutive compiles with nothing changed in between leave
     * build.gradle.kts byte-identical. Without that, no build downstream of
     * it can be reproducible.
     */
    public function it_leaves_the_build_file_byte_identical_across_compiles(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'dependencies' => [
                    'implementation' => ['com.example:library:1.0.0'],
                    'api' => ['com.example:api-lib:2.0.0'],
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $gradlePath = $this->testBasePath.'/android/app/build.gradle.kts';

        $this->compiler->compile();
        $after = $this->files->get($gradlePath);

        $this->compiler->compile();
        $this->compiler->compile();

        $this->assertSame($after, $this->files->get($gradlePath));
    }

    /**
     * @test
     *
     * Headers left behind by a version that did not delimit the block are
     * removed, so upgrading cleans a project up rather than freezing its
     * existing pile in place.
     */
    public function it_removes_headers_left_by_an_earlier_version(): void
    {
        $gradlePath = $this->testBasePath.'/android/app/build.gradle.kts';

        $this->files->put($gradlePath, str_replace(
            "dependencies {\n",
            "dependencies {\n".str_repeat("\n    // NativePHP Plugin Dependencies\n", 12),
            $this->files->get($gradlePath)
        ));

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

        $content = $this->files->get($gradlePath);

        $this->assertStringNotContainsString('// NativePHP Plugin Dependencies', $content);
        $this->assertStringContainsString('implementation("com.example:library:1.0.0")', $content);
    }

    /**
     * @test
     *
     * A platform() BOM is declared once. The presence check compared the raw
     * `platform(group:artifact:version)` form against a file that holds
     * `platform("group:artifact:version")`, so it never matched and every
     * build declared the BOM again.
     */
    public function it_declares_a_platform_bom_once(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'dependencies' => [
                    'implementation' => ['platform(com.google.firebase:firebase-bom:33.1.0)'],
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();
        $this->compiler->compile();
        $this->compiler->compile();

        $content = $this->files->get($this->testBasePath.'/android/app/build.gradle.kts');

        $this->assertEquals(
            1,
            substr_count($content, 'platform("com.google.firebase:firebase-bom:33.1.0")')
        );
    }

    /**
     * @test
     *
     * A dependency the app already declares by hand is not declared a second
     * time inside the generated block.
     */
    public function it_does_not_redeclare_a_dependency_the_app_already_has(): void
    {
        $gradlePath = $this->testBasePath.'/android/app/build.gradle.kts';

        $this->files->put($gradlePath, str_replace(
            "dependencies {\n",
            "dependencies {\n    implementation(\"com.example:library:1.0.0\")\n",
            $this->files->get($gradlePath)
        ));

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

        $content = $this->files->get($gradlePath);

        $this->assertEquals(1, substr_count($content, 'com.example:library:1.0.0'));
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

        $pluginsDir = $this->testBasePath.'/android/app/src/nativephp/kotlin/com/nativephp/mobile/bridge/plugins';
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
        $registrationFile = $this->testBasePath.'/android/app/src/nativephp/kotlin/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';
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

        $generatedPath = $this->testBasePath.'/android/app/src/nativephp/kotlin/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';
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

        $generatedPath = $this->testBasePath.'/android/app/src/nativephp/kotlin/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';
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

        $generatedPath = $this->testBasePath.'/android/app/src/nativephp/kotlin/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';
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

        $generatedPath = $this->testBasePath.'/android/app/src/nativephp/kotlin/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';
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
        $generatedPath = $this->testBasePath.'/android/app/src/nativephp/kotlin/com/nativephp/mobile/bridge/plugins/PluginBridgeFunctionRegistration.kt';
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
     * Application-level meta-data values should have ${ENV_VAR} placeholders
     * substituted from the environment, matching iOS info_plist handling.
     */
    public function it_substitutes_env_placeholders_in_application_meta_data(): void
    {
        putenv('NATIVEPHP_TEST_META_KEY=resolved-secret');
        $_ENV['NATIVEPHP_TEST_META_KEY'] = 'resolved-secret';

        try {
            $plugin = $this->createTestPlugin([
                'android' => [
                    'permissions' => [],
                    'dependencies' => [],
                    'meta_data' => [
                        ['name' => 'com.example.API_KEY', 'value' => '${NATIVEPHP_TEST_META_KEY}'],
                    ],
                ],
            ]);

            $this->mockRegistry
                ->shouldReceive('all')
                ->andReturn(collect([$plugin]));

            $this->compiler->compile();

            $manifestPath = $this->testBasePath.'/android/app/src/main/AndroidManifest.xml';
            $content = $this->files->get($manifestPath);

            $this->assertStringContainsString('<meta-data android:name="com.example.API_KEY" android:value="resolved-secret" />', $content);
            $this->assertStringNotContainsString('${NATIVEPHP_TEST_META_KEY}', $content);
        } finally {
            putenv('NATIVEPHP_TEST_META_KEY');
            unset($_ENV['NATIVEPHP_TEST_META_KEY']);
        }
    }

    /**
     * @test
     *
     * Component-level (service) meta-data values should also have ${ENV_VAR}
     * placeholders substituted from the environment.
     */
    public function it_substitutes_env_placeholders_in_service_meta_data(): void
    {
        putenv('NATIVEPHP_TEST_META_KEY=resolved-secret');
        $_ENV['NATIVEPHP_TEST_META_KEY'] = 'resolved-secret';

        try {
            $plugin = $this->createTestPlugin([
                'android' => [
                    'permissions' => [],
                    'dependencies' => [],
                    'services' => [
                        [
                            'name' => 'com.example.MyService',
                            'exported' => false,
                            'meta_data' => [
                                ['name' => 'com.example.API_KEY', 'value' => '${NATIVEPHP_TEST_META_KEY}'],
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

            $this->assertStringContainsString('<meta-data android:name="com.example.API_KEY" android:value="resolved-secret" />', $content);
            $this->assertStringNotContainsString('${NATIVEPHP_TEST_META_KEY}', $content);
        } finally {
            putenv('NATIVEPHP_TEST_META_KEY');
            unset($_ENV['NATIVEPHP_TEST_META_KEY']);
        }
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
     * @test
     *
     * Should add meta-data entries inside a receiver component using resource attribute (e.g. AppWidgetProvider).
     */
    public function it_adds_meta_data_with_resource_inside_receiver(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'permissions' => [],
                'dependencies' => [],
                'receivers' => [
                    [
                        'name' => 'com.example.MyWidgetProvider',
                        'exported' => true,
                        'meta-data' => [
                            ['name' => 'android.appwidget.provider', 'resource' => '@xml/my_widget_info'],
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

        $this->assertStringContainsString('<receiver', $content);
        $this->assertStringContainsString('<meta-data android:name="android.appwidget.provider" android:resource="@xml/my_widget_info" />', $content);
        $this->assertStringContainsString('</receiver>', $content);
    }

    /**
     * @test
     *
     * Should add meta-data entries inside a receiver component using value attribute.
     */
    public function it_adds_meta_data_with_value_inside_receiver(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'permissions' => [],
                'dependencies' => [],
                'receivers' => [
                    [
                        'name' => 'com.example.MyReceiver',
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

        $this->assertStringContainsString('<meta-data android:name="com.example.API_KEY" android:value="abc123" />', $content);
        $this->assertStringContainsString('</receiver>', $content);
    }

    /**
     * @test
     *
     * Should support both intent-filters and meta-data nested inside a receiver (AppWidgetProvider use-case).
     */
    public function it_supports_both_intent_filters_and_meta_data_in_receiver(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'permissions' => [],
                'dependencies' => [],
                'receivers' => [
                    [
                        'name' => 'com.example.MyWidgetProvider',
                        'exported' => true,
                        'intent-filters' => [
                            ['action' => 'android.appwidget.action.APPWIDGET_UPDATE'],
                        ],
                        'meta-data' => [
                            ['name' => 'android.appwidget.provider', 'resource' => '@xml/my_widget_info'],
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
        $this->assertStringContainsString('<action android:name="android.appwidget.action.APPWIDGET_UPDATE" />', $content);
        $this->assertStringContainsString('<meta-data android:name="android.appwidget.provider" android:resource="@xml/my_widget_info" />', $content);
        $this->assertStringContainsString('</receiver>', $content);
    }

    /**
     * @test
     *
     * Receiver without meta-data or intent-filters should remain self-closing.
     */
    public function it_keeps_receiver_self_closing_without_nested_content(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'permissions' => [],
                'dependencies' => [],
                'receivers' => [
                    [
                        'name' => 'com.example.SimpleReceiver',
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

        $this->assertStringContainsString('com.example.SimpleReceiver', $content);
        $this->assertMatchesRegularExpression('/<receiver[^>]*\/>/', $content);
        $this->assertStringNotContainsString('</receiver>', $content);
    }

    /**
     * Helper method to create a test Plugin instance.
     */
    /**
     * @test
     *
     * A plugin's android.gradle_plugins should be declared in the root
     * build.gradle.kts plugins {} block, defaulting to apply false.
     */
    public function it_declares_plugin_gradle_plugins_in_root_build_file(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'gradle_plugins' => [
                    ['id' => 'com.google.gms.google-services', 'version' => '4.4.3'],
                ],
            ],
        ]);

        $this->mockRegistry->shouldReceive('all')->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $content = $this->files->get($this->testBasePath.'/android/build.gradle.kts');

        $this->assertStringContainsString('// BEGIN nativephp-plugin-gradle-plugins', $content);
        $this->assertStringContainsString('id("com.google.gms.google-services") version "4.4.3" apply false', $content);
        $this->assertStringContainsString('// END nativephp-plugin-gradle-plugins', $content);

        // Declaration must land inside the plugins {} block
        $this->assertMatchesRegularExpression(
            '/plugins\s*\{.*id\("com\.google\.gms\.google-services"\).*\n\}/s',
            $content
        );
    }

    /** @test */
    public function it_applies_declared_gradle_plugins_to_the_app_module(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'gradle_plugins' => [
                    [
                        'id' => 'com.example.application-plugin',
                        'version' => '1.2.3',
                        'apply_to' => 'app',
                    ],
                ],
            ],
        ]);

        $this->mockRegistry->shouldReceive('all')->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $root = $this->files->get($this->testBasePath.'/android/build.gradle.kts');
        $app = $this->files->get($this->testBasePath.'/android/app/build.gradle.kts');

        $this->assertStringContainsString(
            'id("com.example.application-plugin") version "1.2.3" apply false',
            $root
        );
        $this->assertStringContainsString('// BEGIN nativephp-plugin-app-gradle-plugins', $app);
        $this->assertStringContainsString('id("com.example.application-plugin")', $app);
    }

    /** @test */
    public function it_clears_app_gradle_plugins_when_the_plugin_is_removed(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'gradle_plugins' => [
                    ['id' => 'com.example.application-plugin', 'version' => '1.2.3', 'apply_to' => 'app'],
                ],
            ],
        ]);

        $this->mockRegistry->shouldReceive('all')->andReturn(collect([$plugin]));
        $this->compiler->compile();

        $emptyRegistry = Mockery::mock(PluginRegistry::class);
        $emptyRegistry->shouldReceive('detectConflicts')->andReturn([]);
        $emptyRegistry->shouldReceive('all')->andReturn(collect([]));

        (new AndroidPluginCompiler($this->files, $emptyRegistry, $this->testBasePath))->compile();

        $app = $this->files->get($this->testBasePath.'/android/app/build.gradle.kts');

        $this->assertStringNotContainsString('com.example.application-plugin', $app);
        $this->assertStringNotContainsString('nativephp-plugin-app-gradle-plugins', $app);
    }

    /** @test */
    public function it_does_not_duplicate_a_manually_applied_app_gradle_plugin(): void
    {
        $appPath = $this->testBasePath.'/android/app/build.gradle.kts';
        $this->files->put($appPath, 'plugins {'.PHP_EOL
            .'    id("com.android.application")'.PHP_EOL
            .'    id("com.example.application-plugin")'.PHP_EOL
            .'}'.PHP_EOL);

        $plugin = $this->createTestPlugin([
            'android' => [
                'gradle_plugins' => [
                    ['id' => 'com.example.application-plugin', 'version' => '1.2.3', 'apply_to' => 'app'],
                ],
            ],
        ]);

        $this->mockRegistry->shouldReceive('all')->andReturn(collect([$plugin]));
        $this->compiler->compile();

        $app = $this->files->get($appPath);

        $this->assertSame(1, substr_count($app, 'id("com.example.application-plugin")'));
    }

    /**
     * @test
     *
     * Recompiling must not duplicate gradle plugin declarations.
     */
    public function it_does_not_duplicate_gradle_plugin_declarations(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'gradle_plugins' => [
                    ['id' => 'com.google.gms.google-services', 'version' => '4.4.3'],
                ],
            ],
        ]);

        $this->mockRegistry->shouldReceive('all')->andReturn(collect([$plugin]));

        $this->compiler->compile();
        $this->compiler->compile();

        $content = $this->files->get($this->testBasePath.'/android/build.gradle.kts');

        $this->assertEquals(1, substr_count($content, 'id("com.google.gms.google-services")'));
    }

    /**
     * @test
     *
     * Removing a plugin must clear its gradle plugin declaration on the
     * next compile.
     */
    public function it_clears_gradle_plugin_declarations_when_plugin_removed(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'gradle_plugins' => [
                    ['id' => 'com.google.gms.google-services', 'version' => '4.4.3'],
                ],
            ],
        ]);

        $this->mockRegistry->shouldReceive('all')->andReturn(collect([$plugin]));
        $this->compiler->compile();

        // Recompile with the plugin removed
        $emptyRegistry = Mockery::mock(PluginRegistry::class);
        $emptyRegistry->shouldReceive('detectConflicts')->andReturn([]);
        $emptyRegistry->shouldReceive('all')->andReturn(collect([]));

        (new AndroidPluginCompiler($this->files, $emptyRegistry, $this->testBasePath))->compile();

        $content = $this->files->get($this->testBasePath.'/android/build.gradle.kts');

        $this->assertStringNotContainsString('com.google.gms.google-services', $content);
    }

    /**
     * @test
     *
     * A gradle plugin id already declared outside the marker block (e.g.
     * hand-added by the developer) must not be declared a second time.
     */
    public function it_skips_gradle_plugins_already_declared_outside_markers(): void
    {
        $rootPath = $this->testBasePath.'/android/build.gradle.kts';
        $this->files->put(
            $rootPath,
            'plugins {
    alias(libs.plugins.android.application) apply false
    id("com.google.gms.google-services") version "4.4.0" apply false
}'
        );

        $plugin = $this->createTestPlugin([
            'android' => [
                'gradle_plugins' => [
                    ['id' => 'com.google.gms.google-services', 'version' => '4.4.3'],
                ],
            ],
        ]);

        $this->mockRegistry->shouldReceive('all')->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $content = $this->files->get($rootPath);

        $this->assertEquals(1, substr_count($content, 'id("com.google.gms.google-services")'));
        $this->assertStringContainsString('version "4.4.0"', $content);
    }

    /**
     * @test
     *
     * Malformed gradle plugin declarations (missing version, id with
     * characters that could inject Kotlin) are skipped.
     */
    public function it_skips_invalid_gradle_plugin_declarations(): void
    {
        $plugin = $this->createTestPlugin([
            'android' => [
                'gradle_plugins' => [
                    ['id' => 'com.example.missing-version'],
                    ['id' => 'bad") ; System.exit(0) //', 'version' => '1.0.0'],
                    ['id' => 'com.example.valid', 'version' => '2.0.0', 'apply' => true],
                ],
            ],
        ]);

        $this->mockRegistry->shouldReceive('all')->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $content = $this->files->get($this->testBasePath.'/android/build.gradle.kts');

        $this->assertStringNotContainsString('missing-version', $content);
        $this->assertStringNotContainsString('System.exit', $content);
        $this->assertStringContainsString('id("com.example.valid") version "2.0.0" apply true', $content);
    }

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
