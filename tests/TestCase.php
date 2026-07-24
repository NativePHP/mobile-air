<?php

namespace Tests;

use Native\Mobile\NativeServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            NativeServiceProvider::class,
        ];
    }

    protected function tearDown(): void
    {
        // Async task state lives in process statics (persistent-runtime shaped),
        // so reset it between tests for isolation.
        \Native\Mobile\AsyncTask::clearFake();
        \Native\Mobile\Support\AsyncTaskRegistry::flush();
        \Native\Mobile\Support\NativeCallbacks::flush();

        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app)
    {
        // Set up default config values for testing
        $app['config']->set('nativephp.app_id', 'com.test.app');
        $app['config']->set('nativephp.version', '1.0.0');
        $app['config']->set('nativephp.version_code', 1);
        $app['config']->set('app.name', 'Test App');

        // Set up default permissions
        $app['config']->set('nativephp.permissions', [
            'push_notifications' => false,
            'biometric' => false,
            'nfc' => false,
            'deeplinks' => false,
        ]);

        // Android specific config
        $app['config']->set('nativephp.android', [
            'android_sdk_path' => '/home/user/Android/Sdk',
            '7zip-location' => 'C:\\Program Files\\7-Zip\\7z.exe',
        ]);
    }

    /**
     * Mock PHP_OS_FAMILY constant for testing cross-platform behavior
     */
    protected function mockOperatingSystem(string $os): void
    {
        // This is a simplified approach. In real tests, you might need
        // to use runkit or uopz extensions to override constants
        putenv("MOCK_PHP_OS_FAMILY=$os");
    }

    /**
     * Get mocked OS family
     */
    protected function getMockedOSFamily(): string
    {
        return getenv('MOCK_PHP_OS_FAMILY') ?: PHP_OS_FAMILY;
    }

    /**
     * Helper to create a directory structure
     */
    protected function createDirectoryStructure(string $basePath, array $structure): void
    {
        foreach ($structure as $path => $content) {
            if (is_array($content)) {
                // It's a directory with subdirectories/files
                $fullPath = $basePath.'/'.$path;
                if (! is_dir($fullPath)) {
                    mkdir($fullPath, 0755, true);
                }
                $this->createDirectoryStructure($fullPath, $content);
            } else {
                // It's a file
                $fullPath = $basePath.'/'.$path;
                $dir = dirname($fullPath);
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($fullPath, $content);
            }
        }
    }

    /**
     * Assert directory structure matches expected
     */
    protected function assertDirectoryStructure(string $basePath, array $expectedStructure): void
    {
        foreach ($expectedStructure as $path => $content) {
            if (is_array($content)) {
                // Check directory exists and recurse
                $fullPath = $basePath.'/'.$path;
                $this->assertDirectoryExists($fullPath);
                $this->assertDirectoryStructure($fullPath, $content);
            } else {
                // Check file exists and content matches
                $fullPath = $basePath.'/'.$path;
                $this->assertFileExists($fullPath);
                if ($content !== null) {
                    $this->assertEquals($content, file_get_contents($fullPath));
                }
            }
        }
    }
}
