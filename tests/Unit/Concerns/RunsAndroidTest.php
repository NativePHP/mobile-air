<?php

namespace Tests\Unit\Concerns;

use Illuminate\Support\Facades\File;
use Mockery;
use Native\Mobile\Concerns\RunsAndroid;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use Orchestra\Testbench\TestCase;

class RunsAndroidTest extends TestCase
{
    use RunsAndroid;

    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_android_test_'.uniqid();
        File::makeDirectory($this->testProjectPath.'/nativephp/android', 0755, true);

        // Set up base path for testing
        app()->setBasePath($this->testProjectPath);

        // Mock $this->components for task() calls used by PreparesBuild
        $this->components = new class
        {
            public array $errors = [];

            public function task(string $title, callable $callback)
            {
                $callback();
            }

            public function twoColumnDetail(...$args) {}

            public function warn(...$args) {}

            public function error(string $message): void
            {
                $this->errors[] = $message;
            }
        };

        $this->androidLogPath = $this->testProjectPath.'/nativephp/android-build.log';
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);
        Mockery::close();

        parent::tearDown();
    }

    public function test_clean_gradle_cache_removes_directories()
    {
        // Create test directories
        $gradleDir = $this->testProjectPath.'/nativephp/android/.gradle';
        $buildDir = $this->testProjectPath.'/nativephp/android/app/build';

        File::makeDirectory($gradleDir, 0755, true);
        File::makeDirectory($buildDir, 0755, true);
        File::put($gradleDir.'/cache.lock', 'test');
        File::put($buildDir.'/output.apk', 'test');

        $this->assertDirectoryExists($gradleDir);
        $this->assertDirectoryExists($buildDir);

        // Execute
        $this->cleanGradleCache();

        // Assert directories were removed
        $this->assertDirectoryDoesNotExist($gradleDir);
        $this->assertDirectoryDoesNotExist($buildDir);
    }

    public function test_detect_current_app_id_from_gradle()
    {
        // Create test build.gradle.kts - real code matches applicationId, not namespace
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        File::makeDirectory(dirname($gradlePath), 0755, true);
        File::put($gradlePath, 'android {
    namespace = "com.nativephp.mobile"
    defaultConfig {
        applicationId = "com.example.testapp"
    }
    compileSdk = 34
}');

        $appId = $this->detectCurrentAppId();

        $this->assertEquals('com.example.testapp', $appId);
    }

    public function test_detect_current_app_id_returns_null_for_missing_file()
    {
        $appId = $this->detectCurrentAppId();

        $this->assertNull($appId);
    }

    public function test_update_app_id_only_updates_application_id_in_gradle()
    {
        $oldAppId = 'com.example.oldapp';
        $newAppId = 'com.company.newapp';

        // Set up build.gradle.kts with applicationId
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        File::makeDirectory(dirname($gradlePath), 0755, true);
        File::put($gradlePath, 'android {
    namespace = "com.nativephp.mobile"
    defaultConfig {
        applicationId = "com.example.oldapp"
    }
}');

        // Execute
        $this->updateAppId($oldAppId, $newAppId);

        // Assert only applicationId was updated, namespace stays fixed
        $contents = File::get($gradlePath);
        $this->assertStringContainsString('applicationId = "com.company.newapp"', $contents);
        $this->assertStringContainsString('namespace = "com.nativephp.mobile"', $contents);
    }

    public function test_update_version_configuration()
    {
        // Set up config
        config(['nativephp.version' => '2.0.0']);
        config(['nativephp.version_code' => 2000]);

        // Create test build.gradle.kts
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        File::makeDirectory(dirname($gradlePath), 0755, true);
        File::put($gradlePath, 'android {
    versionCode = 1
    versionName = "1.0.0"
}');

        // Execute
        $this->updateVersionConfiguration();

        $contents = File::get($gradlePath);
        $this->assertStringContainsString('versionCode = 2000', $contents);
        $this->assertStringContainsString('versionName = "2.0.0"', $contents);
    }

    public function test_update_app_display_name()
    {
        // Set up config
        config(['app.name' => 'My Test App']);

        // Create AndroidManifest.xml
        $manifestPath = $this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml';
        File::makeDirectory(dirname($manifestPath), 0755, true);
        File::put($manifestPath, '<application android:label="NativePHP">');

        // Execute
        $this->updateAppDisplayName();

        $contents = File::get($manifestPath);
        $this->assertStringContainsString('android:label="My Test App"', $contents);
    }

    public function test_update_permissions_adds_and_removes()
    {
        // Set up config - only push_notifications is a core permission
        config(['nativephp.permissions.push_notifications' => true]);

        // Create AndroidManifest.xml
        $manifestPath = $this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml';
        File::makeDirectory(dirname($manifestPath), 0755, true);
        File::put($manifestPath, '<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <application android:label="NativePHP">
    </application>
</manifest>');

        // Execute
        $this->updatePermissions();

        $contents = File::get($manifestPath);

        // Assert push notifications permission was added
        $this->assertStringContainsString('android.permission.POST_NOTIFICATIONS', $contents);

        // Now disable it
        config(['nativephp.permissions.push_notifications' => false]);
        $this->updatePermissions();

        $contents = File::get($manifestPath);
        $this->assertStringNotContainsString('android.permission.POST_NOTIFICATIONS', $contents);
    }

    public function test_update_firebase_configuration_copies_file()
    {
        // Create source google-services.json
        $sourcePath = $this->testProjectPath.'/google-services.json';
        File::put($sourcePath, '{"project_id": "test"}');

        // Create target directory
        $targetDir = $this->testProjectPath.'/nativephp/android/app';
        File::makeDirectory($targetDir, 0755, true);

        // Execute
        $this->updateFirebaseConfiguration();

        // Assert file was copied
        $targetPath = $targetDir.'/google-services.json';
        $this->assertFileExists($targetPath);
        $this->assertEquals('{"project_id": "test"}', File::get($targetPath));
    }

    public function test_google_services_configuration_requires_a_declaring_plugin()
    {
        File::put($this->testProjectPath.'/google-services.json', '{"project_id": "test"}');

        $this->assertFalse($this->validateGoogleServicesConfiguration(collect()));
        $this->assertCount(1, $this->components->errors);
        $this->assertStringContainsString('nativephp/mobile-firebase', $this->components->errors[0]);
    }

    public function test_registered_plugin_can_own_google_services_configuration()
    {
        File::put($this->testProjectPath.'/google-services.json', '{"project_id": "test"}');

        $plugin = new Plugin(
            name: 'nativephp/mobile-firebase',
            version: '1.1.1',
            path: $this->testProjectPath.'/vendor/nativephp/mobile-firebase',
            manifest: new PluginManifest([
                'namespace' => 'Firebase',
                'android' => [
                    'gradle_plugins' => [
                        ['id' => 'com.google.gms.google-services', 'version' => '4.4.3'],
                    ],
                ],
            ]),
        );

        $this->assertTrue($this->validateGoogleServicesConfiguration(collect([$plugin])));
        $this->assertSame([], $this->components->errors);
    }

    public function test_plugin_name_alone_does_not_claim_google_services_configuration()
    {
        File::put($this->testProjectPath.'/google-services.json', '{"project_id": "test"}');

        $plugin = new Plugin(
            name: 'nativephp/mobile-firebase',
            version: '1.1.0',
            path: $this->testProjectPath.'/vendor/nativephp/mobile-firebase',
            manifest: new PluginManifest([
                'namespace' => 'Firebase',
                'android' => [],
            ]),
        );

        $this->assertFalse($this->validateGoogleServicesConfiguration(collect([$plugin])));
    }

    public function test_manual_gradle_declaration_can_own_google_services_configuration()
    {
        File::put($this->testProjectPath.'/google-services.json', '{"project_id": "test"}');
        File::put(
            $this->testProjectPath.'/nativephp/android/build.gradle.kts',
            'plugins {'.PHP_EOL
            .'    id("com.google.gms.google-services") version "4.4.3" apply false'.PHP_EOL
            .'}'.PHP_EOL,
        );

        $this->assertTrue($this->validateGoogleServicesConfiguration(collect()));
        $this->assertSame([], $this->components->errors);
    }

    public function test_manual_buildscript_classpath_can_own_google_services_configuration()
    {
        File::put($this->testProjectPath.'/google-services.json', '{"project_id": "test"}');
        File::put(
            $this->testProjectPath.'/nativephp/android/build.gradle.kts',
            'buildscript {'.PHP_EOL
            .'    dependencies {'.PHP_EOL
            .'        classpath("com.google.gms:google-services:4.4.2")'.PHP_EOL
            .'    }'.PHP_EOL
            .'}'.PHP_EOL,
        );

        $this->assertTrue($this->validateGoogleServicesConfiguration(collect()));
        $this->assertSame([], $this->components->errors);
    }

    public function test_google_services_mentioned_only_in_a_comment_is_not_a_declaration()
    {
        File::put($this->testProjectPath.'/google-services.json', '{"project_id": "test"}');
        File::put(
            $this->testProjectPath.'/nativephp/android/build.gradle.kts',
            '// id("com.google.gms.google-services") is intentionally not configured'.PHP_EOL,
        );

        $this->assertFalse($this->validateGoogleServicesConfiguration(collect()));
    }

    public function test_stale_generated_declaration_does_not_claim_google_services_configuration()
    {
        File::makeDirectory($this->testProjectPath.'/nativephp/android/app', 0755, true);
        File::put($this->testProjectPath.'/nativephp/android/app/google-services.json', '{"project_id": "test"}');
        File::put(
            $this->testProjectPath.'/nativephp/android/build.gradle.kts',
            'plugins {'.PHP_EOL
            .'    // BEGIN nativephp-plugin-gradle-plugins'.PHP_EOL
            .'    id("com.google.gms.google-services") version "4.4.3" apply false'.PHP_EOL
            .'    // END nativephp-plugin-gradle-plugins'.PHP_EOL
            .'}'.PHP_EOL,
        );

        $this->assertFalse($this->validateGoogleServicesConfiguration(collect()));
    }

    public function test_project_without_google_services_configuration_needs_no_plugin()
    {
        $this->assertTrue($this->validateGoogleServicesConfiguration(collect()));
        $this->assertSame([], $this->components->errors);
    }

    public function test_update_local_properties_windows_path()
    {
        // Mock Windows environment
        $this->mockPlatform('Windows');

        config(['nativephp.android.android_sdk_path' => 'C:\\Users\\test\\Android\\Sdk']);

        // Execute
        $this->updateLocalProperties();

        $path = $this->testProjectPath.'/nativephp/android/local.properties';
        $contents = File::get($path);

        // The actual implementation converts to forward slashes on Windows
        $this->assertEquals("sdk.dir=C:/Users/test/Android/Sdk\n", $contents);
    }

    public function test_update_local_properties_unix_path()
    {
        // Mock Unix environment
        $this->mockPlatform('Linux');

        config(['nativephp.android.android_sdk_path' => '/home/user/Android/Sdk']);

        // Execute
        $this->updateLocalProperties();

        $path = $this->testProjectPath.'/nativephp/android/local.properties';
        $contents = File::get($path);

        $this->assertEquals("sdk.dir=/home/user/Android/Sdk\n", $contents);
    }

    public function test_update_deep_link_configuration()
    {
        // Set up config
        config(['nativephp.deeplink_scheme' => 'myapp']);
        config(['nativephp.deeplink_host' => 'app.example.com']);

        // Create AndroidManifest.xml with proper structure the real code expects
        $manifestPath = $this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml';
        File::makeDirectory(dirname($manifestPath), 0755, true);
        File::put($manifestPath, '<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <application>
        <activity android:name=".ui.MainActivity">
        </activity>
    </application>
</manifest>');

        // Execute
        $this->updateDeepLinkConfiguration();

        // Assert manifest was updated with deep link intent filters
        $manifestContents = File::get($manifestPath);
        $this->assertStringContainsString('android:scheme="myapp"', $manifestContents);
        $this->assertStringContainsString('android:host="app.example.com"', $manifestContents);
    }

    /**
     * Helper methods
     */
    protected function mockPlatform(string $platform)
    {
        // This would need to be implemented to properly mock PHP_OS_FAMILY
        // For testing purposes, we'll override the platform detection
    }

    protected function logToFile(string $message): void {}

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

    protected function installAndroidIcon()
    {
        // Mock for testing
    }

    protected function prepareLaravelBundle()
    {
        // Mock for testing
    }

    protected function runTheAndroidBuild($target)
    {
        // Mock for testing
    }

    // Abstract methods required by PreparesBuild trait
    protected function removeDirectory(string $path): void
    {
        if (is_dir($path)) {
            File::deleteDirectory($path);
        }
    }

    protected function platformOptimizedCopy(string $source, string $destination, array $excludedDirs): void
    {
        // Simple copy implementation for testing
        if (! is_dir($source)) {
            return;
        }

        if (! is_dir($destination)) {
            File::makeDirectory($destination, 0755, true);
        }

        $files = scandir($source);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $sourcePath = $source.'/'.$file;
            $destPath = $destination.'/'.$file;

            if (is_dir($sourcePath)) {
                if (! in_array($file, $excludedDirs)) {
                    $this->platformOptimizedCopy($sourcePath, $destPath, $excludedDirs);
                }
            } else {
                copy($sourcePath, $destPath);
            }
        }
    }
}
