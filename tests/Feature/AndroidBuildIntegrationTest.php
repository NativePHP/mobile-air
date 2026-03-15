<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AndroidBuildIntegrationTest extends TestCase
{
    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_integration_test_'.uniqid();
        File::makeDirectory($this->testProjectPath, 0755, true);

        // Create storage directory that Laravel expects
        File::makeDirectory($this->testProjectPath.'/storage', 0755, true);

        // Set up base path for testing
        app()->setBasePath($this->testProjectPath);

        // Create vendor directory structure
        $this->createVendorStructure();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);
        parent::tearDown();
    }

    public function test_full_android_build_flow()
    {
        // Skip this test on macOS due to Laravel Prompts compatibility issues with expectsChoice
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->markTestSkipped('Android integration test skipped on macOS due to prompt testing limitations');
        }

        // 1. Install Android project (on non-Darwin platforms)
        $this->artisan('native:install', ['--force' => true])
            ->expectsConfirmation('➕ Include ICU-enabled PHP binary? (~30MB extra)', 'yes')
            ->assertSuccessful();

        // Assert initial structure was created
        $this->assertDirectoryExists($this->testProjectPath.'/nativephp/android');
        $this->assertFileExists($this->testProjectPath.'/nativephp/android/.icu-enabled');

        // 2. Change configuration
        config(['nativephp.app_id' => 'com.mycompany.newapp']);
        config(['nativephp.version' => '2.0.0']);
        config(['nativephp.permissions.push_notifications' => true]);
        config(['nativephp.permissions.nfc' => true]);
        config(['nativephp.deeplink_scheme' => 'myapp']);
        config(['nativephp.deeplink_host' => 'app.example.com']);

        // 3. Run the app (which should apply all configurations)
        // Note: We'll mock the actual build process
        $this->mockRunCommand();

        // Assert configurations were applied
        $this->assertAppIdWasUpdated();
        $this->assertVersionWasUpdated();
        $this->assertPermissionsWereUpdated();
        $this->assertDeepLinksWereConfigured();
    }

    public function test_gradle_cache_is_cleaned_on_run()
    {
        // Create gradle cache directories
        $gradleDir = $this->testProjectPath.'/nativephp/android/.gradle';
        $buildDir = $this->testProjectPath.'/nativephp/android/app/build';

        File::makeDirectory($gradleDir, 0755, true);
        File::makeDirectory($buildDir, 0755, true);
        File::put($gradleDir.'/cache.lock', 'test');
        File::put($buildDir.'/output.apk', 'test');

        // Run command (mocked)
        $this->mockRunCommand();

        // Assert cache was cleaned
        $this->assertDirectoryDoesNotExist($gradleDir);
        $this->assertDirectoryDoesNotExist($buildDir);
    }

    public function test_configuration_changes_without_reinstall()
    {
        // Initial install
        $this->createInitialAndroidProject();

        // Change app ID
        config(['nativephp.app_id' => 'com.changed.appid']);

        // Run without reinstalling
        $this->mockRunCommand();

        // Assert app ID was changed (only applicationId, not namespace)
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        $contents = File::get($gradlePath);
        // Namespace stays fixed
        $this->assertStringContainsString('namespace = "com.nativephp.mobile"', $contents);
        // ApplicationId gets updated
        $this->assertStringContainsString('applicationId = "com.changed.appid"', $contents);
    }

    /**
     * Helper methods
     */
    protected function createVendorStructure(): void
    {
        $structure = [
            'vendor/nativephp/mobile/resources/androidstudio' => [
                'build.gradle.kts' => 'plugins { id("com.android.application") }',
                'settings.gradle.kts' => 'rootProject.name = "NativePHP"',
                'app' => [
                    'build.gradle.kts' => 'android {
    namespace = "com.nativephp.mobile"
    applicationId = "REPLACE_APP_ID"
    versionCode = REPLACEMECODE
    versionName = "REPLACEME"
}',
                    'src/main' => [
                        'AndroidManifest.xml' => '<manifest>
    <application android:label="NativePHP">
        <activity android:name=".MainActivity">
        </activity>
    </application>
</manifest>',
                        'java/com/nativephp/mobile' => [
                            'ui/MainActivity.kt' => 'package com.nativephp.mobile.ui',
                            'bridge/PHPBridge.kt' => 'class PHPBridge { init { System.loadLibrary("php") } }',
                            'network/WebViewManager.kt' => 'if (url.startsWith("REPLACEME://")) {',
                        ],
                        'cpp' => [
                            'CMakeLists.txt' => 'project("com_nativephp_mobile")',
                            'php_bridge.c' => 'Java_com_nativephp_mobile_bridge',
                        ],
                    ],
                ],
            ],
            'vendor/nativephp/mobile/bootstrap/android' => [
                'artisan.php' => '<?php // artisan',
            ],
        ];

        $this->createDirectoryStructure($this->testProjectPath, $structure);
    }

    protected function createInitialAndroidProject(): void
    {
        // Simulate initial install
        $source = $this->testProjectPath.'/vendor/nativephp/mobile/resources/androidstudio';
        $dest = $this->testProjectPath.'/nativephp/android';

        File::copyDirectory($source, $dest);

        // Create ICU flag
        File::put($dest.'/.icu-enabled', '1');
    }

    protected function mockRunCommand(): void
    {
        // In a real test, you'd use the actual RunCommand
        // For now, we'll simulate its behavior
        $runner = new class
        {
            use \Native\Mobile\Traits\RunsAndroid;

            public $components;

            public function __construct()
            {
                $this->buildType = 'debug';

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

            public function runMocked()
            {
                $this->cleanGradleCache();
                $this->updateAndroidConfiguration();
            }

            protected function logToFile(string $message): void {}

            protected function info($message) {}

            protected function warn($message) {}

            protected function error($message) {}

            protected function installAndroidIcon() {}

            protected function prepareLaravelBundle() {}

            protected function runTheAndroidBuild($target) {}

            protected function removeDirectory(string $path): void
            {
                if (is_dir($path)) {
                    \Illuminate\Support\Facades\File::deleteDirectory($path);
                }
            }

            protected function platformOptimizedCopy(string $source, string $destination, array $excludedDirs = []): void {}
        };

        $runner->runMocked();
    }

    protected function assertAppIdWasUpdated(): void
    {
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        $contents = File::get($gradlePath);
        // ApplicationId should be updated
        $this->assertStringContainsString('applicationId = "com.mycompany.newapp"', $contents);
        // Namespace should stay fixed
        $this->assertStringContainsString('namespace = "com.nativephp.mobile"', $contents);

        // Source files should NOT be moved - they stay in com/nativephp/mobile
        $this->assertDirectoryExists($this->testProjectPath.'/nativephp/android/app/src/main/java/com/nativephp/mobile');
    }

    protected function assertVersionWasUpdated(): void
    {
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        $contents = File::get($gradlePath);
        $this->assertStringContainsString('versionName = "2.0.0"', $contents);
    }

    protected function assertPermissionsWereUpdated(): void
    {
        $manifestPath = $this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml';
        $contents = File::get($manifestPath);
        $this->assertStringContainsString('android.permission.POST_NOTIFICATIONS', $contents);
        $this->assertStringContainsString('android.permission.NFC', $contents);
    }

    protected function assertDeepLinksWereConfigured(): void
    {
        $manifestPath = $this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml';
        $contents = File::get($manifestPath);
        $this->assertStringContainsString('android:scheme="myapp"', $contents);
        $this->assertStringContainsString('android:host="app.example.com"', $contents);
    }
}
