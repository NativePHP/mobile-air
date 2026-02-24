<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Native\Mobile\Commands\BuildIosAppCommand;
use ReflectionClass;
use Tests\TestCase;

class IosBuildCopyTest extends TestCase
{
    protected string $testProjectPath;

    protected string $appPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_ios_copy_test_'.uniqid();
        File::makeDirectory($this->testProjectPath, 0755, true);

        $this->appPath = $this->testProjectPath.'/nativephp/ios/laravel/';
        File::makeDirectory($this->appPath, 0755, true);

        app()->setBasePath($this->testProjectPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);
        parent::tearDown();
    }

    public function test_copy_respects_cleanup_exclude_files_config(): void
    {
        config(['nativephp.cleanup_exclude_files' => ['custom-excluded/']]);

        $source = $this->testProjectPath.'/source/';
        File::makeDirectory($source.'custom-excluded', 0755, true);
        File::put($source.'custom-excluded/file.txt', 'should not be copied');
        File::makeDirectory($source.'app/Models', 0755, true);
        File::put($source.'app/Models/User.php', '<?php class User {}');
        File::makeDirectory($source.'vendor', 0755, true);
        File::put($source.'vendor/autoload.php', '<?php');

        app()->setBasePath($source);

        $command = $this->app->make(BuildIosAppCommand::class);
        $reflection = new ReflectionClass($command);

        $appPathProp = $reflection->getProperty('appPath');
        $appPathProp->setValue($command, $this->appPath);

        $method = $reflection->getMethod('copyLaravelAppIntoIosApp');
        $method->invoke($command);

        $this->assertDirectoryDoesNotExist(
            $this->appPath.'custom-excluded',
            'User-configured excluded directory should not be copied'
        );

        $this->assertDirectoryExists(
            $this->appPath.'app/Models',
            'Non-excluded directory should be copied'
        );

        $this->assertDirectoryDoesNotExist(
            $this->appPath.'vendor',
            'Default excluded directory should not be copied'
        );
    }

    public function test_copy_handles_empty_cleanup_exclude_files_config(): void
    {
        config(['nativephp.cleanup_exclude_files' => []]);

        $source = $this->testProjectPath.'/source/';
        File::makeDirectory($source.'node_modules', 0755, true);
        File::put($source.'node_modules/package.json', '{}');
        File::makeDirectory($source.'app', 0755, true);
        File::put($source.'app/test.php', '<?php');

        app()->setBasePath($source);

        $command = $this->app->make(BuildIosAppCommand::class);
        $reflection = new ReflectionClass($command);

        $appPathProp = $reflection->getProperty('appPath');
        $appPathProp->setValue($command, $this->appPath);

        $method = $reflection->getMethod('copyLaravelAppIntoIosApp');
        $method->invoke($command);

        $this->assertDirectoryDoesNotExist($this->appPath.'node_modules');
        $this->assertDirectoryExists($this->appPath.'app');
    }

    public function test_copy_handles_null_cleanup_exclude_files_config(): void
    {
        config(['nativephp.cleanup_exclude_files' => null]);

        $source = $this->testProjectPath.'/source/';
        File::makeDirectory($source.'.git', 0755, true);
        File::put($source.'.git/config', '[core]');
        File::makeDirectory($source.'storage', 0755, true);
        File::put($source.'storage/test.txt', 'test');

        app()->setBasePath($source);

        $command = $this->app->make(BuildIosAppCommand::class);
        $reflection = new ReflectionClass($command);

        $appPathProp = $reflection->getProperty('appPath');
        $appPathProp->setValue($command, $this->appPath);

        $method = $reflection->getMethod('copyLaravelAppIntoIosApp');
        $method->invoke($command);

        $this->assertDirectoryDoesNotExist($this->appPath.'.git');
        $this->assertDirectoryExists($this->appPath.'storage');
    }

    public function test_copy_merges_user_config_with_defaults(): void
    {
        config(['nativephp.cleanup_exclude_files' => [
            'custom-dir/',
            'another-custom/',
        ]]);

        $source = $this->testProjectPath.'/source/';
        File::makeDirectory($source.'custom-dir', 0755, true);
        File::makeDirectory($source.'another-custom', 0755, true);
        File::makeDirectory($source.'vendor', 0755, true);
        File::makeDirectory($source.'node_modules', 0755, true);
        File::makeDirectory($source.'app', 0755, true);

        app()->setBasePath($source);

        $command = $this->app->make(BuildIosAppCommand::class);
        $reflection = new ReflectionClass($command);

        $appPathProp = $reflection->getProperty('appPath');
        $appPathProp->setValue($command, $this->appPath);

        $method = $reflection->getMethod('copyLaravelAppIntoIosApp');
        $method->invoke($command);

        $this->assertDirectoryDoesNotExist($this->appPath.'custom-dir');
        $this->assertDirectoryDoesNotExist($this->appPath.'another-custom');
        $this->assertDirectoryDoesNotExist($this->appPath.'vendor');
        $this->assertDirectoryDoesNotExist($this->appPath.'node_modules');
        $this->assertDirectoryExists($this->appPath.'app');
    }
}
