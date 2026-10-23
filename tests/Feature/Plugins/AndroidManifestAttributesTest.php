<?php

namespace Tests\Feature\Plugins;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Mockery;
use Native\Mobile\Exceptions\PluginConflictException;
use Native\Mobile\Plugins\Compilers\AndroidPluginCompiler;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use Native\Mobile\Plugins\PluginRegistry;
use Tests\TestCase;

/**
 * Plugins can add manifest nodes, but the app's own application and launcher
 * activity are core's, and until now a plugin could only change them by
 * patching the file. These cover `android.manifest_attributes`: applying an
 * attribute, giving it back, and refusing to guess between two plugins that
 * disagree.
 */
class AndroidManifestAttributesTest extends TestCase
{
    private AndroidPluginCompiler $compiler;

    private Filesystem $files;

    private string $testBasePath;

    private string $manifestPath;

    private $mockRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->testBasePath = sys_get_temp_dir().'/nativephp-manifest-attrs-'.uniqid();
        $this->manifestPath = $this->testBasePath.'/android/app/src/main/AndroidManifest.xml';

        $this->mockRegistry = Mockery::mock(PluginRegistry::class);
        $this->mockRegistry->shouldReceive('detectConflicts')->andReturn([]);

        $this->files->ensureDirectoryExists($this->testBasePath.'/android/app/src/main');

        // The nodes core's own template declares, which is what a target names.
        $this->files->put($this->manifestPath, <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <manifest xmlns:android="http://schemas.android.com/apk/res/android"
            xmlns:tools="http://schemas.android.com/tools">
            <application
                android:label="TestApp"
                android:theme="@style/Theme.AndroidPHP"
                tools:targetApi="31">
                <activity
                android:name=".ui.MainActivity"
                    android:exported="true"
                    android:launchMode="singleTop">
                </activity>
            </application>
        </manifest>
        XML);

        $this->files->put($this->testBasePath.'/android/app/build.gradle.kts', "dependencies {\n}\n");

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

    public function test_it_sets_an_attribute_on_the_application(): void
    {
        $this->compileWith([$this->plugin('splash/plugin', [
            'application' => ['android:theme' => '@style/Theme.Splash'],
        ])]);

        $this->assertStringContainsString('android:theme="@style/Theme.Splash"', $this->manifest());
        $this->assertStringNotContainsString('android:theme="@style/Theme.AndroidPHP"', $this->manifest());
        $this->assertStringContainsString('android:label="TestApp"', $this->manifest());
    }

    public function test_it_sets_an_attribute_the_template_does_not_declare(): void
    {
        $this->compileWith([$this->plugin('ml/plugin', [
            'application' => ['android:largeHeap' => true],
        ])]);

        $this->assertStringContainsString('android:largeHeap="true"', $this->manifest());
    }

    public function test_it_targets_the_launcher_activity_without_touching_the_application(): void
    {
        $this->compileWith([$this->plugin('deeplinks/plugin', [
            'main_activity' => ['android:launchMode' => 'singleTask'],
        ])]);

        $this->assertStringContainsString('android:launchMode="singleTask"', $this->manifest());
        $this->assertStringNotContainsString('android:launchMode="singleTop"', $this->manifest());
        $this->assertStringContainsString('android:theme="@style/Theme.AndroidPHP"', $this->manifest());
    }

    /**
     * The manifest is installed once and edited in place, so an attribute that
     * is no longer declared has to go back to what core ships.
     */
    public function test_it_gives_an_attribute_back_when_the_plugin_stops_declaring_it(): void
    {
        $this->compileWith([$this->plugin('splash/plugin', [
            'application' => ['android:theme' => '@style/Theme.Splash'],
            'main_activity' => ['android:launchMode' => 'singleTask'],
        ])]);

        $this->compileWith([$this->plugin('splash/plugin', [])]);

        $this->assertStringContainsString('android:theme="@style/Theme.AndroidPHP"', $this->manifest());
        $this->assertStringContainsString('android:launchMode="singleTop"', $this->manifest());
        $this->assertStringNotContainsString('NativePHP Plugin Attributes', $this->manifest());
    }

    /**
     * An attribute core never declared has no value to go back to, so giving
     * it back means removing it.
     */
    public function test_it_removes_an_attribute_the_template_never_had(): void
    {
        $this->compileWith([$this->plugin('ml/plugin', [
            'application' => ['android:largeHeap' => true],
        ])]);

        $this->compileWith([]);

        $this->assertStringNotContainsString('android:largeHeap=', $this->manifest());
        $this->assertStringNotContainsString('NativePHP Plugin Attributes', $this->manifest());
    }

    public function test_it_is_idempotent_across_rebuilds(): void
    {
        $plugins = [$this->plugin('splash/plugin', [
            'application' => ['android:theme' => '@style/Theme.Splash'],
        ])];

        $this->compileWith($plugins);
        $first = $this->manifest();

        $this->compileWith($plugins);

        $this->assertSame($first, $this->manifest());
        $this->assertSame(1, substr_count($this->manifest(), 'android:theme='));
    }

    public function test_it_allows_two_plugins_that_agree(): void
    {
        $this->compileWith([
            $this->plugin('one/plugin', ['application' => ['android:largeHeap' => true]]),
            $this->plugin('two/plugin', ['application' => ['android:largeHeap' => true]]),
        ]);

        $this->assertSame(1, substr_count($this->manifest(), 'android:largeHeap='));
    }

    public function test_it_refuses_to_choose_between_two_plugins_that_disagree(): void
    {
        $this->expectException(PluginConflictException::class);
        $this->expectExceptionMessageMatches('/android:theme on <application>.*one\/plugin.*two\/plugin/s');

        $this->compileWith([
            $this->plugin('one/plugin', ['application' => ['android:theme' => '@style/Theme.One']]),
            $this->plugin('two/plugin', ['application' => ['android:theme' => '@style/Theme.Two']]),
        ]);
    }

    public function test_it_rejects_an_unknown_target(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown manifest attribute target 'provider'");

        $this->plugin('bad/plugin', ['provider' => ['android:exported' => 'false']]);
    }

    public function test_it_rejects_an_invalid_attribute_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid manifest attribute name '<script>'");

        $this->plugin('bad/plugin', ['application' => ['<script>' => 'x']]);
    }

    private function manifest(): string
    {
        return $this->files->get($this->manifestPath);
    }

    /**
     * @param  array<string, array<string, string|bool|int>>  $attributes
     */
    private function plugin(string $name, array $attributes): Plugin
    {
        $data = [
            'name' => $name,
            'namespace' => 'TestPlugin',
            'android' => ['manifest_attributes' => $attributes],
        ];

        return new Plugin(
            name: $name,
            version: '1.0.0',
            path: $this->testBasePath.'/plugins/'.md5($name),
            manifest: new PluginManifest($data)
        );
    }

    /**
     * @param  list<Plugin>  $plugins
     */
    private function compileWith(array $plugins): void
    {
        $registry = Mockery::mock(PluginRegistry::class);
        $registry->shouldReceive('detectConflicts')->andReturn([]);
        $registry->shouldReceive('all')->andReturn(collect($plugins));

        (new AndroidPluginCompiler($this->files, $registry, $this->testBasePath))->compile();
    }
}
