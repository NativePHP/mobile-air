<?php

namespace Tests\Feature\Plugins;

use Illuminate\Filesystem\Filesystem;
use Mockery;
use Native\Mobile\Plugins\Compilers\IOSPluginCompiler;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use Native\Mobile\Plugins\PluginRegistry;
use Tests\TestCase;

/**
 * Feature tests for IOSPluginCompiler.
 *
 * The iOS compiler is responsible for:
 * - Generating PluginBridgeFunctionRegistration.swift with function registrations
 * - Copying Swift source files from plugins to the iOS project
 * - Merging permissions into Info.plist
 * - Adding Swift Package Manager dependencies
 *
 * All tests should FAIL before implementation exists (red phase of TDD).
 *
 * @see /Users/shanerosenthal/Herd/mobile/docs/PLUGIN_SYSTEM_DESIGN.md
 */
class IOSCompilerTest extends TestCase
{
    private IOSPluginCompiler $compiler;

    private Filesystem $files;

    private string $testBasePath;

    private $mockRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->testBasePath = sys_get_temp_dir().'/nativephp-ios-test-'.uniqid();
        $this->mockRegistry = Mockery::mock(PluginRegistry::class);

        // By default, assume no conflicts (individual tests can override)
        $this->mockRegistry->shouldReceive('detectConflicts')->andReturn([]);

        // Create test directory structure matching real iOS project
        $this->files->ensureDirectoryExists($this->testBasePath.'/ios/NativePHP/Bridge');

        // Create minimal Info.plist
        $this->files->put(
            $this->testBasePath.'/ios/NativePHP/Info.plist',
            '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>CFBundleName</key>
    <string>NativePHP</string>
    <key>CFBundleVersion</key>
    <string>1.0</string>
</dict>
</plist>'
        );

        // Create a minimal Package.swift (for SPM dependencies)
        $this->files->put(
            $this->testBasePath.'/ios/Package.swift',
            '// swift-tools-version: 5.9
import PackageDescription

let package = Package(
    name: "NativePHP",
    platforms: [.iOS(.v15)],
    dependencies: [
    ],
    targets: [
        .target(name: "NativePHP"),
    ]
)'
        );

