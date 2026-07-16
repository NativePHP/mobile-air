<?php

namespace Tests\Feature\Plugins;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Native\Mobile\Plugins\Compilers\IOS\ExtensionTargetCompiler;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use Tests\TestCase;

class IOSExtensionTargetRecoveryTest extends TestCase
{
    private Filesystem $files;

    private string $testBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->testBasePath = sys_get_temp_dir().'/nativephp-ios-extension-recovery-'.uniqid();
        $this->files->copyDirectory(
            dirname(__DIR__, 3).'/resources/xcode',
            $this->testBasePath.'/ios'
        );
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->testBasePath);

        parent::tearDown();
    }

    public function test_it_recovers_generated_sources_after_an_interrupted_compilation(): void
    {
        $pluginPath = $this->testBasePath.'/plugin';
        $this->files->ensureDirectoryExists($pluginPath.'/resources/ios/extension');
        $this->files->put($pluginPath.'/resources/ios/extension/Widget.swift', '@main struct Widget {}');

        $compiler = new ExtensionTargetCompiler(
            $this->files,
            $this->testBasePath.'/ios',
            'com.example.app'
        );

        try {
            $compiler->compile(collect([$this->plugin($pluginPath, null)]));
            $this->fail('The invalid property list should fail compilation.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Property list values', $exception->getMessage());
        }

        $ownershipPath = $this->testBasePath.'/ios/Extensions/.nativephp-extension-targets.json';
        $this->assertFileExists($ownershipPath);

        $compiler->compile(collect([$this->plugin($pluginPath, 'Recovered')]));

        $extensionPath = $this->testBasePath.'/ios/Extensions/RecoveryWidget';
        $this->assertFileExists($extensionPath.'/Widget.swift');
        $this->assertStringContainsString('Recovered', $this->files->get($extensionPath.'/Info.plist'));
    }

    private function plugin(string $path, mixed $customValue): Plugin
    {
        return new Plugin('nativephp/recovery-widget', '1.0.0', $path, new PluginManifest([
            'namespace' => 'RecoveryWidget',
            'ios' => [
                'extension_targets' => [[
                    'name' => 'RecoveryWidget',
                    'type' => 'widget-extension',
                    'bundle_id_suffix' => 'recovery-widget',
                    'sources_dir' => 'extension',
                    'info_plist' => ['CustomValue' => $customValue],
                ]],
            ],
        ]));
    }
}
