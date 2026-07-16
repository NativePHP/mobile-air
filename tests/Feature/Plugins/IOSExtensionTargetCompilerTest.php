<?php

namespace Tests\Feature\Plugins;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Mockery;
use Native\Mobile\Plugins\Compilers\IOS\ExtensionTargetCompiler;
use Native\Mobile\Plugins\Compilers\IOSPluginCompiler;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use Native\Mobile\Plugins\PluginRegistry;
use Tests\TestCase;

class IOSExtensionTargetCompilerTest extends TestCase
{
    private Filesystem $files;

    private string $testBasePath;

    private PluginRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->testBasePath = sys_get_temp_dir().'/nativephp-ios-extension-'.uniqid();
        $this->files->copyDirectory(
            dirname(__DIR__, 3).'/resources/xcode',
            $this->testBasePath.'/ios'
        );

        $entitlementsPath = $this->testBasePath.'/ios/NativePHP/NativePHP.entitlements';
        $entitlements = $this->files->get($entitlementsPath);
        $entitlements = str_replace('</dict>', <<<'XML'
	<key>com.apple.security.application-groups</key>
	<array>
		<string>group.com.example.existing</string>
	</array>
</dict>
XML, $entitlements);
        $this->files->put($entitlementsPath, $entitlements);

        $this->registry = Mockery::mock(PluginRegistry::class);
        $this->registry->shouldReceive('detectConflicts')->andReturn([])->byDefault();
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->testBasePath);
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_compiles_and_embeds_a_widget_extension_for_both_ios_hosts(): void
    {
        $plugin = $this->fixturePlugin();
        $this->registry->shouldReceive('all')->andReturn(collect([$plugin]));

        $compiler = $this->compiler();
        $compiler->compile();

        $extensionPath = $this->testBasePath.'/ios/Extensions/NativePHPWidgetsExtension';
        $this->assertFileExists($extensionPath.'/NativePHPWidget.swift');
        $this->assertFileExists($extensionPath.'/Info.plist');
        $this->assertFileExists($extensionPath.'/NativePHPWidgetsExtension.entitlements');

        $hostPath = $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/NativePHPWidgets';
        $this->assertFileExists($hostPath.'/WidgetBridge.swift');
        $this->assertFileDoesNotExist($hostPath.'/extension/NativePHPWidget.swift');

        $plist = $this->files->get($extensionPath.'/Info.plist');
        $this->assertStringContainsString('com.apple.widgetkit-extension', $plist);
        $this->assertStringContainsString('NativePHP Widgets', $plist);

        $extensionEntitlements = $this->files->get($extensionPath.'/NativePHPWidgetsExtension.entitlements');
        $hostEntitlements = $this->files->get($this->testBasePath.'/ios/NativePHP/NativePHP.entitlements');

        foreach ([$extensionEntitlements, $hostEntitlements] as $entitlements) {
            $this->assertStringContainsString('group.com.example.widget-demo.widgets', $entitlements);
        }

        $this->assertStringNotContainsString('aps-environment', $extensionEntitlements);
        $this->assertStringContainsString('group.com.example.existing', $hostEntitlements);
        $this->assertStringContainsString('aps-environment', $hostEntitlements);
        $this->assertStringContainsString('<string>development</string>', $hostEntitlements);
        $this->assertStringNotContainsString('<string>production</string>', $hostEntitlements);

        $project = $this->projectContents();
        $this->assertStringContainsString('productType = "com.apple.product-type.app-extension";', $project);
        $this->assertStringContainsString('PRODUCT_BUNDLE_IDENTIFIER = com.example.widget-demo.widgets;', $project);
        $this->assertStringContainsString('CURRENT_PROJECT_VERSION = 1;', $project);
        $this->assertStringContainsString('path = NativePHPWidgetsExtension.appex;', $project);
        $this->assertSame(2, substr_count($project, 'dstSubfolderSpec = 13;'));
        $this->assertSame(3, substr_count($project, 'name = NativePHPWidgetsExtension;'));
        $this->assertSame(4, substr_count($project, 'CODE_SIGN_ENTITLEMENTS = NativePHP/NativePHP.entitlements;'));

        $deviceTarget = $this->targetBlock($project, 'NativePHP');
        $simulatorTarget = $this->targetBlock($project, 'NativePHP-simulator');
        $this->assertStringContainsString('DeviceBuildPhases', $deviceTarget);
        $this->assertStringNotContainsString('SimulatorBuildPhases', $deviceTarget);
        $this->assertStringContainsString('SimulatorBuildPhases', $simulatorTarget);
        $this->assertStringNotContainsString('DeviceBuildPhases', $simulatorTarget);
    }

    public function test_compilation_is_byte_idempotent_and_repairs_the_bundle_identifier(): void
    {
        $plugin = $this->fixturePlugin();
        $this->registry->shouldReceive('all')->andReturn(collect([$plugin]));
        $compiler = $this->compiler();

        $compiler->compile();
        $first = $this->projectContents();
        $compiler->compile();

        $this->assertSame($first, $this->projectContents());

        $this->files->put(
            $this->projectPath(),
            str_replace('com.example.widget-demo.widgets', 'com.example.widget-demo', $first)
        );

        $compiler->compile();

        $project = $this->projectContents();
        $this->assertStringContainsString('PRODUCT_BUNDLE_IDENTIFIER = com.example.widget-demo.widgets;', $project);
        $this->assertSame(1, substr_count($project, 'NATIVEPHP_EXTENSIONS_BEGIN PBXNativeTarget'));
    }

    public function test_it_excludes_flat_extension_sources_from_the_host_target(): void
    {
        $pluginPath = $this->testBasePath.'/plugins/flat-widget';
        $this->files->ensureDirectoryExists($pluginPath.'/resources/ios/extension');
        $this->files->put($pluginPath.'/resources/ios/Host.swift', 'enum Host {}');
        $this->files->put($pluginPath.'/resources/ios/extension/Widget.swift', '@main struct Widget {}');

        $plugin = $this->plugin($pluginPath, [
            'name' => 'FlatWidgetExtension',
            'type' => 'widget-extension',
            'bundle_id_suffix' => 'flat-widget',
            'sources_dir' => 'extension',
        ]);
        $this->registry->shouldReceive('all')->andReturn(collect([$plugin]));
        $staleHostSource = $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/FlatWidget/extension/Widget.swift';
        $this->files->ensureDirectoryExists(dirname($staleHostSource));
        $this->files->put($staleHostSource, '@main struct StaleWidget {}');

        $this->compiler()->compile();

        $host = $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/FlatWidget';
        $this->assertFileExists($host.'/Host.swift');
        $this->assertFileDoesNotExist($host.'/extension/Widget.swift');
        $this->assertFileExists($this->testBasePath.'/ios/Extensions/FlatWidgetExtension/Widget.swift');
    }

    public function test_it_excludes_an_extension_only_sources_directory_from_the_host_target(): void
    {
        $pluginPath = $this->testBasePath.'/plugins/extension-only-widget';
        $this->files->ensureDirectoryExists($pluginPath.'/resources/ios/Sources');
        $this->files->put($pluginPath.'/resources/ios/Sources/Widget.swift', '@main struct Widget {}');

        $plugin = $this->plugin($pluginPath, [
            'name' => 'ExtensionOnlyWidget',
            'type' => 'widget-extension',
            'bundle_id_suffix' => 'extension-only-widget',
            'sources_dir' => 'Sources',
        ]);
        $this->registry->shouldReceive('all')->andReturn(collect([$plugin]));

        $this->compiler()->compile();

        $host = $this->testBasePath.'/ios/NativePHP/Bridge/Plugins/FlatWidget';
        $this->assertFileDoesNotExist($host.'/Widget.swift');
        $this->assertFileExists($this->testBasePath.'/ios/Extensions/ExtensionOnlyWidget/Widget.swift');
    }

    public function test_it_fails_when_the_declared_source_directory_is_missing(): void
    {
        $plugin = $this->plugin($this->testBasePath.'/plugins/missing', [
            'name' => 'MissingWidgetExtension',
            'type' => 'widget-extension',
            'bundle_id_suffix' => 'missing-widget',
            'sources_dir' => 'extension',
        ]);
        $this->registry->shouldReceive('all')->andReturn(collect([$plugin]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sources_dir');

        $this->compiler()->compile();
    }

    public function test_it_removes_managed_targets_when_no_plugin_declares_them(): void
    {
        $unownedPath = $this->testBasePath.'/ios/Extensions/HandWrittenExtension';
        $this->files->ensureDirectoryExists($unownedPath);
        $this->files->put($unownedPath.'/Keep.swift', 'struct Keep {}');

        $plugin = $this->fixturePlugin();
        $this->registry->shouldReceive('all')->andReturn(collect([$plugin]));
        $this->compiler()->compile();

        (new ExtensionTargetCompiler(
            $this->files,
            $this->testBasePath.'/ios',
            'com.example.widget-demo'
        ))->compile(collect());

        $this->assertDirectoryDoesNotExist($this->testBasePath.'/ios/Extensions/NativePHPWidgetsExtension');
        $this->assertFileExists($unownedPath.'/Keep.swift');
        $this->assertFileDoesNotExist($this->testBasePath.'/ios/Extensions/.nativephp-extension-targets.json');
        $this->assertStringNotContainsString('NATIVEPHP_EXTENSIONS_BEGIN', $this->projectContents());
        $this->assertStringNotContainsString('NativePHPWidgetsExtension.appex', $this->projectContents());
    }

    public function test_it_refuses_to_overwrite_an_unowned_extension_directory(): void
    {
        $path = $this->testBasePath.'/ios/Extensions/NativePHPWidgetsExtension';
        $this->files->ensureDirectoryExists($path);
        $this->files->put($path.'/Keep.swift', 'struct Keep {}');

        $plugin = $this->fixturePlugin();
        $this->registry->shouldReceive('all')->andReturn(collect([$plugin]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to overwrite unowned');

        $this->compiler()->compile();
    }

    public function test_it_rejects_an_invalid_app_identifier(): void
    {
        $plugin = $this->fixturePlugin();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid NativePHP app ID');

        (new ExtensionTargetCompiler(
            $this->files,
            $this->testBasePath.'/ios',
            'com..example'
        ))->compile(collect([$plugin]));
    }

    private function compiler(): IOSPluginCompiler
    {
        return (new IOSPluginCompiler($this->files, $this->registry, $this->testBasePath))
            ->setAppId('com.example.widget-demo')
            ->setConfig(['version' => '1.2.3', 'version_code' => 42]);
    }

    private function fixturePlugin(): Plugin
    {
        $path = dirname(__DIR__, 2).'/Fixtures/plugins/widget-extension-plugin';

        return new Plugin(
            'nativephp/widgets',
            '1.0.0',
            $path,
            PluginManifest::fromFile($path.'/nativephp.json')
        );
    }

    private function plugin(string $path, array $target): Plugin
    {
        return new Plugin('nativephp/flat-widget', '1.0.0', $path, new PluginManifest([
            'namespace' => 'FlatWidget',
            'ios' => [
                'min_version' => '17.0',
                'extension_targets' => [$target],
            ],
        ]));
    }

    private function projectPath(): string
    {
        return $this->testBasePath.'/ios/NativePHP.xcodeproj/project.pbxproj';
    }

    private function projectContents(): string
    {
        return $this->files->get($this->projectPath());
    }

    private function targetBlock(string $project, string $name): string
    {
        $pattern = '/[A-F0-9]{24} \/\* '.preg_quote($name, '/').' \*\/ = \{\s+'
            .'isa = PBXNativeTarget;.*?\n\t\t\};/s';

        $this->assertMatchesRegularExpression($pattern, $project);
        preg_match($pattern, $project, $matches);

        return $matches[0];
    }
}