        $this->compiler = new IOSPluginCompiler(
            $this->files,
            $this->mockRegistry,
            $this->testBasePath
        );
    }

    protected function tearDown(): void
    {
        // Reset any config touched by individual tests so we don't leak state
        // into later tests in the same process.
        config()->set('nativephp.permissions', []);
        config()->set('nativephp.permission_localizations', []);

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

        $generatedPath = $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/PluginBridgeFunctionRegistration.swift';

        $this->assertFileExists($generatedPath);

        $content = $this->files->get($generatedPath);
        $this->assertStringContainsString('// No plugins to register', $content);
        $this->assertStringContainsString('func registerPluginBridgeFunctions', $content);
        $this->assertStringContainsString('import Foundation', $content);
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
                    'android' => 'com.test.TestFunctions.Execute',
                    'ios' => 'TestFunctions.Execute',
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $generatedPath = $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/PluginBridgeFunctionRegistration.swift';

        $content = $this->files->get($generatedPath);

        $this->assertStringContainsString('registry.register("Test.Execute"', $content);
        $this->assertStringContainsString('TestFunctions.Execute()', $content);
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
                ['name' => 'PluginA.Func1', 'android' => 'com.a.FuncA1', 'ios' => 'PluginAFunctions.Func1'],
            ],
        ]);

        $pluginB = $this->createTestPlugin([
            'name' => 'vendor/plugin-b',
            'namespace' => 'PluginB',
            'bridge_functions' => [
                ['name' => 'PluginB.Func1', 'android' => 'com.b.FuncB1', 'ios' => 'PluginBFunctions.Func1'],
                ['name' => 'PluginB.Func2', 'android' => 'com.b.FuncB2', 'ios' => 'PluginBFunctions.Func2'],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$pluginA, $pluginB]));

        $this->compiler->compile();

        $generatedPath = $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/PluginBridgeFunctionRegistration.swift';

        $content = $this->files->get($generatedPath);

        $this->assertStringContainsString('PluginA.Func1', $content);
        $this->assertStringContainsString('PluginB.Func1', $content);
        $this->assertStringContainsString('PluginB.Func2', $content);
    }

    /**
     * @test
     *
     * Should copy Swift source files from plugin to iOS project.
     */
    public function it_copies_swift_source_files(): void
    {
        // Create plugin with Swift source
        $pluginPath = $this->testBasePath.'/plugins/test-plugin';
        $swiftPath = $pluginPath.'/resources/ios/Sources';
        $this->files->ensureDirectoryExists($swiftPath);
        $this->files->put($swiftPath.'/TestFunctions.swift', 'import Foundation

enum TestFunctions {
    class Execute: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            return ["status": "success"]
        }
    }
}');

        $plugin = $this->createTestPlugin([], $pluginPath);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $copiedPath = $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/TestPlugin';

        $this->assertDirectoryExists($copiedPath);
        $this->assertFileExists($copiedPath.'/TestFunctions.swift');
    }

    /**
     * @test
     *
     * Should preserve directory structure when copying Swift files.
     */
    public function it_preserves_directory_structure_when_copying(): void
    {
        $pluginPath = $this->testBasePath.'/plugins/test-plugin';
        $swiftPath = $pluginPath.'/resources/ios/Sources/Subfolder';
        $this->files->ensureDirectoryExists($swiftPath);
        $this->files->put($swiftPath.'/NestedClass.swift', 'import Foundation

class NestedClass {}');

        $plugin = $this->createTestPlugin([], $pluginPath);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $copiedPath = $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/TestPlugin/Subfolder/NestedClass.swift';

        $this->assertFileExists($copiedPath);
    }

    /**
     * @test
     *
     * Should merge Info.plist entries from plugins.
     */
    public function it_merges_info_plist_entries(): void
    {
        $plugin = $this->createTestPlugin([
            'ios' => [
                'info_plist' => [
                    'NSCameraUsageDescription' => 'This app uses camera',
                    'NSMicrophoneUsageDescription' => 'This app uses microphone',
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $plistPath = $this->testBasePath.'/ios/NativePHP/Info.plist';
        $content = $this->files->get($plistPath);

        $this->assertStringContainsString('NSCameraUsageDescription', $content);
        $this->assertStringContainsString('This app uses camera', $content);
        $this->assertStringContainsString('NSMicrophoneUsageDescription', $content);
        $this->assertStringContainsString('This app uses microphone', $content);
    }

    /**
     * @test
     *
     * App-level config('nativephp.permissions.ios') wins over plugin manifests,
     * so an app developer can resolve key collisions between plugins.
     */
    public function it_applies_app_permission_overrides_after_plugins(): void
    {
        config()->set('nativephp.permissions', [
            'NSCameraUsageDescription' => 'App-level camera string.',
        ]);

        // Two plugins both claim the same key — last wins among plugins,
        // but app override should beat both.
        $pluginA = $this->createTestPlugin([
            'name' => 'plugin-a',
            'ios' => [
                'info_plist' => [
                    'NSCameraUsageDescription' => 'Plugin A camera string.',
                ],
            ],
        ]);
        $pluginB = $this->createTestPlugin([
            'name' => 'plugin-b',
            'ios' => [
                'info_plist' => [
                    'NSCameraUsageDescription' => 'Plugin B camera string.',
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$pluginA, $pluginB]));

        $this->compiler->compile();

        $plistPath = $this->testBasePath.'/ios/NativePHP/Info.plist';
        $content = $this->files->get($plistPath);

        $this->assertStringContainsString('App-level camera string.', $content);
        $this->assertStringNotContainsString('Plugin A camera string.', $content);
        $this->assertStringNotContainsString('Plugin B camera string.', $content);
        $this->assertEquals(1, substr_count($content, 'NSCameraUsageDescription'));

        config()->set('nativephp.permissions', []);
    }

    /**
     * @test
     *
     * Should update existing Info.plist entries without duplicating the key,
     * so plugin manifests remain the source of truth across rebuilds.
     */
    public function it_updates_existing_plist_entries_without_duplicating(): void
    {
        // Add a permission to the plist first
        $plistPath = $this->testBasePath.'/ios/NativePHP/Info.plist';
        $this->files->put($plistPath, '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>NSCameraUsageDescription</key>
    <string>Existing camera description</string>
</dict>
</plist>');

        $plugin = $this->createTestPlugin([
            'ios' => [
                'info_plist' => [
                    'NSCameraUsageDescription' => 'New camera description',
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $content = $this->files->get($plistPath);

        // Key should appear exactly once, with the manifest value applied
        $this->assertEquals(1, substr_count($content, 'NSCameraUsageDescription'));
        $this->assertStringContainsString('<string>New camera description</string>', $content);
        $this->assertStringNotContainsString('Existing camera description', $content);
    }

    /**
     * @test
     *
     * Should not duplicate Info.plist entries when compiling multiple times.
     */
    public function it_does_not_duplicate_plist_entries_on_recompile(): void
    {
        $plugin = $this->createTestPlugin([
            'ios' => [
                'info_plist' => ['NSCameraUsageDescription' => 'Camera access'],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        // Compile twice
        $this->compiler->compile();
        $this->compiler->compile();

        $plistPath = $this->testBasePath.'/ios/NativePHP/Info.plist';
        $content = $this->files->get($plistPath);

        $count = substr_count($content, 'NSCameraUsageDescription');
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

        $pluginsDir = $this->testBasePath.'/ios/NativePHP/Bridge/Plugins';
        $this->assertDirectoryExists($pluginsDir);

        $this->compiler->clean();

        $this->assertDirectoryDoesNotExist($pluginsDir);
    }

    /**
     * @test
     *
     * Should return list of generated Swift files.
     */
    public function it_returns_generated_files(): void
    {
        $pluginPath = $this->testBasePath.'/plugins/test-plugin';
        $swiftPath = $pluginPath.'/resources/ios/Sources';
        $this->files->ensureDirectoryExists($swiftPath);
        $this->files->put($swiftPath.'/TestFunctions.swift', 'import Foundation');

        $plugin = $this->createTestPlugin([], $pluginPath);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $files = $this->compiler->getGeneratedFiles();

        $this->assertIsArray($files);
        $this->assertNotEmpty($files);

        // Should include the registration file
        $registrationFile = $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/PluginBridgeFunctionRegistration.swift';
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
                ['name' => 'Test.Execute', 'android' => 'com.test.Execute', 'ios' => 'TestFunctions.Execute'],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $generatedPath = $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/PluginBridgeFunctionRegistration.swift';
        $content = $this->files->get($generatedPath);

        $this->assertStringContainsString('AUTO-GENERATED', $content);
        $this->assertStringContainsString('DO NOT EDIT', $content);
    }

    /**
     * @test
     *
     * Generated registration function should have correct Swift signature.
     */
    public function it_generates_function_with_correct_signature(): void
    {
        $plugin = $this->createTestPlugin([
            'bridge_functions' => [
                ['name' => 'Test.Execute', 'android' => 'com.test.Execute', 'ios' => 'TestFunctions.Execute'],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $generatedPath = $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/PluginBridgeFunctionRegistration.swift';
        $content = $this->files->get($generatedPath);

        $this->assertStringContainsString('func registerPluginBridgeFunctions()', $content);
        $this->assertStringContainsString('BridgeFunctionRegistry', $content);
    }

    /**
     * @test
     *
     * Should handle plugins without bridge functions.
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
        $generatedPath = $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/PluginBridgeFunctionRegistration.swift';
        $this->assertFileExists($generatedPath);
    }

    /**
     * @test
     *
     * Should generate comments indicating which plugin each function comes from.
     */
    public function it_generates_plugin_comments(): void
    {
        $plugin = $this->createTestPlugin([
            'name' => 'vendor/my-plugin',
            'bridge_functions' => [
                ['name' => 'MyPlugin.Func', 'android' => 'com.vendor.Func', 'ios' => 'MyPluginFunctions.Func'],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $generatedPath = $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/PluginBridgeFunctionRegistration.swift';
        $content = $this->files->get($generatedPath);

        // Should have a comment indicating the plugin
        $this->assertStringContainsString('vendor/my-plugin', $content);
    }

    /**
     * @test
     *
     * Should handle Info.plist with various value types (string, bool, array).
     */
    public function it_handles_various_plist_value_types(): void
    {
        $plugin = $this->createTestPlugin([
            'ios' => [
                'info_plist' => [
                    'NSCameraUsageDescription' => 'Camera description',  // String
                    'UIRequiredDeviceCapabilities' => ['arm64'],  // Array (if supported)
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $plistPath = $this->testBasePath.'/ios/NativePHP/Info.plist';
        $content = $this->files->get($plistPath);

        $this->assertStringContainsString('NSCameraUsageDescription', $content);
        $this->assertStringContainsString('Camera description', $content);
    }

    /**
     * @test
     *
     * Compilation should be idempotent - running twice produces same result.
     */
    public function it_is_idempotent(): void
    {
        $plugin = $this->createTestPlugin([
            'bridge_functions' => [
                ['name' => 'Test.Execute', 'android' => 'com.test.Execute', 'ios' => 'TestFunctions.Execute'],
            ],
            'ios' => [
                'info_plist' => ['NSCameraUsageDescription' => 'Camera access'],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();
        $firstContent = $this->files->get(
            $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/PluginBridgeFunctionRegistration.swift'
        );

        $this->compiler->compile();
        $secondContent = $this->files->get(
            $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/PluginBridgeFunctionRegistration.swift'
        );

        $this->assertEquals($firstContent, $secondContent);
    }

    /**
     * @test
     *
     * Should handle plugins with only iOS implementations (no android).
     */
    public function it_handles_ios_only_bridge_functions(): void
    {
        $plugin = $this->createTestPlugin([
            'bridge_functions' => [
                [
                    'name' => 'iOSOnly.Func',
                    'ios' => 'iOSOnlyFunctions.Func',
                    // No android key
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $generatedPath = $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/PluginBridgeFunctionRegistration.swift';
        $content = $this->files->get($generatedPath);

        $this->assertStringContainsString('iOSOnly.Func', $content);
        $this->assertStringContainsString('iOSOnlyFunctions.Func', $content);
    }

    /**
     * @test
     *
     * App-level permission_localizations are written to {locale}.lproj/InfoPlist.strings
     * inside the synced NativePHP group so iOS picks them up at runtime.
     */
    public function it_writes_app_level_info_plist_localizations(): void
    {
        config()->set('nativephp.permission_localizations', [
            'nl' => [
                'NSCameraUsageDescription' => 'Camera-toegang nodig.',
                'NSMicrophoneUsageDescription' => 'Microfoon-toegang nodig.',
            ],
            'fr' => [
                'NSCameraUsageDescription' => 'Accès caméra requis.',
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$this->createTestPlugin()]));

        $this->compiler->compile();

        $nlPath = $this->testBasePath.'/ios/NativePHP/nl.lproj/InfoPlist.strings';
        $frPath = $this->testBasePath.'/ios/NativePHP/fr.lproj/InfoPlist.strings';

        $this->assertFileExists($nlPath);
        $this->assertFileExists($frPath);

        $nl = $this->files->get($nlPath);
        $this->assertStringContainsString('"NSCameraUsageDescription" = "Camera-toegang nodig.";', $nl);
        $this->assertStringContainsString('"NSMicrophoneUsageDescription" = "Microfoon-toegang nodig.";', $nl);

        $fr = $this->files->get($frPath);
        $this->assertStringContainsString('"NSCameraUsageDescription" = "Accès caméra requis.";', $fr);

        config()->set('nativephp.permission_localizations', []);
    }

    /**
     * @test
     *
     * Plugins can ship their own per-locale permission strings via
     * `ios.info_plist_localizations`; the app-level config wins on collisions.
     */
    public function it_merges_plugin_localizations_with_app_overrides(): void
    {
        config()->set('nativephp.permission_localizations', [
            'nl' => [
                'NSCameraUsageDescription' => 'App-level NL string.',
            ],
        ]);

        $plugin = $this->createTestPlugin([
            'ios' => [
                'info_plist' => [],
                'info_plist_localizations' => [
                    'nl' => [
                        'NSCameraUsageDescription' => 'Plugin NL string.',
                        'NSMicrophoneUsageDescription' => 'Plugin NL microfoon.',
                    ],
                ],
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$plugin]));

        $this->compiler->compile();

        $nl = $this->files->get($this->testBasePath.'/ios/NativePHP/nl.lproj/InfoPlist.strings');

        // App override wins for the camera key, plugin string is gone.
        $this->assertStringContainsString('"NSCameraUsageDescription" = "App-level NL string.";', $nl);
        $this->assertStringNotContainsString('Plugin NL string.', $nl);

        // Plugin-only keys still come through.
        $this->assertStringContainsString('"NSMicrophoneUsageDescription" = "Plugin NL microfoon.";', $nl);

        config()->set('nativephp.permission_localizations', []);
    }

    /**
     * @test
     *
     * When permission_localizations is empty, no .lproj folders should be created
     * — this is the default-config case and we don't want spurious files.
     */
    public function it_does_not_write_localizations_when_config_is_empty(): void
    {
        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$this->createTestPlugin()]));

        $this->compiler->compile();

        $this->assertFileDoesNotExist($this->testBasePath.'/ios/NativePHP/en.lproj/InfoPlist.strings');
        $this->assertFileDoesNotExist($this->testBasePath.'/ios/NativePHP/nl.lproj/InfoPlist.strings');
    }

    /**
     * @test
     *
     * Quotes, backslashes, and newlines in localized strings must be escaped
     * so the resulting .strings file remains valid.
     */
    public function it_escapes_special_characters_in_localized_strings(): void
    {
        config()->set('nativephp.permission_localizations', [
            'nl' => [
                'NSCameraUsageDescription' => "Camera \"toegang\" \\nodig\nop regel 2",
            ],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$this->createTestPlugin()]));

        $this->compiler->compile();

        $nl = $this->files->get($this->testBasePath.'/ios/NativePHP/nl.lproj/InfoPlist.strings');

        $this->assertStringContainsString(
            '"NSCameraUsageDescription" = "Camera \\"toegang\\" \\\\nodig\\nop regel 2";',
            $nl
        );

        config()->set('nativephp.permission_localizations', []);
    }

    /**
     * @test
     *
     * Locales used in permission_localizations must be added to the pbxproj's
     * `knownRegions` list so Xcode bundles the .lproj folders as resources.
     */
    public function it_registers_new_locales_in_pbxproj_known_regions(): void
    {
        // Minimal pbxproj fragment with the same knownRegions block shape
        // the real project uses.
        $pbxprojPath = $this->testBasePath.'/ios/NativePHP.xcodeproj/project.pbxproj';
        $this->files->ensureDirectoryExists(dirname($pbxprojPath));
        $this->files->put($pbxprojPath, "// !\$*UTF8\$*!\n{\n\tobjects = {\n\t\t95BD5DBB /* Project object */ = {\n\t\t\tisa = PBXProject;\n\t\t\tdevelopmentRegion = en;\n\t\t\tknownRegions = (\n\t\t\t\ten,\n\t\t\t\tBase,\n\t\t\t);\n\t\t};\n\t};\n}\n");

        config()->set('nativephp.permission_localizations', [
            'nl' => ['NSCameraUsageDescription' => 'NL'],
            'fr' => ['NSCameraUsageDescription' => 'FR'],
            'en' => ['NSCameraUsageDescription' => 'EN (already known)'],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$this->createTestPlugin()]));

        $this->compiler->compile();

        $pbxproj = $this->files->get($pbxprojPath);

        // New locales are inserted before the closing paren.
        $this->assertMatchesRegularExpression('/knownRegions\s*=\s*\(\s*en,\s*Base,\s*nl,\s*fr,\s*\)/s', $pbxproj);

        // Existing `en` not duplicated.
        $this->assertEquals(1, substr_count($pbxproj, "\ten,"));

        config()->set('nativephp.permission_localizations', []);
    }

    /**
     * @test
     *
     * Re-running compile should be idempotent for localizations — no duplicate
     * lines in the .strings file, no duplicate entries in knownRegions.
     */
    public function it_is_idempotent_for_localizations_on_recompile(): void
    {
        $pbxprojPath = $this->testBasePath.'/ios/NativePHP.xcodeproj/project.pbxproj';
        $this->files->ensureDirectoryExists(dirname($pbxprojPath));
        $this->files->put($pbxprojPath, "// !\$*UTF8\$*!\n{\n\tknownRegions = (\n\t\ten,\n\t\tBase,\n\t);\n}\n");

        config()->set('nativephp.permission_localizations', [
            'nl' => ['NSCameraUsageDescription' => 'NL'],
        ]);

        $this->mockRegistry
            ->shouldReceive('all')
            ->andReturn(collect([$this->createTestPlugin()]));

        $this->compiler->compile();
        $this->compiler->compile();

        $nl = $this->files->get($this->testBasePath.'/ios/NativePHP/nl.lproj/InfoPlist.strings');
        $this->assertEquals(1, substr_count($nl, 'NSCameraUsageDescription'));

        $pbxproj = $this->files->get($pbxprojPath);
        $this->assertEquals(1, substr_count($pbxproj, "\tnl,"));

        config()->set('nativephp.permission_localizations', []);
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
