<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Native\Mobile\Traits\PreparesBuild;
use Tests\TestCase;

class ConfigurationUpdatesTest extends TestCase
{
    use PreparesBuild {
        updateAndroidConfiguration as public testUpdateAndroidConfiguration;
    }

    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_config_test_'.uniqid();
        app()->setBasePath($this->testProjectPath);

        // Mock $this->components for task() calls used by PreparesBuild
        $this->components = new class
        {
            public function task(string $title, callable $callback)
            {
                $callback();
            }

            public function twoColumnDetail(...$args) {}

            public function warn(...$args) {}
        };

        // Create Android project structure
        $this->createAndroidProjectStructure();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);
        parent::tearDown();
    }

    public function test_configuration_update_with_all_changes()
    {
        // Set all new configurations
        config([
            'nativephp.app_id' => 'com.newcompany.newapp',
            'nativephp.version' => '3.0.0',
            'nativephp.version_code' => 3000,
            'app.name' => 'Updated App Name',
            'nativephp.permissions.push_notifications' => true,
            'nativephp.permissions.biometric' => true,
            'nativephp.permissions.nfc' => true,
            'nativephp.permissions.deeplinks' => true,
            'nativephp.deeplink_scheme' => 'newapp',
            'nativephp.deeplink_host' => 'new.example.com',
            'nativephp.android.android_sdk_path' => '/new/path/to/sdk',
            // Add build configuration
            'nativephp.android.build.minify_enabled' => true,
            'nativephp.android.build.obfuscate' => false,
            'nativephp.android.build.shrink_resources' => true,
            'nativephp.android.build.debug_symbols' => 'SYMBOL_TABLE',
        ]);

        // Create google-services.json
        File::put($this->testProjectPath.'/google-services.json', '{"project_id": "test"}');

        // Enable ICU via nativephp.json
        File::put($this->testProjectPath.'/nativephp.json', json_encode(['php' => ['version' => '8.4.7', 'icu' => true]]));

        // Execute configuration update
        $this->testUpdateAndroidConfiguration();

        // Assert all configurations were applied
        $this->assertApplicationIdUpdated();
        $this->assertVersionUpdated();
        $this->assertAppNameUpdated();
        $this->assertPermissionsUpdated();
        $this->assertDeepLinksConfigured();
        $this->assertFirebaseConfigCopied();
        $this->assertSdkPathUpdated();
        $this->assertIcuConfigured();
        $this->assertBuildConfigurationUpdated();
    }

    public function test_partial_configuration_update()
    {
        // Set app_id to the placeholder value so it gets updated
        config([
            'nativephp.app_id' => 'com.partial.app',
            'nativephp.version' => '2.5.0',
            'nativephp.permissions.push_notifications' => true,
        ]);

        // Execute
        $this->testUpdateAndroidConfiguration();

        // Assert only changed configurations were updated
        $gradleContents = File::get($this->testProjectPath.'/nativephp/android/app/build.gradle.kts');
        $this->assertStringContainsString('versionName = "2.5.0"', $gradleContents);

        $manifestContents = File::get($this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml');
        $this->assertStringContainsString('android.permission.POST_NOTIFICATIONS', $manifestContents);

        // Namespace should remain fixed at com.nativephp.mobile
        $this->assertStringContainsString('namespace = "com.nativephp.mobile"', $gradleContents);
        // ApplicationId should be updated
        $this->assertStringContainsString('applicationId = "com.partial.app"', $gradleContents);
    }

    public function test_permission_toggle_on_off()
    {
        // Initial state with permission on
        config(['nativephp.permissions.biometric' => true]);
        $this->testUpdateAndroidConfiguration();

        $manifestContents = File::get($this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml');
        $this->assertStringContainsString('android.permission.USE_BIOMETRIC', $manifestContents);

        // Toggle permission off
        config(['nativephp.permissions.biometric' => false]);
        $this->testUpdateAndroidConfiguration();

        $manifestContents = File::get($this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml');
        $this->assertStringNotContainsString('android.permission.USE_BIOMETRIC', $manifestContents);
    }

    public function test_app_id_only_changes_application_id()
    {
        // Change app ID
        config(['nativephp.app_id' => 'com.updated.app']);
        $this->testUpdateAndroidConfiguration();

        // Assert only applicationId was changed, NOT namespace
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        $gradleContents = File::get($gradlePath);

        // Namespace should remain fixed
        $this->assertStringContainsString('namespace = "com.nativephp.mobile"', $gradleContents);
        // ApplicationId should be updated
        $this->assertStringContainsString('applicationId = "com.updated.app"', $gradleContents);

        // Source files should NOT be moved - they stay in com/nativephp/mobile
        $this->assertDirectoryExists($this->testProjectPath.'/nativephp/android/app/src/main/java/com/nativephp/mobile');
        $this->assertDirectoryDoesNotExist($this->testProjectPath.'/nativephp/android/app/src/main/java/com/updated/app');
    }

    public function test_invalid_configurations_handled_gracefully()
    {
        // Set invalid configurations
        config([
            'nativephp.app_id' => '', // Empty app ID
            'nativephp.deeplink_scheme' => 'invalid scheme with spaces',
            'nativephp.android.android_sdk_path' => 'invalid\\path\\without\\drive',
        ]);

        // Execute - should not throw exceptions
        $this->testUpdateAndroidConfiguration();

        // Assert original values remain or graceful handling
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function test_update_android_configuration()
    {
        // This tests the trait method directly
        config([
            'nativephp.app_id' => 'com.test.myapp',
            'nativephp.version' => '1.5.0',
        ]);

        $this->testUpdateAndroidConfiguration();

        // Assert that configuration was updated
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        $this->assertFileExists($gradlePath);
        $contents = File::get($gradlePath);
        $this->assertStringContainsString('versionName = "1.5.0"', $contents);
        $this->assertStringContainsString('applicationId = "com.test.myapp"', $contents);
    }

    public function test_build_configuration_independent_minification_obfuscation()
    {
        // Test the key feature: independent control of minification and obfuscation
        config([
            'nativephp.app_id' => 'com.test.app',
            'nativephp.android.build.minify_enabled' => true,
            'nativephp.android.build.obfuscate' => false,  // This is the key test case
            'nativephp.android.build.shrink_resources' => false,
            'nativephp.android.build.debug_symbols' => 'FULL',
            'nativephp.android.build.keep_line_numbers' => true,
        ]);

        $this->testUpdateAndroidConfiguration();

        // Assert minification is enabled but obfuscation is disabled
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        $gradleContents = File::get($gradlePath);
        $this->assertStringContainsString('isMinifyEnabled = true', $gradleContents);
        $this->assertStringContainsString('isShrinkResources = false', $gradleContents);
        $this->assertStringContainsString('debugSymbolLevel = "FULL"', $gradleContents);

        // Assert obfuscation is disabled via ProGuard rules
        $proguardPath = $this->testProjectPath.'/nativephp/android/app/proguard-rules.pro';
        $proguardContents = File::get($proguardPath);
        $this->assertStringContainsString('-dontobfuscate', $proguardContents);
        $this->assertStringContainsString('-keepattributes SourceFile,LineNumberTable', $proguardContents);
    }

    /**
     * Helper methods
     */
    protected function createAndroidProjectStructure(): void
    {
        $structure = [
            'nativephp/android' => [
                'app' => [
                    'build.gradle.kts' => 'android {
    namespace = "com.nativephp.mobile"

    defaultConfig {
        applicationId = "REPLACE_APP_ID"
    }

    versionCode = 1
    versionName = "1.0.0"

    buildTypes {
        release {
            isMinifyEnabled = REPLACE_MINIFY_ENABLED
            isShrinkResources = REPLACE_SHRINK_RESOURCES
            ndk {
                debugSymbolLevel = "REPLACE_DEBUG_SYMBOLS"
            }
        }
    }
}',
                    'proguard-rules.pro' => '# Add project specific ProGuard rules here.

# NativePHP WebView JavaScript Interface
-keepclassmembers class com.**.bridge.** {
    @android.webkit.JavascriptInterface <methods>;
}

# Debug information preservation (configurable)
REPLACE_KEEP_LINE_NUMBERS
REPLACE_KEEP_SOURCE_FILE

# Obfuscation control (configurable)
REPLACE_OBFUSCATION_CONTROL

# Custom ProGuard rules (configurable)
REPLACE_CUSTOM_PROGUARD_RULES',
                    'src/main' => [
                        'AndroidManifest.xml' => '<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <application android:label="NativePHP">
        <activity android:name=".ui.MainActivity">
        </activity>
    </application>
</manifest>',
                        'java/com/nativephp/mobile' => [
                            'ui/MainActivity.kt' => 'package com.nativephp.mobile.ui
class MainActivity',
                            'bridge/PHPBridge.kt' => 'package com.nativephp.mobile.bridge
class PHPBridge {
    init {
        System.loadLibrary("php")
    }
}',
                            'network/WebViewManager.kt' => 'package com.nativephp.mobile.network
if (url.startsWith("REPLACEME://")) {',
                        ],
                        'cpp' => [
                            'CMakeLists.txt' => 'project("com_nativephp_mobile")',
                            'php_bridge.c' => '#include <jni.h>
Java_com_nativephp_mobile_bridge_PHPBridge',
                        ],
                    ],
                ],
                'settings.gradle.kts' => 'rootProject.name = "NativePHP"',
                'local.properties' => '',
            ],
        ];

        $this->createDirectoryStructure($this->testProjectPath, $structure);
    }

    protected function assertApplicationIdUpdated(): void
    {
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        $contents = File::get($gradlePath);
        // Namespace should stay fixed
        $this->assertStringContainsString('namespace = "com.nativephp.mobile"', $contents);
        // ApplicationId should be updated
        $this->assertStringContainsString('applicationId = "com.newcompany.newapp"', $contents);

        // Source files should NOT be moved - they stay in com/nativephp/mobile
        $this->assertDirectoryExists($this->testProjectPath.'/nativephp/android/app/src/main/java/com/nativephp/mobile');
    }

    protected function assertVersionUpdated(): void
    {
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        $contents = File::get($gradlePath);
        $this->assertStringContainsString('versionName = "3.0.0"', $contents);
        $this->assertStringContainsString('versionCode = 3000', $contents);
    }

    protected function assertAppNameUpdated(): void
    {
        $manifestPath = $this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml';
        $this->assertStringContainsString('android:label="Updated App Name"', File::get($manifestPath));
    }

    protected function assertPermissionsUpdated(): void
    {
        $manifestPath = $this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml';
        $contents = File::get($manifestPath);
        $this->assertStringContainsString('android.permission.POST_NOTIFICATIONS', $contents);
        $this->assertStringContainsString('android.permission.USE_BIOMETRIC', $contents);
        $this->assertStringContainsString('android.permission.NFC', $contents);
    }

    protected function assertDeepLinksConfigured(): void
    {
        $manifestPath = $this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml';
        $contents = File::get($manifestPath);
        $this->assertStringContainsString('android:scheme="newapp"', $contents);
        $this->assertStringContainsString('android:host="new.example.com"', $contents);
    }

    protected function assertFirebaseConfigCopied(): void
    {
        $targetPath = $this->testProjectPath.'/nativephp/android/app/google-services.json';
        $this->assertFileExists($targetPath);
        $this->assertEquals('{"project_id": "test"}', File::get($targetPath));
    }

    protected function assertSdkPathUpdated(): void
    {
        $localPropertiesPath = $this->testProjectPath.'/nativephp/android/local.properties';
        $this->assertStringContainsString('sdk.dir=/new/path/to/sdk', File::get($localPropertiesPath));
    }

    protected function assertIcuConfigured(): void
    {
        $bridgePath = $this->testProjectPath.'/nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt';
        $contents = File::get($bridgePath);
        $this->assertStringContainsString('System.loadLibrary("icudata")', $contents);
    }

    protected function assertBuildConfigurationUpdated(): void
    {
        // Assert Gradle build configuration
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        $gradleContents = File::get($gradlePath);
        $this->assertStringContainsString('isMinifyEnabled = true', $gradleContents);
        $this->assertStringContainsString('isShrinkResources = true', $gradleContents);
        $this->assertStringContainsString('debugSymbolLevel = "SYMBOL_TABLE"', $gradleContents);

        // Assert ProGuard configuration (obfuscate = false should add -dontobfuscate)
        $proguardPath = $this->testProjectPath.'/nativephp/android/app/proguard-rules.pro';
        $proguardContents = File::get($proguardPath);
        $this->assertStringContainsString('-dontobfuscate', $proguardContents);
    }

    /**
     * Mock methods - Required by PreparesBuild trait
     */
    protected function logToFile(string $message): void {}

    protected function updateOrientationConfiguration(): void {}

    protected function info($message) {}

    protected function warn($message) {}

    protected function error($message) {}

    protected function line($message) {}

    protected function newLine() {}

    protected function removeDirectory(string $path): void
    {
        if (is_dir($path)) {
            File::deleteDirectory($path);
        }
    }

    protected function platformOptimizedCopy(string $source, string $destination, array $excludedDirs): void
    {
        // Simple copy implementation for testing
        $this->copyDirectoryRecursive($source, $destination, $excludedDirs);
    }

    protected function copyDirectoryRecursive(string $source, string $destination, array $excludedDirs = []): void
    {
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
                    $this->copyDirectoryRecursive($sourcePath, $destPath, $excludedDirs);
                }
            } else {
                copy($sourcePath, $destPath);
            }
        }
    }

    // Abstract methods required by PreparesBuild trait
    protected function detectCurrentAppId(): ?string
    {
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        if (! File::exists($gradlePath)) {
            return null;
        }

        $contents = File::get($gradlePath);
        if (preg_match('/applicationId\s*=\s*"(.*?)"/', $contents, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function updateAppId(string $oldAppId, string $newAppId): void
    {
        // Since AGP 7.0, namespace and applicationId are decoupled.
        // Only update the applicationId in build.gradle.kts
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        if (File::exists($gradlePath)) {
            $contents = File::get($gradlePath);
            $contents = preg_replace('/applicationId\s*=\s*".*?"/', 'applicationId = "'.$newAppId.'"', $contents);
            File::put($gradlePath, $contents);
        }
    }

    protected function updateLocalProperties(): void
    {
        $sdkPath = config('nativephp.android.android_sdk_path');
        if ($sdkPath) {
            $path = $this->testProjectPath.'/nativephp/android/local.properties';
            File::put($path, "sdk.dir=$sdkPath".PHP_EOL);
        }
    }

    protected function updateVersionConfiguration(): void
    {
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        if (File::exists($gradlePath)) {
            $contents = File::get($gradlePath);
            $version = config('nativephp.version');
            $versionCode = config('nativephp.version_code');

            if ($version) {
                $contents = preg_replace('/versionName\s*=\s*".*?"/', 'versionName = "'.$version.'"', $contents);
            }
            if ($versionCode) {
                $contents = preg_replace('/versionCode\s*=\s*\d+/', 'versionCode = '.$versionCode, $contents);
            }

            File::put($gradlePath, $contents);
        }
    }

    protected function updateAppDisplayName(): void
    {
        $manifestPath = $this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml';
        if (File::exists($manifestPath)) {
            $contents = File::get($manifestPath);
            $appName = config('app.name');
            if ($appName) {
                $contents = preg_replace('/android:label=".*?"/', 'android:label="'.$appName.'"', $contents);
                File::put($manifestPath, $contents);
            }
        }
    }

    protected function updatePermissions(): void
    {
        $manifestPath = $this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml';
        if (File::exists($manifestPath)) {
            $contents = File::get($manifestPath);

            // Handle push notifications permission
            if (config('nativephp.permissions.push_notifications')) {
                if (! str_contains($contents, 'android.permission.POST_NOTIFICATIONS')) {
                    $contents = str_replace('<manifest', '<manifest xmlns:android="http://schemas.android.com/apk/res/android">'.PHP_EOL.'    <uses-permission android:name="android.permission.POST_NOTIFICATIONS" />', $contents);
                }
            } else {
                // Remove permission if disabled
                $contents = preg_replace('/\s*<uses-permission[^>]*android\.permission\.POST_NOTIFICATIONS[^>]*\/?>/', '', $contents);
            }

            // Handle biometric permission
            if (config('nativephp.permissions.biometric')) {
                if (! str_contains($contents, 'android.permission.USE_BIOMETRIC')) {
                    $contents = str_replace('<manifest', '<manifest xmlns:android="http://schemas.android.com/apk/res/android">'.PHP_EOL.'    <uses-permission android:name="android.permission.USE_BIOMETRIC" />', $contents);
                }
            } else {
                // Remove permission if disabled
                $contents = preg_replace('/\s*<uses-permission[^>]*android\.permission\.USE_BIOMETRIC[^>]*\/?>/', '', $contents);
            }

            // Handle NFC permission
            if (config('nativephp.permissions.nfc')) {
                if (! str_contains($contents, 'android.permission.NFC')) {
                    $contents = str_replace('<manifest', '<manifest xmlns:android="http://schemas.android.com/apk/res/android">'.PHP_EOL.'    <uses-permission android:name="android.permission.NFC" />', $contents);
                }
            } else {
                // Remove permission if disabled
                $contents = preg_replace('/\s*<uses-permission[^>]*android\.permission\.NFC[^>]*\/?>/', '', $contents);
            }

            File::put($manifestPath, $contents);
        }
    }

    protected function updateDeepLinkConfiguration(): void
    {
        $manifestPath = $this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml';
        if (File::exists($manifestPath)) {
            $contents = File::get($manifestPath);
            $scheme = config('nativephp.deeplink_scheme');
            $host = config('nativephp.deeplink_host');

            if ($scheme || $host) {
                // Simple implementation for tests
                $intentFilter = '<intent-filter android:autoVerify="true">';
                if ($scheme) {
                    $intentFilter .= '<data android:scheme="'.$scheme.'"';
                    if ($host) {
                        $intentFilter .= ' android:host="'.$host.'"';
                    }
                    $intentFilter .= ' />';
                }
                $intentFilter .= '</intent-filter>';

                $contents = str_replace('</activity>', $intentFilter.PHP_EOL.'        </activity>', $contents);
                File::put($manifestPath, $contents);
            }
        }
    }

    protected function updateIcuConfiguration(): void
    {
        $jsonPath = $this->testProjectPath.'/nativephp.json';

        if (! file_exists($jsonPath)) {
            return;
        }

        $nativephp = json_decode(file_get_contents($jsonPath), true) ?? [];

        if (empty($nativephp['php']['icu'])) {
            return;
        }

        // ICU is configured in the fixed path since namespace doesn't change
        $bridgePath = $this->testProjectPath.'/nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt';
        if (File::exists($bridgePath)) {
            $contents = File::get($bridgePath);
            if (! str_contains($contents, 'System.loadLibrary("icudata")')) {
                $contents = str_replace('System.loadLibrary("php")', 'System.loadLibrary("icudata")'.PHP_EOL.'        System.loadLibrary("php")', $contents);
            }
            File::put($bridgePath, $contents);
        }
    }

    protected function updateFirebaseConfiguration(): void
    {
        $sourcePath = $this->testProjectPath.'/google-services.json';
        $targetPath = $this->testProjectPath.'/nativephp/android/app/google-services.json';

        if (File::exists($sourcePath)) {
            File::copy($sourcePath, $targetPath);
        }
    }

    protected function createDirectoryStructure(string $basePath, array $structure): void
    {
        foreach ($structure as $name => $content) {
            $path = $basePath.'/'.$name;

            if (is_array($content)) {
                // Directory
                File::ensureDirectoryExists($path);
                $this->createDirectoryStructure($path, $content);
            } else {
                // File
                File::ensureDirectoryExists(dirname($path));
                File::put($path, $content);
            }
        }
    }
}
