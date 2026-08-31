<?php

namespace Tests\Unit\Plugins;

use Illuminate\Filesystem\Filesystem;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use Native\Mobile\Plugins\ProjectFileManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProjectFileManagerTest extends TestCase
{
    private Filesystem $files;

    private string $projectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->projectPath = sys_get_temp_dir().'/nativephp-project-files-'.uniqid();
        $this->files->ensureDirectoryExists($this->projectPath.'/nativephp/android/app');
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->projectPath);

        parent::tearDown();
    }

    /** @test */
    public function it_copies_the_first_available_project_file_source(): void
    {
        $this->files->ensureDirectoryExists($this->projectPath.'/nativephp/resources');
        $this->files->put($this->projectPath.'/service.json', 'root');
        $this->files->put($this->projectPath.'/nativephp/resources/service.json', 'preferred');

        $this->manager()->sync(collect([$this->plugin([
            'sources' => ['nativephp/resources/service.json', 'service.json'],
            'destination' => 'app/service.json',
            'required' => true,
        ])]));

        $this->assertSame(
            'preferred',
            $this->files->get($this->projectPath.'/nativephp/android/app/service.json')
        );
    }

    /** @test */
    public function it_reports_a_missing_required_file_as_the_plugins_requirement(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Plugin 'vendor/files-plugin' requires one of these project files for android: service.json.");

        $this->manager()->sync(collect([$this->plugin([
            'sources' => ['service.json'],
            'destination' => 'app/service.json',
            'required' => true,
        ])]));
    }

    /** @test */
    public function it_removes_a_previously_managed_file_after_the_plugin_is_removed(): void
    {
        $this->files->put($this->projectPath.'/service.json', 'configured');
        $this->manager()->sync(collect([$this->plugin([
            'sources' => ['service.json'],
            'destination' => 'app/service.json',
        ])]));

        $this->assertFileExists($this->projectPath.'/nativephp/android/app/service.json');

        $this->manager()->sync(collect());

        $this->assertFileDoesNotExist($this->projectPath.'/nativephp/android/app/service.json');
    }

    /** @test */
    public function it_rejects_paths_that_escape_the_project(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may not traverse outside the project');

        $this->manager()->sync(collect([$this->plugin([
            'sources' => ['../service.json'],
            'destination' => 'app/service.json',
        ])]));
    }

    private function manager(): ProjectFileManager
    {
        return new ProjectFileManager($this->files, $this->projectPath.'/nativephp', 'android');
    }

    private function plugin(array $projectFile): Plugin
    {
        return new Plugin(
            'vendor/files-plugin',
            '1.0.0',
            $this->projectPath.'/vendor/files-plugin',
            new PluginManifest([
                'namespace' => 'FilesPlugin',
                'android' => ['project_files' => [$projectFile]],
            ])
        );
    }
}
