<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Native\Mobile\Traits\PreparesBuild;
use Tests\TestCase;

class BuildConfigurationTest extends TestCase
{
    use PreparesBuild {
        updateBuildConfiguration as public testUpdateBuildConfiguration;
    }

    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_build_config_test_'.uniqid();
        app()->setBasePath($this->testProjectPath);

        // Create Android project structure with build configuration files
        $this->createAndroidBuildStructure();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);
        parent::tearDown();
    }

    public function test_minification_and_obfuscation_both_enabled()
    {
        // Configure both minification and obfuscation enabled
        config([
            'nativephp.android.build.minify_enabled' => true,
            'nativephp.android.build.obfuscate' => true,
            'nativephp.android.build.shrink_resources' => true,
            'nativephp.android.build.debug_symbols' => 'FULL',
            'nativephp.android.build.keep_line_numbers' => true,
            'nativephp.android.build.keep_source_file' => true,
        ]);

        // Execute configuration update
        $this->testUpdateBuildConfiguration();

        // Assert Gradle configuration
        $gradleContent = File::get($this->testProjectPath.'/nativephp/android/app/build.gradle.kts');
        $this->assertStringContainsString('isMinifyEnabled = true', $gradleContent);
        $this->assertStringContainsString('isShrinkResources = true', $gradleContent);
        $this->assertStringContainsString('debugSymbolLevel = "FULL"', $gradleContent);

        // Assert ProGuard configuration - should NOT contain -dontobfuscate
        $proguardContent = File::get($this->testProjectPath.'/nativephp/android/app/proguard-rules.pro');
        $this->assertStringContainsString('-keepattributes SourceFile,LineNumberTable', $proguardContent);
        $this->assertStringContainsString('-renamesourcefileattribute SourceFile', $proguardContent);
        $this->assertStringNotContainsString('-dontobfuscate', $proguardContent);
    }

    public function test_minification_enabled_obfuscation_disabled()
    {
        // Configure minification enabled but obfuscation disabled
        config([
            'nativephp.android.build.minify_enabled' => true,
            'nativephp.android.build.obfuscate' => false,
            'nativephp.android.build.shrink_resources' => false,
            'nativephp.android.build.debug_symbols' => 'SYMBOL_TABLE',
            'nativephp.android.build.keep_line_numbers' => false,
            'nativephp.android.build.keep_source_file' => false,
        ]);

        // Execute configuration update
        $this->testUpdateBuildConfiguration();

        // Assert Gradle configuration
        $gradleContent = File::get($this->testProjectPath.'/nativephp/android/app/build.gradle.kts');
        $this->assertStringContainsString('isMinifyEnabled = true', $gradleContent);
        $this->assertStringContainsString('isShrinkResources = false', $gradleContent);
        $this->assertStringContainsString('debugSymbolLevel = "SYMBOL_TABLE"', $gradleContent);

        // Assert ProGuard configuration - should contain -dontobfuscate
        $proguardContent = File::get($this->testProjectPath.'/nativephp/android/app/proguard-rules.pro');
        $this->assertStringContainsString('-dontobfuscate', $proguardContent);
        $this->assertStringNotContainsString('-keepattributes SourceFile,LineNumberTable', $proguardContent);
        $this->assertStringNotContainsString('-renamesourcefileattribute SourceFile', $proguardContent);
    }

    public function test_both_minification_and_obfuscation_disabled()
    {
        // Configure both disabled
        config([
            'nativephp.android.build.minify_enabled' => false,
            'nativephp.android.build.obfuscate' => false,
            'nativephp.android.build.shrink_resources' => false,
            'nativephp.android.build.debug_symbols' => 'NONE',
        ]);

        // Execute configuration update
        $this->testUpdateBuildConfiguration();

        // Assert Gradle configuration
        $gradleContent = File::get($this->testProjectPath.'/nativephp/android/app/build.gradle.kts');
        $this->assertStringContainsString('isMinifyEnabled = false', $gradleContent);
        $this->assertStringContainsString('isShrinkResources = false', $gradleContent);
        $this->assertStringContainsString('debugSymbolLevel = "NONE"', $gradleContent);

        // Assert ProGuard configuration - should contain -dontobfuscate (redundant but safe)
        $proguardContent = File::get($this->testProjectPath.'/nativephp/android/app/proguard-rules.pro');
        $this->assertStringContainsString('-dontobfuscate', $proguardContent);
    }

    public function test_custom_proguard_rules_applied()
    {
        // Configure with custom ProGuard rules
        config([
            'nativephp.android.build.minify_enabled' => true,
            'nativephp.android.build.obfuscate' => true,
            'nativephp.android.build.custom_proguard_rules' => [
                '-keep class com.mycompany.** { *; }',
                '-dontwarn com.thirdparty.**',
            ],
        ]);

        // Execute configuration update
        $this->testUpdateBuildConfiguration();

        // Assert custom rules were added
        $proguardContent = File::get($this->testProjectPath.'/nativephp/android/app/proguard-rules.pro');
        $this->assertStringContainsString('-keep class com.mycompany.** { *; }', $proguardContent);
        $this->assertStringContainsString('-dontwarn com.thirdparty.**', $proguardContent);
    }

    public function test_configuration_replaces_existing_hardcoded_values()
    {
        // Pre-populate files with hardcoded values that should be replaced
        $gradlePath = $this->testProjectPath.'/nativephp/android/app/build.gradle.kts';
        $gradleContent = File::get($gradlePath);
        $gradleContent = str_replace('isMinifyEnabled = REPLACE_MINIFY_ENABLED', 'isMinifyEnabled = true', $gradleContent);
        $gradleContent = str_replace('isShrinkResources = REPLACE_SHRINK_RESOURCES', 'isShrinkResources = true', $gradleContent);
        File::put($gradlePath, $gradleContent);

        // Configure to disable
        config([
            'nativephp.android.build.minify_enabled' => false,
            'nativephp.android.build.obfuscate' => false,
            'nativephp.android.build.shrink_resources' => false,
        ]);

        // Execute configuration update
        $this->testUpdateBuildConfiguration();

        // Assert hardcoded values were replaced
        $updatedContent = File::get($gradlePath);
        $this->assertStringContainsString('isMinifyEnabled = false', $updatedContent);
        $this->assertStringContainsString('isShrinkResources = false', $updatedContent);
    }

    public function test_default_configuration_values()
    {
        // Don't set any configuration - should use defaults
        config(['nativephp.android.build' => []]);

        // Execute configuration update
        $this->testUpdateBuildConfiguration();

        // Assert defaults were applied
        $gradleContent = File::get($this->testProjectPath.'/nativephp/android/app/build.gradle.kts');
        $this->assertStringContainsString('isMinifyEnabled = false', $gradleContent);  // Default is false
        $this->assertStringContainsString('isShrinkResources = false', $gradleContent);  // Default is false
        $this->assertStringContainsString('debugSymbolLevel = "FULL"', $gradleContent);  // Default is FULL

        // Default obfuscation is false, so should contain -dontobfuscate
        $proguardContent = File::get($this->testProjectPath.'/nativephp/android/app/proguard-rules.pro');
        $this->assertStringContainsString('-dontobfuscate', $proguardContent);
    }

    public function test_missing_files_handled_gracefully()
    {
        // Remove required files to test graceful handling
        File::delete($this->testProjectPath.'/nativephp/android/app/build.gradle.kts');
        File::delete($this->testProjectPath.'/nativephp/android/app/proguard-rules.pro');

        // Configure some settings
        config([
            'nativephp.android.build.minify_enabled' => true,
            'nativephp.android.build.obfuscate' => false,
        ]);

        // Execute - should not throw exceptions
        $this->testUpdateBuildConfiguration();

        // Assert we get here without exceptions
        $this->assertTrue(true);
    }

    public function test_environment_variable_mapping()
    {
        // Test that the configuration properly applies values that would come from environment variables
        // This simulates what would happen when env vars are loaded through config
        config([
            'nativephp.android.build.minify_enabled' => true,  // from NATIVEPHP_ANDROID_MINIFY_ENABLED=true
            'nativephp.android.build.obfuscate' => false,  // from NATIVEPHP_ANDROID_OBFUSCATE=false
            'nativephp.android.build.shrink_resources' => true,  // from NATIVEPHP_ANDROID_SHRINK_RESOURCES=true
            'nativephp.android.build.debug_symbols' => 'SYMBOL_TABLE',  // from NATIVEPHP_ANDROID_DEBUG_SYMBOLS=SYMBOL_TABLE
            'nativephp.android.build.keep_line_numbers' => true,  // from NATIVEPHP_ANDROID_KEEP_LINE_NUMBERS=true
        ]);

        // Execute configuration update
        $this->testUpdateBuildConfiguration();

        // Assert configuration was applied from environment
        $gradleContent = File::get($this->testProjectPath.'/nativephp/android/app/build.gradle.kts');
        $this->assertStringContainsString('isMinifyEnabled = true', $gradleContent);
        $this->assertStringContainsString('isShrinkResources = true', $gradleContent);
        $this->assertStringContainsString('debugSymbolLevel = "SYMBOL_TABLE"', $gradleContent);

        $proguardContent = File::get($this->testProjectPath.'/nativephp/android/app/proguard-rules.pro');
        $this->assertStringContainsString('-dontobfuscate', $proguardContent);  // obfuscate=false
        $this->assertStringContainsString('-keepattributes SourceFile,LineNumberTable', $proguardContent);  // keep_line_numbers=true
    }

    /**
     * Helper methods
     */
    protected function createAndroidBuildStructure(): void
    {
        $structure = [
            'nativephp/android/app' => [
                'build.gradle.kts' => 'android {
    namespace = "com.nativephp.mobile"
    compileSdk = 34

    buildTypes {
        release {
            isMinifyEnabled = REPLACE_MINIFY_ENABLED
            isShrinkResources = REPLACE_SHRINK_RESOURCES
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
            
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

# Keep PHP bridge classes
-keep class com.**.bridge.** { *; }

# Debug information preservation (configurable)
REPLACE_KEEP_LINE_NUMBERS
REPLACE_KEEP_SOURCE_FILE

# Obfuscation control (configurable)
REPLACE_OBFUSCATION_CONTROL

# Custom ProGuard rules (configurable)
REPLACE_CUSTOM_PROGUARD_RULES',
            ],
        ];

        $this->createDirectoryStructure($this->testProjectPath, $structure);
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

    /**
     * Mock methods - Required by PreparesBuild trait
     */
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
        // Mock implementation
    }

    // Abstract methods required by PreparesBuild trait
    protected function detectCurrentAppId(): ?string
    {
        return null;
    }

    protected function updateAppId(string $oldAppId, string $newAppId): void {}

    protected function updateLocalProperties(): void {}

    protected function updateVersionConfiguration(): void {}

    protected function updateAppDisplayName(): void {}

    protected function updateDeepLinkConfiguration(): void {}

    protected function updatePermissions(): void {}

    protected function updateIcuConfiguration(): void {}

    protected function updateFirebaseConfiguration(): void {}
}
