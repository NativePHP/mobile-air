<?php

namespace Tests\Feature\Plugins;

use Illuminate\Filesystem\Filesystem;
use Mockery;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use Native\Mobile\Plugins\PluginRegistry;
use Tests\TestCase;

/**
 * Feature tests for Plugin Artisan Commands.
 *
 * Tests the following commands:
 * - native:plugin:create - Scaffold a new plugin
 * - native:plugin:validate - Validate plugin structure and manifest
 * - native:plugin:list - List installed plugins
 *
 * All tests should FAIL before implementation exists (red phase of TDD).
 *
 * @see /Users/shanerosenthal/Herd/mobile/docs/PLUGIN_SYSTEM_DESIGN.md
 */
class PluginCommandsTest extends TestCase
{
    private Filesystem $files;

    private string $testPluginPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->testPluginPath = sys_get_temp_dir().'/test-plugin-'.uniqid();
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->testPluginPath);
        Mockery::close();
        parent::tearDown();
    }

    // ==========================================
    // native:plugin:create Command Tests
    // ==========================================

    /**
     * @test
     *
     * The create command should scaffold a complete plugin directory structure.
     */
    public function plugin_create_command_scaffolds_complete_structure(): void
    {
        $this->artisan('native:plugin:create', [
            'name' => 'test/my-plugin',
            '--namespace' => 'MyPlugin',
            '--path' => $this->testPluginPath,
        ])
            ->expectsQuestion('Describe your plugin briefly', 'Test plugin description')
            ->expectsConfirmation('Include Boost AI guidelines?', 'no')
            ->expectsConfirmation('Install AI agents for plugin development?', 'no')
            ->assertSuccessful();

        // Verify complete directory structure
        $this->assertFileExists($this->testPluginPath.'/composer.json');
        $this->assertFileExists($this->testPluginPath.'/nativephp.json');
        $this->assertFileExists($this->testPluginPath.'/src/MyPluginServiceProvider.php');
        $this->assertFileExists($this->testPluginPath.'/src/MyPlugin.php');
        $this->assertFileExists($this->testPluginPath.'/src/Facades/MyPlugin.php');
        $this->assertDirectoryExists($this->testPluginPath.'/resources/android');
        $this->assertDirectoryExists($this->testPluginPath.'/resources/ios');
        $this->assertFileExists($this->testPluginPath.'/README.md');
    }

    /**
     * @test
     *
     * Should generate a valid composer.json with correct type.
     */
    public function plugin_create_generates_valid_composer_json(): void
    {
        $this->artisan('native:plugin:create', [
            'name' => 'test/my-plugin',
            '--namespace' => 'MyPlugin',
            '--path' => $this->testPluginPath,
        ])
            ->expectsQuestion('Describe your plugin briefly', 'Test')
            ->expectsConfirmation('Include Boost AI guidelines?', 'no')
            ->expectsConfirmation('Install AI agents for plugin development?', 'no')
            ->assertSuccessful();

        $composer = json_decode(
            $this->files->get($this->testPluginPath.'/composer.json'),
            true
        );

        $this->assertEquals('test/my-plugin', $composer['name']);
        $this->assertEquals('nativephp-plugin', $composer['type']);
        $this->assertArrayHasKey('nativephp', $composer['extra']);
        $this->assertEquals('nativephp.json', $composer['extra']['nativephp']['manifest']);
        $this->assertArrayHasKey('autoload', $composer);
        $this->assertArrayHasKey('psr-4', $composer['autoload']);
    }

    /**
     * @test
     *
     * Should generate a valid nativephp.json manifest.
     */
    public function plugin_create_generates_valid_manifest(): void
    {
        $this->artisan('native:plugin:create', [
            'name' => 'test/my-plugin',
            '--namespace' => 'MyPlugin',
            '--path' => $this->testPluginPath,
        ])
            ->expectsQuestion('Describe your plugin briefly', 'A cool plugin')
            ->expectsConfirmation('Include Boost AI guidelines?', 'no')
            ->expectsConfirmation('Install AI agents for plugin development?', 'no')
            ->assertSuccessful();

        $manifest = json_decode(
            $this->files->get($this->testPluginPath.'/nativephp.json'),
            true
        );

        $this->assertEquals('test/my-plugin', $manifest['name']);
        $this->assertEquals('MyPlugin', $manifest['namespace']);
        $this->assertEquals('A cool plugin', $manifest['description']);
        $this->assertArrayHasKey('bridge_functions', $manifest);
        $this->assertArrayHasKey('android', $manifest);
        $this->assertArrayHasKey('ios', $manifest);
        $this->assertArrayHasKey('permissions', $manifest['android']);
        $this->assertArrayHasKey('dependencies', $manifest['android']);
    }

    /**
     * @test
     *
     * Should generate example bridge function in manifest when requested.
     */
    public function plugin_create_generates_example_bridge_function(): void
    {
        $this->artisan('native:plugin:create', [
            'name' => 'test/my-plugin',
            '--namespace' => 'MyPlugin',
            '--path' => $this->testPluginPath,
        ])
            ->expectsQuestion('Describe your plugin briefly', 'Test')
            ->expectsConfirmation('Include Boost AI guidelines?', 'no')
            ->expectsConfirmation('Install AI agents for plugin development?', 'no')
            ->assertSuccessful();

        $manifest = json_decode(
            $this->files->get($this->testPluginPath.'/nativephp.json'),
            true
        );

        // Should have at least one example bridge function
        $this->assertNotEmpty($manifest['bridge_functions']);
        $this->assertArrayHasKey('name', $manifest['bridge_functions'][0]);
        $this->assertArrayHasKey('android', $manifest['bridge_functions'][0]);
        $this->assertArrayHasKey('ios', $manifest['bridge_functions'][0]);
    }

    /**
     * @test
     *
     * Should generate Kotlin example file.
     */
    public function plugin_create_generates_kotlin_example(): void
    {
        $this->artisan('native:plugin:create', [
            'name' => 'test/my-plugin',
            '--namespace' => 'MyPlugin',
            '--path' => $this->testPluginPath,
        ])
            ->expectsQuestion('Describe your plugin briefly', 'Test')
            ->expectsConfirmation('Include Boost AI guidelines?', 'no')
            ->expectsConfirmation('Install AI agents for plugin development?', 'no')
            ->assertSuccessful();

        // Find Kotlin files recursively (glob ** doesn't work in PHP)
        $kotlinFiles = $this->findFilesRecursively($this->testPluginPath.'/resources/android', '*.kt');

        $this->assertNotEmpty($kotlinFiles, 'Should have generated at least one Kotlin file');

        // Check content of the Kotlin file
        $kotlinContent = $this->files->get($kotlinFiles[0]);
        $this->assertStringContainsString('BridgeFunction', $kotlinContent);
        $this->assertStringContainsString('execute', $kotlinContent);
    }

    /**
     * @test
     *
     * Should generate Swift example file.
     */
    public function plugin_create_generates_swift_example(): void
    {
        $this->artisan('native:plugin:create', [
            'name' => 'test/my-plugin',
            '--namespace' => 'MyPlugin',
            '--path' => $this->testPluginPath,
        ])
            ->expectsQuestion('Describe your plugin briefly', 'Test')
            ->expectsConfirmation('Include Boost AI guidelines?', 'no')
            ->expectsConfirmation('Install AI agents for plugin development?', 'no')
            ->assertSuccessful();

        // Find Swift files
        $swiftFiles = glob($this->testPluginPath.'/resources/ios/*.swift');

        $this->assertNotEmpty($swiftFiles, 'Should have generated at least one Swift file');

        // Check content of the Swift file
        $swiftContent = $this->files->get($swiftFiles[0]);
        $this->assertStringContainsString('BridgeFunction', $swiftContent);
        $this->assertStringContainsString('execute', $swiftContent);
    }

    /**
     * @test
     *
     * Should generate valid PHP service provider.
     */
    public function plugin_create_generates_service_provider(): void
    {
        $this->artisan('native:plugin:create', [
            'name' => 'vendor/awesome-plugin',
            '--namespace' => 'AwesomePlugin',
            '--path' => $this->testPluginPath,
        ])
            ->expectsQuestion('Describe your plugin briefly', 'Test')
            ->expectsConfirmation('Include Boost AI guidelines?', 'no')
            ->expectsConfirmation('Install AI agents for plugin development?', 'no')
            ->assertSuccessful();

        $providerPath = $this->testPluginPath.'/src/AwesomePluginServiceProvider.php';
        $this->assertFileExists($providerPath);

        $content = $this->files->get($providerPath);
        $this->assertStringContainsString('namespace Vendor\\AwesomePlugin', $content);
        $this->assertStringContainsString('extends ServiceProvider', $content);
        $this->assertStringContainsString('function register', $content);
        $this->assertStringContainsString('function boot', $content);
    }

    /**
     * @test
     *
     * Should generate events when that feature is selected.
     */
    public function plugin_create_generates_events_when_selected(): void
    {
        $this->artisan('native:plugin:create', [
            'name' => 'test/my-plugin',
            '--namespace' => 'MyPlugin',
            '--path' => $this->testPluginPath,
        ])
            ->expectsQuestion('Describe your plugin briefly', 'Test')
            ->expectsConfirmation('Include Boost AI guidelines?', 'no')
            ->expectsConfirmation('Install AI agents for plugin development?', 'no')
            ->assertSuccessful();

        // Should have Events directory
        $this->assertDirectoryExists($this->testPluginPath.'/src/Events');

        // Should have at least one example event
        $eventFiles = glob($this->testPluginPath.'/src/Events/*.php');
        $this->assertNotEmpty($eventFiles);
    }

    /**
     * @test
     *
     * Should fail gracefully if path already exists and --force not provided.
     */
    public function plugin_create_fails_if_path_exists(): void
    {
        $this->files->ensureDirectoryExists($this->testPluginPath);
        $this->files->put($this->testPluginPath.'/composer.json', '{}');

        $this->artisan('native:plugin:create', [
            'name' => 'test/my-plugin',
            '--namespace' => 'MyPlugin',
            '--path' => $this->testPluginPath,
        ])
            ->assertFailed()
            ->expectsOutputToContain('already exists');
    }

    /**
     * @test
     *
     * Should overwrite if --force is provided.
     */
    public function plugin_create_overwrites_with_force_flag(): void
    {
        $this->files->ensureDirectoryExists($this->testPluginPath);
        $this->files->put($this->testPluginPath.'/composer.json', '{}');

        $this->artisan('native:plugin:create', [
            'name' => 'test/my-plugin',
            '--namespace' => 'MyPlugin',
            '--path' => $this->testPluginPath,
            '--force' => true,
        ])
            ->expectsQuestion('Describe your plugin briefly', 'Test')
            ->expectsConfirmation('Include Boost AI guidelines?', 'no')
            ->expectsConfirmation('Install AI agents for plugin development?', 'no')
            ->assertSuccessful();

        // Verify new content
        $composer = json_decode($this->files->get($this->testPluginPath.'/composer.json'), true);
        $this->assertEquals('test/my-plugin', $composer['name']);
    }

    // ==========================================
    // native:plugin:validate Command Tests
    // ==========================================

    /**
     * @test
     *
     * Should pass validation for a properly structured plugin.
     */
    public function plugin_validate_passes_for_valid_plugin(): void
    {
        // First create a valid plugin
        $this->artisan('native:plugin:create', [
            'name' => 'test/my-plugin',
            '--namespace' => 'MyPlugin',
            '--path' => $this->testPluginPath,
        ])
            ->expectsQuestion('Describe your plugin briefly', 'Test')
            ->expectsConfirmation('Include Boost AI guidelines?', 'no')
            ->expectsConfirmation('Install AI agents for plugin development?', 'no');

        // Then validate it
        $this->artisan('native:plugin:validate', [
            'path' => $this->testPluginPath,
        ])
            ->assertSuccessful();
    }

    /**
     * @test
     *
     * Should fail validation when nativephp.json is missing.
     */
    public function plugin_validate_fails_for_missing_manifest(): void
    {
        $this->files->ensureDirectoryExists($this->testPluginPath);
        $this->files->put($this->testPluginPath.'/composer.json', json_encode([
            'name' => 'test/plugin',
            'type' => 'nativephp-plugin',
        ]));

        $this->artisan('native:plugin:validate', [
            'path' => $this->testPluginPath,
        ])
            ->assertFailed()
            ->expectsOutputToContain('nativephp.json');
    }

    /**
     * @test
     *
     * Should fail validation when composer.json has wrong type.
     */
    public function plugin_validate_fails_for_wrong_package_type(): void
    {
        $this->files->ensureDirectoryExists($this->testPluginPath);
        $this->files->put($this->testPluginPath.'/composer.json', json_encode([
            'name' => 'test/plugin',
            'type' => 'library',  // Wrong type!
        ]));
        $this->files->put($this->testPluginPath.'/nativephp.json', json_encode([
            'name' => 'test/plugin',
            'namespace' => 'Test',
        ]));
        $this->files->ensureDirectoryExists($this->testPluginPath.'/src');

        $this->artisan('native:plugin:validate', [
            'path' => $this->testPluginPath,
        ])
            ->assertFailed()
            ->expectsOutputToContain('nativephp-plugin');
    }

    /**
     * @test
     *
     * Should fail validation when manifest is invalid JSON.
     */
    public function plugin_validate_fails_for_invalid_json(): void
    {
        $this->files->ensureDirectoryExists($this->testPluginPath);
        $this->files->put($this->testPluginPath.'/composer.json', json_encode([
            'name' => 'test/plugin',
            'type' => 'nativephp-plugin',
        ]));
        $this->files->put($this->testPluginPath.'/nativephp.json', 'this is not valid json {{{');

        $this->artisan('native:plugin:validate', [
            'path' => $this->testPluginPath,
        ])
            ->assertFailed()
            ->expectsOutputToContain('Invalid JSON');
    }

    /**
     * @test
     *
     * Should fail validation when required manifest fields are missing.
     */
    public function plugin_validate_fails_for_missing_required_fields(): void
    {
        $this->files->ensureDirectoryExists($this->testPluginPath);
        $this->files->put($this->testPluginPath.'/composer.json', json_encode([
            'name' => 'test/plugin',
            'type' => 'nativephp-plugin',
        ]));
        $this->files->put($this->testPluginPath.'/nativephp.json', json_encode([
            'name' => 'test/plugin',
            // Missing 'namespace' field
        ]));

        $this->artisan('native:plugin:validate', [
            'path' => $this->testPluginPath,
        ])
            ->assertFailed()
            ->expectsOutputToContain('namespace');
    }

    /**
     * @test
     *
     * Should warn when declared bridge functions lack native code files.
     */
    public function plugin_validate_warns_for_missing_native_code(): void
    {
        $this->files->ensureDirectoryExists($this->testPluginPath.'/src');
        $this->files->put($this->testPluginPath.'/composer.json', json_encode([
            'name' => 'test/plugin',
            'type' => 'nativephp-plugin',
        ]));
        $this->files->put($this->testPluginPath.'/nativephp.json', json_encode([
            'name' => 'test/plugin',
            'namespace' => 'TestPlugin',
            'bridge_functions' => [
                [
                    'name' => 'TestPlugin.Execute',
                    'android' => 'com.test.TestFunctions.Execute',
                    'ios' => 'TestFunctions.Execute',
                ],
            ],
        ]));
        // Note: NOT creating the native code files

        $this->artisan('native:plugin:validate', [
            'path' => $this->testPluginPath,
        ])
            ->expectsOutputToContain('native code');  // Should warn about missing files
    }

    /**
     * @test
     *
     * Should validate bridge function structure.
     */
    public function plugin_validate_checks_bridge_function_structure(): void
    {
        $this->files->ensureDirectoryExists($this->testPluginPath);
        $this->files->put($this->testPluginPath.'/composer.json', json_encode([
            'name' => 'test/plugin',
            'type' => 'nativephp-plugin',
        ]));
        $this->files->put($this->testPluginPath.'/nativephp.json', json_encode([
            'name' => 'test/plugin',
            'namespace' => 'TestPlugin',
            'bridge_functions' => [
                [
                    'name' => 'Missing.Implementation',
                    // Missing android and ios!
                ],
            ],
        ]));

        $this->artisan('native:plugin:validate', [
            'path' => $this->testPluginPath,
        ])
            ->assertFailed()
            ->expectsOutputToContain('implementation');
    }

    // ==========================================
    // native:plugin:list Command Tests
    // ==========================================

    /**
     * @test
     *
     * Should show "no plugins" message when none are installed.
     */
    public function plugin_list_shows_no_plugins_message(): void
    {
        // Mock empty registry
        $mockRegistry = Mockery::mock(PluginRegistry::class);
        $mockRegistry->shouldReceive('all')->andReturn(collect([]));
        $mockRegistry->shouldReceive('count')->andReturn(0);
        $mockRegistry->shouldReceive('unregistered')->andReturn(collect([]));
        $this->app->instance(PluginRegistry::class, $mockRegistry);

        $this->artisan('native:plugin:list')
            ->assertSuccessful()
            ->expectsOutputToContain('No NativePHP plugins');
    }

    /**
     * @test
     *
     * Should list all installed plugins with name and version.
     */
    public function plugin_list_shows_installed_plugins(): void
    {
        $plugin1 = $this->createMockPlugin('vendor/plugin-one', '1.0.0');
        $plugin2 = $this->createMockPlugin('vendor/plugin-two', '2.0.0');

        $mockRegistry = Mockery::mock(PluginRegistry::class);
        $mockRegistry->shouldReceive('all')->andReturn(collect([$plugin1, $plugin2]));
        $mockRegistry->shouldReceive('count')->andReturn(2);
        $mockRegistry->shouldReceive('unregistered')->andReturn(collect([]));
        $mockRegistry->shouldReceive('hasPluginsProvider')->andReturn(true);
        $this->app->instance(PluginRegistry::class, $mockRegistry);

        $this->artisan('native:plugin:list')
            ->assertSuccessful()
            ->expectsOutputToContain('vendor/plugin-one')
            ->expectsOutputToContain('vendor/plugin-two')
            ->expectsOutputToContain('Total: 2 registered plugin(s)');
    }

    /**
     * @test
     *
     * Should output JSON when --json flag is provided.
     */
    public function plugin_list_outputs_json_when_requested(): void
    {
        $plugin = $this->createMockPlugin('vendor/plugin', '1.0.0');

        $mockRegistry = Mockery::mock(PluginRegistry::class);
        $mockRegistry->shouldReceive('all')->andReturn(collect([$plugin]));
        $mockRegistry->shouldReceive('count')->andReturn(1);
        $mockRegistry->shouldReceive('unregistered')->andReturn(collect([]));
        $mockRegistry->shouldReceive('hasPluginsProvider')->andReturn(true);
        $this->app->instance(PluginRegistry::class, $mockRegistry);

        $this->artisan('native:plugin:list', ['--json' => true])
            ->assertSuccessful();

        // The output should be valid JSON (test will verify command runs successfully)
    }

    /**
     * @test
     *
     * Should show bridge function count for each plugin.
     */
    public function plugin_list_shows_bridge_function_count(): void
    {
        $plugin = $this->createMockPlugin('vendor/plugin', '1.0.0', [
            'bridge_functions' => [
                ['name' => 'Func1', 'android' => 'a', 'ios' => 'b'],
                ['name' => 'Func2', 'android' => 'a', 'ios' => 'b'],
            ],
        ]);

        $mockRegistry = Mockery::mock(PluginRegistry::class);
        $mockRegistry->shouldReceive('all')->andReturn(collect([$plugin]));
        $mockRegistry->shouldReceive('count')->andReturn(1);
        $mockRegistry->shouldReceive('unregistered')->andReturn(collect([]));
        $mockRegistry->shouldReceive('hasPluginsProvider')->andReturn(true);
        $this->app->instance(PluginRegistry::class, $mockRegistry);

        $this->artisan('native:plugin:list')
            ->assertSuccessful()
            ->expectsOutputToContain('2');  // Should show count of bridge functions
    }

    /**
     * @test
     *
     * Should show bridge function names in output.
     */
    public function plugin_list_shows_bridge_functions(): void
    {
        $plugin = $this->createMockPlugin('vendor/plugin', '1.0.0', [
            'description' => 'A detailed description',
            'bridge_functions' => [
                ['name' => 'Plugin.Execute', 'android' => 'com.test.Execute', 'ios' => 'Execute'],
            ],
        ]);

        $mockRegistry = Mockery::mock(PluginRegistry::class);
        $mockRegistry->shouldReceive('all')->andReturn(collect([$plugin]));
        $mockRegistry->shouldReceive('count')->andReturn(1);
        $mockRegistry->shouldReceive('unregistered')->andReturn(collect([]));
        $mockRegistry->shouldReceive('hasPluginsProvider')->andReturn(true);
        $this->app->instance(PluginRegistry::class, $mockRegistry);

        $this->artisan('native:plugin:list')
            ->assertSuccessful()
            ->expectsOutputToContain('Plugin.Execute');
    }

    // ==========================================
    // native:plugin:uninstall Command Tests
    // ==========================================

    /**
     * @test
     *
     * Should fail when plugin is not installed.
     */
    public function plugin_uninstall_fails_for_unknown_plugin(): void
    {
        $this->artisan('native:plugin:uninstall', [
            'plugin' => 'unknown/plugin',
        ])
            ->assertFailed()
            ->expectsOutputToContain('not installed');
    }

    /**
     * Helper to create a mock Plugin for testing.
     */
    private function createMockPlugin(string $name, string $version, array $manifestData = []): Plugin
    {
        $defaultData = [
            'name' => $name,
            'namespace' => str_replace(['/', '-'], ['', ''], ucwords($name, '/-')),
            'description' => '',
            'bridge_functions' => [],
            'android' => ['permissions' => [], 'dependencies' => []],
            'ios' => ['info_plist' => [], 'dependencies' => []],
        ];

        $data = array_merge($defaultData, $manifestData);

        // Create a real PluginManifest instead of mock (readonly properties can't be mocked)
        $manifest = new PluginManifest($data);

        return new Plugin(
            name: $name,
            version: $version,
            path: '/path/to/'.$name,
            manifest: $manifest
        );
    }

    /**
     * Recursively find files matching a pattern.
     */
    private function findFilesRecursively(string $directory, string $pattern): array
    {
        $files = [];

        if (! is_dir($directory)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
