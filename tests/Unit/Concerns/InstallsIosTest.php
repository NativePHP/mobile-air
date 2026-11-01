<?php

namespace Tests\Unit\Concerns;

use Illuminate\Support\Facades\File;
use Native\Mobile\Concerns\InstallsIos;
use Orchestra\Testbench\TestCase;
use SupaNative\Core\Support\CaBundle;

class InstallsIosTest extends TestCase
{
    use InstallsIos;

    protected string $testProjectPath;

    protected object $components;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_install_ios_test_'.uniqid();
        File::makeDirectory($this->testProjectPath, 0755, true);

        // Set up base path for testing
        app()->setBasePath($this->testProjectPath);

        $this->iosPath = $this->testProjectPath.'/nativephp/ios';

        // Mock $this->components for task() calls
        $this->components = new class
        {
            public function task(string $title, callable $callback)
            {
                return $callback();
            }
        };
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);

        parent::tearDown();
    }

    public function test_create_xcode_project_copies_boilerplate_and_the_ca_bundle_from_core()
    {
        // Create mock vendor directory with no CA bundle of its own
        $vendorPath = $this->testProjectPath.'/vendor/nativephp/mobile/resources/xcode';
        File::makeDirectory($vendorPath.'/NativePHP', 0755, true);
        File::put($vendorPath.'/NativePHP/AppDelegate.swift', 'app delegate');

        // Execute
        $this->createXcodeProject();

        // Assert core's file sits beside the sources Xcode bundles
        $this->assertEquals('app delegate', File::get($this->iosPath.'/NativePHP/AppDelegate.swift'));
        $this->assertFileEquals(CaBundle::path(), $this->iosPath.'/NativePHP/cacert.pem');
    }

    public function test_create_xcode_project_replaces_an_older_ca_bundle()
    {
        // Create existing ios directory with its own copy
        File::makeDirectory($this->iosPath.'/NativePHP', 0755, true);
        File::put($this->iosPath.'/NativePHP/cacert.pem', 'older bundle');

        // Create mock vendor directory
        $vendorPath = $this->testProjectPath.'/vendor/nativephp/mobile/resources/xcode';
        File::makeDirectory($vendorPath.'/NativePHP', 0755, true);
        File::put($vendorPath.'/NativePHP/AppDelegate.swift', 'app delegate');

        // Execute
        $this->createXcodeProject();

        // Assert core's file replaced the older copy
        $this->assertFileEquals(CaBundle::path(), $this->iosPath.'/NativePHP/cacert.pem');
    }
}
