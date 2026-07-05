<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Native\Mobile\Support\BundleExclusions;
use Native\Mobile\Support\BundleFileManager;
use Tests\TestCase;

class IosBuildCopyTest extends TestCase
{
    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_ios_build_test_'.uniqid();
        File::makeDirectory($this->testProjectPath, 0755, true);

        app()->setBasePath($this->testProjectPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);
        parent::tearDown();
    }

    public function test_excluded_paths_returns_all_expected_patterns(): void
    {
        $paths = BundleFileManager::excludes();

        foreach (['.git', '.github', 'node_modules', 'tests', '.DS_Store'] as $pattern) {
            $this->assertContains($pattern, $paths);
        }

        foreach (['/nativephp', '/output', '/build', '/storage/logs', '/storage/framework'] as $pattern) {
            $this->assertContains($pattern, $paths);
        }

        foreach (['/database/database.sqlite', '/*.js', '/*.md'] as $pattern) {
            $this->assertContains($pattern, $paths);
        }

        $this->assertContains('vendor/nativephp/mobile/resources', $paths);
        $this->assertContains('vendor/*/*/vendor', $paths);
    }

    public function test_excluded_paths_includes_vendor_non_runtime_file_patterns(): void
    {
        $paths = BundleFileManager::excludes();

        foreach (['*.md', 'LICENSE*', 'docs', '*.yml', '*.yaml', '*.neon', '*.neon.dist'] as $pattern) {
            $this->assertContains($pattern, $paths);
        }
    }

    public function test_excluded_paths_separates_anchored_from_any_depth_patterns(): void
    {
        $paths = BundleFileManager::excludes();

        $anchored = array_filter($paths, fn ($p) => str_starts_with($p, '/'));
        $anyDepth = array_filter($paths, fn ($p) => ! str_starts_with($p, '/') && ! str_starts_with($p, 'vendor/'));

        $this->assertNotEmpty($anchored);
        $this->assertNotEmpty($anyDepth);
        $this->assertContains('node_modules', $anyDepth);
        $this->assertContains('/nativephp', $anchored);
    }

    public function test_export_ignore_returns_empty_when_no_vendor_dir(): void
    {
        $this->assertEmpty(
            BundleFileManager::vendorExportIgnorePatterns($this->testProjectPath)
        );
    }

    public function test_export_ignore_returns_empty_when_no_gitattributes(): void
    {
        File::makeDirectory($this->testProjectPath.'/vendor/acme/foo/src', 0755, true);

        $this->assertEmpty(
            BundleFileManager::vendorExportIgnorePatterns($this->testProjectPath)
        );
    }

    public function test_export_ignore_parses_gitattributes_and_strips_leading_slashes(): void
    {
        $this->createVendorGitattributes('acme/widget', "/tests export-ignore\n/docs export-ignore\n/.github export-ignore\n");

        $result = BundleFileManager::vendorExportIgnorePatterns($this->testProjectPath);

        $this->assertArrayHasKey('vendor/acme/widget/', $result);
        $this->assertContains('tests', $result['vendor/acme/widget/']);
        $this->assertContains('docs', $result['vendor/acme/widget/']);
        $this->assertContains('.github', $result['vendor/acme/widget/']);
    }

    public function test_export_ignore_skips_comments_and_non_export_ignore_lines(): void
    {
        $this->createVendorGitattributes('acme/bar', "# comment\n*.php diff=php\n/tests export-ignore\n*.js text eol=lf\n");

        $result = BundleFileManager::vendorExportIgnorePatterns($this->testProjectPath);

        $this->assertCount(1, $result['vendor/acme/bar/']);
        $this->assertContains('tests', $result['vendor/acme/bar/']);
    }

    public function test_export_ignore_handles_multiple_packages(): void
    {
        $this->createVendorGitattributes('acme/alpha', "/tests export-ignore\n");
        $this->createVendorGitattributes('acme/beta', "/docs export-ignore\n");
        File::makeDirectory($this->testProjectPath.'/vendor/other/gamma', 0755, true);

        $result = BundleFileManager::vendorExportIgnorePatterns($this->testProjectPath);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('vendor/acme/alpha/', $result);
        $this->assertArrayHasKey('vendor/acme/beta/', $result);
        $this->assertArrayNotHasKey('vendor/other/gamma/', $result);
    }

    public function test_export_ignore_handles_empty_gitattributes(): void
    {
        $this->createVendorGitattributes('acme/empty', '');

        $this->assertEmpty(
            BundleFileManager::vendorExportIgnorePatterns($this->testProjectPath)
        );
    }

    public function test_copy_runs_rsync_with_correct_flags_and_excludes(): void
    {
        $appPath = $this->fakeRsyncAndGetAppPath();

        BundleFileManager::copy(base_path(), $appPath);

        Process::assertRan(function ($process) {
            $cmd = $process->command;

            return str_contains($cmd, 'rsync -a --copy-links')
                && str_contains($cmd, "--exclude='node_modules'")
                && str_contains($cmd, "--exclude='.git'")
                && str_contains($cmd, "--exclude='/nativephp'");
        });
    }

    public function test_copy_throws_on_rsync_failure(): void
    {
        $appPath = $this->fakeRsyncAndGetAppPath(exitCode: 1);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to copy app bundle');

        BundleFileManager::copy(base_path(), $appPath);
    }

    public function test_copy_includes_vendor_export_ignore_patterns_in_rsync(): void
    {
        $this->createVendorGitattributes('acme/plugin', "/tests export-ignore\n/docs export-ignore\n");
        $appPath = $this->fakeRsyncAndGetAppPath();

        BundleFileManager::copy(base_path(), $appPath);

        Process::assertRan(function ($process) {
            $cmd = $process->command;

            return str_contains($cmd, "--exclude='vendor/acme/plugin/tests'")
                && str_contains($cmd, "--exclude='vendor/acme/plugin/docs'");
        });
    }

    public function test_copy_passes_vendor_non_runtime_excludes_to_rsync(): void
    {
        $appPath = $this->fakeRsyncAndGetAppPath();

        BundleFileManager::copy(base_path(), $appPath);

        Process::assertRan(function ($process) {
            $cmd = $process->command;

            return str_contains($cmd, "--exclude='*.md'")
                && str_contains($cmd, "--exclude='LICENSE*'")
                && str_contains($cmd, "--exclude='docs'")
                && str_contains($cmd, "--exclude='*.yml'")
                && str_contains($cmd, "--exclude='*.yaml'")
                && str_contains($cmd, "--exclude='*.neon'")
                && str_contains($cmd, "--exclude='vendor/*/*/vendor'");
        });
    }

    public function test_copy_passes_project_anchored_excludes_to_rsync(): void
    {
        $appPath = $this->fakeRsyncAndGetAppPath();

        BundleFileManager::copy(base_path(), $appPath);

        Process::assertRan(function ($process) {
            $cmd = $process->command;

            return str_contains($cmd, "--exclude='/nativephp'")
                && str_contains($cmd, "--exclude='/storage/logs'")
                && str_contains($cmd, "--exclude='/storage/framework'")
                && str_contains($cmd, "--exclude='/bootstrap/cache/*'");
        });
    }

    public function test_remove_deletes_standard_directories(): void
    {
        $appPath = $this->createAppPath([
            'node_modules' => ['some-package' => ['index.js' => '{}']],
            'vendor' => ['bin' => ['phpunit' => '#!/usr/bin/env php']],
            'tests' => ['Unit' => ['ExampleTest.php' => '<?php']],
            'storage' => ['logs' => ['laravel.log' => 'log']],
            'app' => ['Models' => ['User.php' => '<?php']],
        ]);

        BundleFileManager::removeUnnecessaryFiles($appPath);

        $this->assertDirectoryDoesNotExist($appPath.'node_modules');
        $this->assertDirectoryDoesNotExist($appPath.'vendor/bin');
        $this->assertDirectoryDoesNotExist($appPath.'tests');
        $this->assertDirectoryDoesNotExist($appPath.'storage/logs');
        $this->assertDirectoryExists($appPath.'app/Models');
    }

    public function test_remove_handles_glob_wildcard_for_nested_vendor_dirs(): void
    {
        $appPath = $this->createAppPath([
            'vendor' => [
                'acme' => ['plugin' => [
                    'vendor' => ['nested' => ['file.php' => '<?php']],
                    'src' => ['Plugin.php' => '<?php'],
                ]],
                'other' => ['package' => [
                    'vendor' => ['dep' => ['file.php' => '<?php']],
                ]],
            ],
        ]);

        BundleFileManager::removeUnnecessaryFiles($appPath);

        $this->assertDirectoryDoesNotExist($appPath.'vendor/acme/plugin/vendor');
        $this->assertDirectoryDoesNotExist($appPath.'vendor/other/package/vendor');
        $this->assertDirectoryExists($appPath.'vendor/acme/plugin/src');
    }

    public function test_remove_deletes_standard_files(): void
    {
        $appPath = $this->createAppPath([
            'artisan' => '#!/usr/bin/env php',
            '.gitignore' => '/vendor',
            '.editorconfig' => 'root = true',
            '.DS_Store' => 'binary',
            'database' => ['database.sqlite' => ''],
            'app' => ['bootstrap.php' => '<?php'],
        ]);

        BundleFileManager::removeUnnecessaryFiles($appPath);

        $this->assertFileDoesNotExist($appPath.'artisan');
        $this->assertFileDoesNotExist($appPath.'.gitignore');
        $this->assertFileDoesNotExist($appPath.'.editorconfig');
        $this->assertFileDoesNotExist($appPath.'.DS_Store');
        $this->assertFileExists($appPath.'app/bootstrap.php');
    }

    public function test_remove_deletes_livewire_big_image_and_pint_builds(): void
    {
        $appPath = $this->createAppPath([
            'vendor' => [
                'livewire' => ['livewire' => ['src' => ['Features' => ['SupportFileUploads' => [
                    'browser_test_image_big.jpg' => 'large binary data',
                    'FileUploadController.php' => '<?php // keep',
                ]]]]],
                'laravel' => ['pint' => [
                    'builds' => ['pint' => 'binary'],
                    'src' => ['Command.php' => '<?php'],
                ]],
            ],
        ]);

        BundleFileManager::removeUnnecessaryFiles($appPath);

        $this->assertFileDoesNotExist($appPath.'vendor/livewire/livewire/src/Features/SupportFileUploads/browser_test_image_big.jpg');
        $this->assertFileExists($appPath.'vendor/livewire/livewire/src/Features/SupportFileUploads/FileUploadController.php');
        $this->assertDirectoryDoesNotExist($appPath.'vendor/laravel/pint/builds');
        $this->assertDirectoryExists($appPath.'vendor/laravel/pint/src');
    }

    public function test_remove_deletes_root_glob_matched_files(): void
    {
        $appPath = $this->createAppPath([
            'vite.config.js' => 'export default {}',
            'tailwind.config.js' => 'module.exports = {}',
            'README.md' => '# App',
            'CHANGELOG.md' => '## 1.0',
            'composer.lock' => '{}',
            'phpunit.xml' => '<phpunit/>',
            '.env.example' => 'APP_NAME=',
            'composer.json' => '{"name":"app"}',
        ]);

        BundleFileManager::removeUnnecessaryFiles($appPath);

        $this->assertFileDoesNotExist($appPath.'vite.config.js');
        $this->assertFileDoesNotExist($appPath.'tailwind.config.js');
        $this->assertFileDoesNotExist($appPath.'README.md');
        $this->assertFileDoesNotExist($appPath.'CHANGELOG.md');
        $this->assertFileDoesNotExist($appPath.'composer.lock');
        $this->assertFileDoesNotExist($appPath.'phpunit.xml');
        $this->assertFileDoesNotExist($appPath.'.env.example');
        $this->assertFileExists($appPath.'composer.json');
    }

    public function test_remove_handles_missing_directories_gracefully(): void
    {
        $appPath = $this->testProjectPath.'/nativephp/ios/laravel/';
        File::makeDirectory($appPath, 0755, true);

        BundleFileManager::removeUnnecessaryFiles($appPath);

        $this->assertTrue(true);
    }

    /**
     * Excludes should cover every cleanup target so files are never
     * copied in the first place (preventing copy-then-delete waste).
     */
    public function test_excludes_cover_all_cleanup_directories(): void
    {
        $excludes = BundleFileManager::excludes();

        $cleanupDirs = ['.git', '.github', 'node_modules', 'tests', 'vendor/*/*/vendor',
            'storage/logs', 'storage/framework', 'public/storage'];

        foreach ($cleanupDirs as $dir) {
            $covered = in_array($dir, $excludes, true) || in_array('/'.$dir, $excludes, true);
            $this->assertTrue($covered, "Cleanup dir '{$dir}' not covered by excludes");
        }
    }

    public function test_excludes_cover_all_cleanup_files(): void
    {
        $excludes = BundleFileManager::excludes();
        $cleanupFiles = BundleFileManager::cleanupFiles();

        foreach ($cleanupFiles as $file) {
            $covered = in_array($file, $excludes, true) || in_array('/'.$file, $excludes, true);

            // CLEANUP_ONLY items are intentionally not in rsync excludes
            if (in_array($file, BundleExclusions::CLEANUP_ONLY, true)) {
                $this->assertFalse($covered, "Cleanup-only file '{$file}' should not be in rsync excludes");
            } else {
                $this->assertTrue($covered, "Cleanup file '{$file}' not covered by excludes");
            }
        }
    }

    /**
     * Copy delegates to an external process (rsync/robocopy) so PHP never
     * loads the file tree into memory.
     */
    public function test_copy_delegates_to_external_process_not_php_iterator(): void
    {
        $source = file_get_contents(
            (new \ReflectionMethod(BundleFileManager::class, 'copyWithRsync'))->getFileName()
        );

        $this->assertStringContainsString('Process::run', $source);
        $this->assertStringNotContainsString('RecursiveIteratorIterator', $source);
        $this->assertStringNotContainsString('RecursiveDirectoryIterator', $source);
    }

    public function test_excluded_paths_includes_config_cleanup_exclude_files(): void
    {
        $paths = BundleFileManager::excludes([
            'storage/framework/sessions',
            'storage/framework/cache',
        ]);

        $this->assertContains('/storage/framework/sessions', $paths);
        $this->assertContains('/storage/framework/cache', $paths);
    }

    public function test_excluded_paths_anchors_config_values_with_leading_slash(): void
    {
        $paths = BundleFileManager::excludes([
            'storage/framework/sessions',
            '/storage/framework/cache',
        ]);

        $this->assertContains('/storage/framework/sessions', $paths);
        $this->assertContains('/storage/framework/cache', $paths);
        $this->assertNotContains('storage/framework/sessions', $paths);
    }

    public function test_excluded_paths_handles_empty_config(): void
    {
        $paths = BundleFileManager::excludes([]);

        $this->assertNotEmpty($paths);
        $this->assertContains('.git', $paths);
    }

    public function test_copy_passes_config_excludes_to_rsync(): void
    {
        $appPath = $this->fakeRsyncAndGetAppPath();

        BundleFileManager::copy(base_path(), $appPath, [
            'storage/framework/sessions',
            'custom/dir',
        ]);

        Process::assertRan(function ($process) {
            $cmd = $process->command;

            return str_contains($cmd, "--exclude='/storage/framework/sessions'")
                && str_contains($cmd, "--exclude='/custom/dir'");
        });
    }

    public function test_remove_deletes_config_specified_directories(): void
    {
        $appPath = $this->createAppPath([
            'custom' => ['cache' => ['data.bin' => 'cached']],
            'tmp' => ['build' => ['output.js' => 'built']],
            'app' => ['Models' => ['User.php' => '<?php']],
        ]);

        BundleFileManager::removeUnnecessaryFiles($appPath, ['custom/cache', 'tmp/build']);

        $this->assertDirectoryDoesNotExist($appPath.'custom/cache');
        $this->assertDirectoryDoesNotExist($appPath.'tmp/build');
        $this->assertDirectoryExists($appPath.'app/Models');
    }

    public function test_remove_deletes_config_specified_files(): void
    {
        $appPath = $this->createAppPath([
            'custom-file.txt' => 'should be removed',
            'app' => ['keep.php' => '<?php'],
        ]);

        BundleFileManager::removeUnnecessaryFiles($appPath, ['custom-file.txt']);

        $this->assertFileDoesNotExist($appPath.'custom-file.txt');
        $this->assertFileExists($appPath.'app/keep.php');
    }

    public function test_remove_handles_nonexistent_config_paths(): void
    {
        $appPath = $this->testProjectPath.'/nativephp/ios/laravel/';
        File::makeDirectory($appPath, 0755, true);

        BundleFileManager::removeUnnecessaryFiles($appPath, ['does/not/exist', 'also/missing.txt']);

        $this->assertTrue(true);
    }

    public function test_excludes_cover_config_cleanup_paths(): void
    {
        $excludes = BundleFileManager::excludes([
            'storage/framework/sessions',
            'storage/framework/cache',
        ]);

        $this->assertContains('/storage/framework/sessions', $excludes);
        $this->assertContains('/storage/framework/cache', $excludes);
    }

    // Helpers

    protected function createVendorGitattributes(string $package, string $content): void
    {
        $path = $this->testProjectPath.'/vendor/'.$package;
        File::makeDirectory($path, 0755, true);
        File::put($path.'/.gitattributes', $content);
    }

    protected function fakeRsyncAndGetAppPath(int $exitCode = 0): string
    {
        Process::fake([
            'rsync*' => Process::result(output: '', errorOutput: $exitCode ? 'error' : '', exitCode: $exitCode),
        ]);

        $appPath = $this->testProjectPath.'/nativephp/ios/laravel/';
        File::makeDirectory($appPath, 0755, true);

        return $appPath;
    }

    protected function createAppPath(array $structure): string
    {
        $appPath = $this->testProjectPath.'/nativephp/ios/laravel/';
        $this->createDirectoryStructure($appPath, $structure);

        return $appPath;
    }
}
