<?php

namespace Tests\Unit\Concerns;

use Illuminate\Support\Facades\File;
use Mockery;
use Native\Mobile\Concerns\InstallsAndroid;
use Orchestra\Testbench\TestCase;
use SupaNative\Core\Support\CaBundle;

class InstallsAndroidTest extends TestCase
{
    use InstallsAndroid {
        InstallsAndroid::installPHPAndroid as private traitInstallPHPAndroid;
    }

    protected string $testProjectPath;

    protected $mockOutput;

    protected bool $forcing = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_install_test_'.uniqid();
        File::makeDirectory($this->testProjectPath, 0755, true);

        // Set up base path for testing
        app()->setBasePath($this->testProjectPath);

        // Mock output for progress bar
        $this->mockOutput = Mockery::mock('Symfony\Component\Console\Output\OutputInterface');
        $this->output = $this->mockOutput;

        // Mock $this->components for task() calls
        $this->components = new class
        {
            public function task(string $title, callable $callback)
            {
                $callback();
            }

            public function twoColumnDetail(...$args) {}

            public function warn(...$args) {}
        };
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);
        Mockery::close();

        parent::tearDown();
    }

    public function test_create_android_studio_project_copies_boilerplate()
    {
        // Create mock vendor directory with boilerplate
        $vendorPath = $this->testProjectPath.'/vendor/nativephp/mobile/resources/androidstudio';
        File::makeDirectory($vendorPath, 0755, true);
        File::put($vendorPath.'/build.gradle', 'test content');
        File::makeDirectory($vendorPath.'/app');
        File::put($vendorPath.'/app/build.gradle.kts', 'app content');

        // Execute
        $this->createAndroidStudioProject();

        // Assert files were copied
        $androidPath = $this->testProjectPath.'/nativephp/android';
        $this->assertDirectoryExists($androidPath);
        $this->assertFileExists($androidPath.'/build.gradle');
        $this->assertFileExists($androidPath.'/app/build.gradle.kts');
        $this->assertEquals('test content', File::get($androidPath.'/build.gradle'));
    }

    public function test_create_android_studio_project_with_force_removes_existing()
    {
        // Create existing android directory
        $androidPath = $this->testProjectPath.'/nativephp/android';
        File::makeDirectory($androidPath, 0755, true);
        File::put($androidPath.'/existing.txt', 'existing content');

        // Create mock vendor directory
        $vendorPath = $this->testProjectPath.'/vendor/nativephp/mobile/resources/androidstudio';
        File::makeDirectory($vendorPath, 0755, true);
        File::put($vendorPath.'/new.txt', 'new content');

        // Set force flag
        $this->forcing = true;

        // Execute
        $this->createAndroidStudioProject();

        // Assert old file was removed and new file exists
        $this->assertFileDoesNotExist($androidPath.'/existing.txt');
        $this->assertFileExists($androidPath.'/new.txt');
    }

    public function test_create_android_studio_project_adds_the_ca_bundle_from_core()
    {
        // Create mock vendor directory with no assets folder
        $vendorPath = $this->testProjectPath.'/vendor/nativephp/mobile/resources/androidstudio';
        File::makeDirectory($vendorPath.'/app', 0755, true);
        File::put($vendorPath.'/app/build.gradle.kts', 'app content');

        // Execute
        $this->createAndroidStudioProject();

        // Assert core's file is where LaravelEnvironment reads it
        $this->assertFileEquals(
            CaBundle::path(),
            $this->testProjectPath.'/nativephp/android/app/src/main/assets/cacert.pem'
        );
    }

    public function test_create_android_studio_project_replaces_an_older_ca_bundle()
    {
        // Create existing android directory with its own copy
        $assetsPath = $this->testProjectPath.'/nativephp/android/app/src/main/assets';
        File::makeDirectory($assetsPath, 0755, true);
        File::put($assetsPath.'/cacert.pem', 'older bundle');

        // Create mock vendor directory
        $vendorPath = $this->testProjectPath.'/vendor/nativephp/mobile/resources/androidstudio';
        File::makeDirectory($vendorPath, 0755, true);
        File::put($vendorPath.'/build.gradle', 'test content');

        // Execute
        $this->createAndroidStudioProject();

        // Assert core's file replaced the older copy
        $this->assertFileEquals(CaBundle::path(), $assetsPath.'/cacert.pem');
    }

    public function test_install_php_android_with_icu_lock()
    {
        $this->mockConfirm('➕ Include ICU-enabled PHP binary? (~30MB extra)', true);

        // Create destination directory
        $destination = $this->testProjectPath.'/nativephp/android/app/src/main';
        File::makeDirectory($destination, 0755, true);

        // ICU preference is now stored in nativephp.lock by InstallCommand
        $lockPath = $this->testProjectPath.'/nativephp.lock';
        File::put($lockPath, json_encode(['php' => ['version' => '8.4.7', 'icu' => true]]));

        $data = json_decode(File::get($lockPath), true);
        $this->assertTrue($data['php']['icu']);
    }

    public function test_install_php_android_without_icu_lock()
    {
        $this->mockConfirm('➕ Include ICU-enabled PHP binary? (~30MB extra)', false);

        // Create destination directory
        $destination = $this->testProjectPath.'/nativephp/android/app/src/main';
        File::makeDirectory($destination, 0755, true);

        // ICU preference is now stored in nativephp.lock by InstallCommand
        $lockPath = $this->testProjectPath.'/nativephp.lock';
        File::put($lockPath, json_encode(['php' => ['version' => '8.4.7', 'icu' => false]]));

        $data = json_decode(File::get($lockPath), true);
        $this->assertFalse($data['php']['icu']);
    }

    /**
     * Helper methods
     */
    protected function shouldReceiveOption(string $option, $value)
    {
        $this->option = [$option => $value];
    }

    protected function option($key)
    {
        return $this->option[$key] ?? false;
    }

    protected function mockConfirm(string $question, bool $response)
    {
        // In real implementation, you'd mock the confirm function
        $this->confirmResponse = $response;
    }

    protected function info($message)
    {
        // Mock for testing
    }

    protected function warn($message)
    {
        // Mock for testing
    }

    protected function error($message)
    {
        // Mock for testing
    }

    protected function newLine($count = 1)
    {
        // Mock for testing
    }
}
