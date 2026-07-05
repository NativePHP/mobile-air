<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Native\Mobile\Support\BundleExclusions;
use Native\Mobile\Support\BundleFileManager;
use Tests\TestCase;

class BundleFileManagerTest extends TestCase
{
    public function test_excludes_anchors_project_paths(): void
    {
        $excludes = BundleFileManager::excludes();

        foreach (BundleExclusions::PROJECT as $path) {
            $this->assertContains('/'.$path, $excludes);
        }
    }

    public function test_excludes_leaves_any_depth_patterns_unanchored(): void
    {
        $excludes = BundleFileManager::excludes();

        foreach (BundleExclusions::ANY_DEPTH as $pattern) {
            $this->assertContains($pattern, $excludes);
            $this->assertNotContains('/'.$pattern, $excludes);
        }
    }

    public function test_cleanup_directories_includes_all_expected(): void
    {
        $dirs = BundleFileManager::cleanupDirectories();

        foreach (BundleExclusions::ANY_DEPTH as $pattern) {
            $this->assertContains($pattern, $dirs);
        }

        foreach (BundleExclusions::PROJECT as $path) {
            $this->assertContains($path, $dirs);
        }

        $this->assertContains('vendor/bin', $dirs);
        $this->assertContains('vendor/*/*/vendor', $dirs);
        $this->assertContains('vendor/laravel/pint/builds', $dirs);
    }

    public function test_cleanup_files_includes_all_expected(): void
    {
        $files = BundleFileManager::cleanupFiles();

        foreach (BundleExclusions::ANY_DEPTH as $pattern) {
            $this->assertContains($pattern, $files);
        }

        foreach (BundleExclusions::PROJECT as $path) {
            $this->assertContains($path, $files);
        }

        $this->assertContains(
            'vendor/livewire/livewire/src/Features/SupportFileUploads/browser_test_image_big.jpg',
            $files
        );
    }

    public function test_excludes_with_config_anchors_and_merges(): void
    {
        $result = BundleFileManager::excludes(['custom/path', 'another/dir']);

        $this->assertContains('/custom/path', $result);
        $this->assertContains('/another/dir', $result);
        $this->assertContains('.git', $result);
        $this->assertContains('/nativephp', $result);
    }

    public function test_excludes_with_empty_config_matches_base(): void
    {
        $this->assertEquals(
            BundleFileManager::excludes(),
            BundleFileManager::excludes([])
        );
    }

    public function test_excludes_preserves_already_anchored_config(): void
    {
        $result = BundleFileManager::excludes(['/already/anchored']);

        $this->assertContains('/already/anchored', $result);
        $this->assertNotContains('//already/anchored', $result);
    }

    public function test_remove_deletes_directories_and_files(): void
    {
        $appPath = sys_get_temp_dir().'/nativephp_bundle_test_'.uniqid().'/';
        mkdir($appPath, 0755, true);

        $this->createDirectoryStructure($appPath, [
            'node_modules' => ['pkg' => ['index.js' => '{}']],
            'tests' => ['Unit' => ['Test.php' => '<?php']],
            '.git' => ['config' => 'gitconfig'],
            'artisan' => '#!/usr/bin/env php',
            '.gitignore' => '/vendor',
            'app' => ['Models' => ['User.php' => '<?php']],
        ]);

        BundleFileManager::removeUnnecessaryFiles($appPath);

        $this->assertDirectoryDoesNotExist($appPath.'node_modules');
        $this->assertDirectoryDoesNotExist($appPath.'tests');
        $this->assertDirectoryDoesNotExist($appPath.'.git');
        $this->assertFileDoesNotExist($appPath.'artisan');
        $this->assertFileDoesNotExist($appPath.'.gitignore');
        $this->assertDirectoryExists($appPath.'app/Models');

        File::deleteDirectory($appPath);
    }

    public function test_remove_handles_config_paths(): void
    {
        $appPath = sys_get_temp_dir().'/nativephp_bundle_test_'.uniqid().'/';
        mkdir($appPath, 0755, true);

        $this->createDirectoryStructure($appPath, [
            'custom' => ['cache' => ['data.bin' => 'cached']],
            'extra.log' => 'log content',
            'app' => ['keep.php' => '<?php'],
        ]);

        BundleFileManager::removeUnnecessaryFiles($appPath, ['custom/cache', 'extra.log']);

        $this->assertDirectoryDoesNotExist($appPath.'custom/cache');
        $this->assertFileDoesNotExist($appPath.'extra.log');
        $this->assertFileExists($appPath.'app/keep.php');

        File::deleteDirectory($appPath);
    }

    public function test_remove_handles_nonexistent_paths(): void
    {
        $appPath = sys_get_temp_dir().'/nativephp_bundle_test_'.uniqid().'/';
        mkdir($appPath, 0755, true);

        BundleFileManager::removeUnnecessaryFiles($appPath, ['does/not/exist', 'missing.txt']);

        $this->assertTrue(true);

        File::deleteDirectory($appPath);
    }
}
