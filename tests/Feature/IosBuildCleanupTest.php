<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Native\Mobile\Commands\BuildIosAppCommand;
use ReflectionClass;
use Tests\TestCase;

class IosBuildCleanupTest extends TestCase
{
    protected string $testProjectPath;

    protected string $appPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_ios_cleanup_test_'.uniqid();
        File::makeDirectory($this->testProjectPath, 0755, true);

        // Simulate the appPath that would be used during iOS build
        $this->appPath = $this->testProjectPath.'/nativephp/ios/laravel/';
        File::makeDirectory($this->appPath, 0755, true);

        app()->setBasePath($this->testProjectPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);
        parent::tearDown();
    }

    public function test_removes_default_unnecessary_directories(): void
    {
        // Create directories that should be removed by default
        $defaultDirectories = [
            '.git',
            '.github',
            'node_modules',
            'vendor/bin',
            'tests',
            'storage/logs',
            'storage/framework',
            'vendor/laravel/pint/builds',
            'public/storage',
        ];

        foreach ($defaultDirectories as $dir) {
            File::makeDirectory($this->appPath.$dir, 0755, true);
            File::put($this->appPath.$dir.'/test.txt', 'test');
        }

        // Run the cleanup
        $this->invokeRemoveUnnecessaryFiles();

        // Assert all default directories were removed
        foreach ($defaultDirectories as $dir) {
            $this->assertDirectoryDoesNotExist(
                $this->appPath.$dir,
                "Directory '{$dir}' should have been removed"
            );
        }
    }

    public function test_removes_user_configured_directories_from_cleanup_exclude_files(): void
    {
        // Configure custom directories to exclude via config
        config(['nativephp.cleanup_exclude_files' => [
            'capacitor-app',
            'docs',
            'custom-build-folder',
        ]]);

        // Create these directories
        $customDirectories = [
            'capacitor-app',
            'docs',
            'custom-build-folder',
        ];

        foreach ($customDirectories as $dir) {
            File::makeDirectory($this->appPath.$dir, 0755, true);
            File::put($this->appPath.$dir.'/test.txt', 'test');
        }

        // Also create a directory that should NOT be removed
        File::makeDirectory($this->appPath.'app/Models', 0755, true);
        File::put($this->appPath.'app/Models/User.php', '<?php class User {}');

        // Run the cleanup
        $this->invokeRemoveUnnecessaryFiles();

        // Assert user-configured directories were removed
        foreach ($customDirectories as $dir) {
            $this->assertDirectoryDoesNotExist(
                $this->appPath.$dir,
                "User-configured directory '{$dir}' should have been removed"
            );
        }

        // Assert app directory was NOT removed (it's not in any exclusion list)
        $this->assertDirectoryExists($this->appPath.'app');
    }

    public function test_removes_both_default_and_user_configured_directories(): void
    {
        // Configure custom directories
        config(['nativephp.cleanup_exclude_files' => [
            'capacitor-app',
            'legacy-folder',
        ]]);

        // Create both default and custom directories
        $allDirectories = [
            // Default
            '.git',
            'node_modules',
            'tests',
            // Custom
            'capacitor-app',
            'legacy-folder',
        ];

        foreach ($allDirectories as $dir) {
            File::makeDirectory($this->appPath.$dir, 0755, true);
            File::put($this->appPath.$dir.'/test.txt', 'test');
        }

        // Run the cleanup
        $this->invokeRemoveUnnecessaryFiles();

        // Assert all directories were removed
        foreach ($allDirectories as $dir) {
            $this->assertDirectoryDoesNotExist(
                $this->appPath.$dir,
                "Directory '{$dir}' should have been removed"
            );
        }
    }

    public function test_handles_empty_cleanup_exclude_files_config(): void
    {
        // Set empty config
        config(['nativephp.cleanup_exclude_files' => []]);

        // Create a default directory that should still be removed
        File::makeDirectory($this->appPath.'node_modules', 0755, true);
        File::put($this->appPath.'node_modules/package.json', '{}');

        // Run the cleanup
        $this->invokeRemoveUnnecessaryFiles();

        // Assert default directory was still removed
        $this->assertDirectoryDoesNotExist($this->appPath.'node_modules');
    }

    public function test_handles_null_cleanup_exclude_files_config(): void
    {
        // Set null config (simulating missing config)
        config(['nativephp.cleanup_exclude_files' => null]);

        // Create a default directory that should still be removed
        File::makeDirectory($this->appPath.'.git', 0755, true);
        File::put($this->appPath.'.git/config', '[core]');

        // Run the cleanup
        $this->invokeRemoveUnnecessaryFiles();

        // Assert default directory was still removed
        $this->assertDirectoryDoesNotExist($this->appPath.'.git');
    }

    public function test_handles_nested_directory_paths_in_config(): void
    {
        // Configure nested directory paths
        config(['nativephp.cleanup_exclude_files' => [
            'storage/framework/sessions',
            'storage/framework/cache',
        ]]);

        // Create nested directories
        File::makeDirectory($this->appPath.'storage/framework/sessions', 0755, true);
        File::makeDirectory($this->appPath.'storage/framework/cache', 0755, true);
        File::put($this->appPath.'storage/framework/sessions/session1', 'data');
        File::put($this->appPath.'storage/framework/cache/cache1', 'data');

        // Run the cleanup
        $this->invokeRemoveUnnecessaryFiles();

        // Assert nested directories were removed
        $this->assertDirectoryDoesNotExist($this->appPath.'storage/framework/sessions');
        $this->assertDirectoryDoesNotExist($this->appPath.'storage/framework/cache');
    }

    public function test_skips_non_existent_directories_without_error(): void
    {
        // Configure directories that don't exist
        config(['nativephp.cleanup_exclude_files' => [
            'non-existent-folder',
            'another-missing-dir',
        ]]);

        // Don't create these directories

        // Run the cleanup - should not throw an error
        $this->invokeRemoveUnnecessaryFiles();

        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    /**
     * Helper method to invoke the private removeUnnecessaryFiles method
     */
    protected function invokeRemoveUnnecessaryFiles(): void
    {
        $command = $this->app->make(BuildIosAppCommand::class);

        // Use reflection to set the appPath property
        $reflection = new ReflectionClass($command);

        $appPathProperty = $reflection->getProperty('appPath');
        $appPathProperty->setAccessible(true);
        $appPathProperty->setValue($command, $this->appPath);

        // Invoke the private method
        $method = $reflection->getMethod('removeUnnecessaryFiles');
        $method->setAccessible(true);
        $method->invoke($command);
    }
}
