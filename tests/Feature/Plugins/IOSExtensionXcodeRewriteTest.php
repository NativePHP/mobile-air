<?php

namespace Tests\Feature\Plugins;

use Illuminate\Filesystem\Filesystem;
use Mockery;
use Native\Mobile\Plugins\Compilers\IOS\ExtensionTargetCompiler;
use Native\Mobile\Plugins\Compilers\IOS\ExtensionTargetProjectId;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use Tests\TestCase;

class IOSExtensionXcodeRewriteTest extends TestCase
{
    private Filesystem $files;

    private string $testBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->testBasePath = sys_get_temp_dir().'/nativephp-ios-xcode-rewrite-'.uniqid();
        $this->files->copyDirectory(
            dirname(__DIR__, 3).'/resources/xcode',
            $this->testBasePath.'/ios'
        );
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->testBasePath);
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_recovers_after_xcode_removes_managed_comments(): void
    {
        $compiler = $this->compiler($this->files);
        $compiler->compile(collect([$this->plugin()]));
        $this->simulateXcodeSave();

        $compiler->compile(collect([$this->plugin()]));

        $project = $this->projectContents();
        $targetId = ExtensionTargetProjectId::for('NativePHPWidgetsExtension', 'target');
        $this->assertSame(1, substr_count($project, $targetId.' /* NativePHPWidgetsExtension */ = {'));
        $this->assertSame(1, substr_count($project, 'NATIVEPHP_EXTENSIONS_BEGIN PBXNativeTarget'));
        $this->assertSame(2, substr_count($project, 'dstSubfolderSpec = 13;'));
        $this->assertSame(4, substr_count($project, 'CODE_SIGN_ENTITLEMENTS = NativePHP/NativePHP.entitlements;'));
    }

    public function test_it_removes_markerless_managed_objects_when_the_plugin_is_removed(): void
    {
        $compiler = $this->compiler($this->files);
        $compiler->compile(collect([$this->plugin()]));
        $this->simulateXcodeSave();

        $compiler->compile(collect());

        $project = $this->projectContents();
        $this->assertStringNotContainsString('NativePHPWidgetsExtension', $project);
        $this->assertStringNotContainsString('NATIVEPHP_EXTENSIONS_BEGIN', $project);
        $this->assertSame(2, substr_count($project, 'CODE_SIGN_ENTITLEMENTS = NativePHP/NativePHP.entitlements;'));
        $this->assertDirectoryDoesNotExist($this->testBasePath.'/ios/Extensions/NativePHPWidgetsExtension');
    }

    public function test_the_clean_no_extension_path_does_not_write_the_xcode_project(): void
    {
        $projectPath = $this->projectPath();
        $files = Mockery::mock(Filesystem::class)->makePartial();
        $files->shouldNotReceive('put')->with($projectPath, Mockery::any());

        $this->compiler($files)->compile(collect());

        $this->assertSame(
            $this->files->get($projectPath),
            $files->get($projectPath)
        );
    }

    private function compiler(Filesystem $files): ExtensionTargetCompiler
    {
        return new ExtensionTargetCompiler(
            $files,
            $this->testBasePath.'/ios',
            'com.example.widget-demo'
        );
    }

    private function plugin(): Plugin
    {
        $path = dirname(__DIR__, 2).'/Fixtures/plugins/widget-extension-plugin';

        return new Plugin(
            'nativephp/widgets',
            '1.0.0',
            $path,
            PluginManifest::fromFile($path.'/nativephp.json')
        );
    }

    private function simulateXcodeSave(): void
    {
        $project = preg_replace(
            '/^[ \t]*\/\* NATIVEPHP_EXTENSIONS_(?:BEGIN|END) [^*]+ \*\/\R?/m',
            '',
            $this->projectContents()
        );

        $this->files->put($this->projectPath(), $project);
    }

    private function projectPath(): string
    {
        return $this->testBasePath.'/ios/NativePHP.xcodeproj/project.pbxproj';
    }

    private function projectContents(): string
    {
        return $this->files->get($this->projectPath());
    }
}
