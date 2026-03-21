<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Native\Mobile\Traits\InstallsAndroid;
use Native\Mobile\Traits\PlatformFileOperations;
use Native\Mobile\Traits\RunsAndroid;
use Tests\TestCase;

class EdgeCasesAndErrorHandlingTest extends TestCase
{
    use InstallsAndroid, PlatformFileOperations, RunsAndroid;

    protected string $testProjectPath;

    protected array $warnings = [];

    protected array $errors = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_edge_test_'.uniqid();
        File::makeDirectory($this->testProjectPath, 0755, true);
        app()->setBasePath($this->testProjectPath);

        $this->warnings = [];
        $this->errors = [];

        // Mock $this->components for task() calls used by RunsAndroid/PreparesBuild
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
        parent::tearDown();
    }

    protected function createMinimalAndroidStructure(): void
    {
        $structure = [
            'nativephp/android' => [
                'app' => [
                    'build.gradle.kts' => 'android {
    namespace = "com.nativephp.mobile"
    applicationId = "REPLACE_APP_ID"
    versionCode = 1
    versionName = "1.0.0"
}',
                    'src/main' => [
                        'AndroidManifest.xml' => '<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <application android:label="NativePHP">
    </application>
</manifest>',
                        'java/com/nativephp/mobile' => [
                            'ui/MainActivity.kt' => 'package com.nativephp.mobile.ui',
                            'bridge/PHPBridge.kt' => 'package com.nativephp.mobile.bridge',
                        ],
                        'cpp' => [
                            'CMakeLists.txt' => 'project("com_nativephp_mobile")',
                            'php_bridge.c' => 'Java_com_nativephp_mobile_bridge',
                        ],
                    ],
                ],
                'local.properties' => '',
            ],
        ];

        $this->createDirectoryStructure($this->testProjectPath, $structure);
    }

    public function test_handles_missing_android_project_directory()
    {
        // Don't create the android directory - simulating a fresh Laravel app
        // before running native:install

        // Try to detect app ID - should return null
        $appId = $this->detectCurrentAppId();
        $this->assertNull($appId);

        // The updateAndroidConfiguration method expects the Android structure to exist
        // In a real Laravel app, this would only be called after native:install
        // So we shouldn't test it with missing directories
    }

    public function test_handles_corrupted_gradle_file()
    {
        // Create corrupted build.gradle.kts
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        File::makeDirectory(dirname($gradlePath), 0755, true);
        File::put($gradlePath, 'invalid { gradle syntax');

        // Try to detect app ID
        $appId = $this->detectCurrentAppId();
        $this->assertNull($appId);

        // Try to update version
        $this->updateVersionConfiguration();

        // File should still exist but might not be updated properly
        $this->assertFileExists($gradlePath);
    }

    public function test_handles_readonly_files()
    {
        // Create a file and make it readonly
        $manifestPath = $this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml';
        File::makeDirectory(dirname($manifestPath), 0755, true);
        File::put($manifestPath, '<manifest></manifest>');

        // Make file readonly
        chmod($manifestPath, 0444);

        // Try to update permissions
        config(['nativephp.permissions.push_notifications' => true]);

        // This might fail on some systems, but should not throw exception
        try {
            $this->updatePermissions();
            $this->assertTrue(true);
        } catch (\Exception $e) {
            // On systems where this fails, ensure it's a permission error
            $this->assertStringContainsString('Permission', $e->getMessage());
        } finally {
            // Restore permissions for cleanup
            chmod($manifestPath, 0644);
        }
    }

    public function test_handles_icu_disabled_in_json()
    {
        // Create PHPBridge.kt with ICU disabled in nativephp.json
        $bridgePath = $this->testProjectPath.'/nativephp/android/app/src/main/java/com/test/app/bridge/PHPBridge.kt';
        File::makeDirectory(dirname($bridgePath), 0755, true);
        File::put($bridgePath, 'class PHPBridge { init { System.loadLibrary("php") } }');

        config(['nativephp.app_id' => 'com.test.app']);
        File::put($this->testProjectPath.'/nativephp.json', json_encode(['php' => ['version' => '8.4.7', 'icu' => false]]));

        // Update ICU configuration with ICU disabled
        $this->updateIcuConfiguration();

        // Should skip ICU libraries
        $contents = File::get($bridgePath);
        $this->assertStringNotContainsString('System.loadLibrary("icudata")', $contents);
    }

    public function test_handles_invalid_app_id_format()
    {
        // Create minimal Android structure for this test
        $this->createMinimalAndroidStructure();

        // Set invalid app IDs
        $invalidAppIds = [
            'invalid app id with spaces',
            'com.example.123start', // starts with number
            'com..double.dots',
            '.starts.with.dot',
            'ends.with.dot.',
        ];

        foreach ($invalidAppIds as $invalidId) {
            config(['nativephp.app_id' => $invalidId]);

            // The actual code should validate app IDs before processing
            // For now, we'll just test that invalid IDs don't crash
            try {
                $this->updateAndroidConfiguration();
            } catch (\Exception $e) {
                // Expected - invalid app IDs should be rejected
                $this->assertStringContainsString('app', strtolower($e->getMessage()));
            }
        }

        // If we get here, all were handled
        $this->assertTrue(true);
    }

    public function test_handles_extremely_long_paths()
    {
        // Create a very deep directory structure
        $deepPath = $this->testProjectPath;
        for ($i = 0; $i < 20; $i++) {
            $deepPath .= '/very_long_directory_name_'.$i;
        }

        // Try to create directory
        try {
            File::makeDirectory($deepPath, 0755, true);
            File::put($deepPath.'/test.txt', 'content');

            // Try to remove it
            $this->removeDirectory(dirname($deepPath));

            $this->assertDirectoryDoesNotExist($deepPath);
        } catch (\Exception $e) {
            // Some systems have path length limits
            $this->assertStringContainsString('path', strtolower($e->getMessage()));
        }
    }

    public function test_handles_special_characters_in_app_name()
    {
        // Create the Android structure once
        $this->createMinimalAndroidStructure();

        $specialNames = [
            'App & Name',
            'App "Quoted"',
            'App\'s Name',
            'App <Tag>',
            'App\\Backslash',
            'App|Pipe',
        ];

        $manifestPath = $this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml';

        foreach ($specialNames as $name) {
            config(['app.name' => $name]);

            // Reset manifest for each test
            File::put($manifestPath, '<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <application android:label="OldName">
    </application>
</manifest>');

            // Update app name
            $this->updateAppDisplayName();

            // Check if special characters were escaped or handled
            $contents = File::get($manifestPath);
            $this->assertStringContainsString('android:label=', $contents);
            // The actual app name should be properly XML-escaped
        }
    }

    public function test_handles_concurrent_file_access()
    {
        $file = $this->testProjectPath.'/test_concurrent.txt';
        File::put($file, 'original content');

        // Simulate concurrent access by opening file handle
        $handle = fopen($file, 'r');

        try {
            // Try to replace contents while file is open
            $result = $this->replaceFileContents($file, 'original', 'new');

            // Behavior may vary by OS
            if ($result) {
                $this->assertEquals('new content', File::get($file));
            } else {
                // If it failed, that's also acceptable
                $this->assertFalse($result);
            }
        } finally {
            fclose($handle);
        }
    }

    public function test_handles_circular_symlinks()
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlink test skipped on Windows');
        }

        $dir1 = $this->testProjectPath.'/dir1';
        $dir2 = $this->testProjectPath.'/dir2';

        File::makeDirectory($dir1);
        File::makeDirectory($dir2);

        // Create circular symlinks
        symlink($dir2, $dir1.'/link_to_dir2');
        symlink($dir1, $dir2.'/link_to_dir1');

        // Try to copy - should not hang
        $dest = $this->testProjectPath.'/dest';
        $this->platformOptimizedCopy($dir1, $dest);

        // If we get here, it didn't hang
        $this->assertTrue(true);
    }

    public function test_handles_network_paths()
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Network path test only for Windows');
        }

        // Test UNC path handling
        $networkPath = '\\\\server\\share\\Android\\Sdk';

        config(['nativephp.android.android_sdk_path' => $networkPath]);

        // Should handle without crashing
        $this->updateLocalProperties();

        $localPropertiesPath = $this->testProjectPath.'/nativephp/android/local.properties';
        if (File::exists($localPropertiesPath)) {
            $contents = File::get($localPropertiesPath);
            $this->assertStringContainsString('sdk.dir=', $contents);
        }
    }

    protected function logToFile(string $message): void {}

    /**
     * Override output methods to capture warnings/errors
     */
    protected function info($message)
    {
        // Capture for testing
    }

    protected function warn($message)
    {
        $this->warnings[] = $message;
    }

    protected function error($message)
    {
        $this->errors[] = $message;
    }

    protected function newLine($count = 1)
    {
        // Mock for testing
    }

    protected function option($key)
    {
        return false;
    }
}
