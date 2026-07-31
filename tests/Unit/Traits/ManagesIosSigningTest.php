<?php

namespace Tests\Unit\Traits;

use Native\Mobile\Traits\ManagesIosSigning;
use Tests\TestCase;

class ManagesIosSigningTest extends TestCase
{
    use ManagesIosSigning;

    public function test_detects_file_paths_correctly()
    {
        // Absolute paths
        $this->assertTrue($this->isFilePath('/path/to/certificate.p12'));
        $this->assertTrue($this->isFilePath('/Users/user/credentials/cert.p12'));
        $this->assertTrue($this->isFilePath('~/Documents/profile.mobileprovision'));

        // Relative paths
        $this->assertTrue($this->isFilePath('credentials/cert.p12'));
        $this->assertTrue($this->isFilePath('./cert.p12'));
        $this->assertTrue($this->isFilePath('../config/profile.mobileprovision'));

        // Different extensions
        $this->assertTrue($this->isFilePath('/path/to/file.mobileprovision'));
        $this->assertTrue($this->isFilePath('/path/to/key.p8'));
        $this->assertTrue($this->isFilePath('/path/to/cert.cer'));
    }

    public function test_detects_base64_strings_correctly()
    {
        // Typical base64 strings
        $this->assertFalse($this->isFilePath('MIIEvQIBADANBgkqhkiG9w=='));
        $this->assertFalse($this->isFilePath('LS0tLS1CRUdJTiBDRVJUSUZJQ0FURS0tLS0t'));

        // Base64 with padding
        $this->assertFalse($this->isFilePath('YWJjZGVmZ2hpamtsbW5vcA=='));
        $this->assertFalse($this->isFilePath('YWJjZGVmZw='));

        // Long base64 strings (certificates are usually long)
        $longBase64 = str_repeat('YWJjZGVmZw', 50).'==';
        $this->assertFalse($this->isFilePath($longBase64));

        // Empty or short strings that could be confused as paths
        $this->assertFalse($this->isFilePath(''));
        $this->assertFalse($this->isFilePath('abc'));
    }

    public function test_handles_edge_cases_gracefully()
    {
        // Null input
        $this->assertFalse($this->isFilePath(null));

        // URLs that might be confused as paths
        $this->assertFalse($this->isFilePath('https://example.com/cert.p12'));
        $this->assertFalse($this->isFilePath('http://localhost/file.mobileprovision'));

        // Paths with unusual characters
        $this->assertTrue($this->isFilePath('/path with spaces/cert.p12'));
        $this->assertTrue($this->isFilePath('/path-with-dashes/cert.p12'));
        $this->assertTrue($this->isFilePath('/path_with_underscores/cert.p12'));

        // Windows-style paths (should still be detected as file paths)
        $this->assertTrue($this->isFilePath('C:\\Users\\User\\cert.p12'));
        $this->assertTrue($this->isFilePath('C:/Users/User/cert.p12'));

        // Very short potential paths
        $this->assertTrue($this->isFilePath('./a'));
        $this->assertTrue($this->isFilePath('/a'));
    }

    public function test_resolves_file_path_to_base64()
    {
        // Create a temporary file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'test_cert');
        $testContent = 'test certificate content';
        file_put_contents($tempFile, $testContent);

        // Test that file content is properly base64 encoded
        $result = $this->resolveCredentialFromPath($tempFile);
        $this->assertEquals(base64_encode($testContent), $result);

        // Clean up
        unlink($tempFile);
    }

    public function test_returns_base64_string_unchanged()
    {
        $base64String = 'MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC==';

        $result = $this->resolveCredentialFromPath($base64String);
        $this->assertEquals($base64String, $result);
    }

    public function test_handles_missing_files_gracefully()
    {
        $nonExistentFile = '/path/that/does/not/exist.p12';

        $result = $this->resolveCredentialFromPath($nonExistentFile);
        $this->assertNull($result);
    }

    public function test_handles_unreadable_files_gracefully()
    {
        // Create a temporary file and make it unreadable
        $tempFile = tempnam(sys_get_temp_dir(), 'test_unreadable');
        file_put_contents($tempFile, 'content');
        chmod($tempFile, 0000); // Remove all permissions

        $result = $this->resolveCredentialFromPath($tempFile);
        $this->assertNull($result);

        // Clean up (restore permissions first)
        chmod($tempFile, 0644);
        unlink($tempFile);
    }

    public function test_prioritizes_path_vars_over_base64_vars()
    {
        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_cert');
        $fileContent = 'certificate from file';
        file_put_contents($tempFile, $fileContent);

        // Mock environment variables
        $this->mockEnvironmentVariables([
            'IOS_DISTRIBUTION_CERTIFICATE_PATH' => $tempFile,
            'IOS_DISTRIBUTION_CERTIFICATE' => 'base64-fallback-content==',
        ]);

        $result = $this->getCertificateForExportMethod('app-store');
        $this->assertEquals(base64_encode($fileContent), $result);

        // Clean up
        unlink($tempFile);
    }

    public function test_falls_back_to_base64_when_no_path()
    {
        $base64Content = 'MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC==';

        // Mock environment variables with only base64 var set
        $this->mockEnvironmentVariables([
            'IOS_DISTRIBUTION_CERTIFICATE_PATH' => null,
            'IOS_DISTRIBUTION_CERTIFICATE' => $base64Content,
        ]);

        $result = $this->getCertificateForExportMethod('app-store');
        $this->assertEquals($base64Content, $result);
    }

    public function test_gets_provisioning_profile_from_file_path()
    {
        // Create a temporary provisioning profile file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_profile');
        $profileContent = '<?xml version="1.0"?><!DOCTYPE plist><plist><dict><key>Name</key><string>Test Profile</string></dict></plist>';
        file_put_contents($tempFile, $profileContent);

        // Mock environment variables
        $this->mockEnvironmentVariables([
            'IOS_DISTRIBUTION_PROVISIONING_PROFILE_PATH' => $tempFile,
        ]);

        $result = $this->getProvisioningProfileForExportMethod('app-store');
        $this->assertEquals(base64_encode($profileContent), $result);

        // Clean up
        unlink($tempFile);
    }

    public function test_gets_api_key_from_file_path()
    {
        // Create a temporary API key file
        $tempFile = tempnam(sys_get_temp_dir(), 'test_api_key');
        $apiKeyContent = "-----BEGIN PRIVATE KEY-----\ntest content\n-----END PRIVATE KEY-----";
        file_put_contents($tempFile, $apiKeyContent);

        // Mock environment variables - assuming we'll add this functionality
        $this->mockEnvironmentVariables([
            'APP_STORE_API_KEY_PATH' => $tempFile,
        ]);

        // This test will need to be implemented when we add API key path support
        $this->markTestSkipped('API key file path support not yet implemented');

        // Clean up
        unlink($tempFile);
    }

    /**
     * Helper method to mock environment variables for testing
     */
    private function mockEnvironmentVariables(array $vars): void
    {
        foreach ($vars as $key => $value) {
            if ($value !== null) {
                putenv("{$key}={$value}");
            } else {
                putenv($key);
            }
        }
    }

    /**
     * Clean up environment variables after each test
     */
    protected function tearDown(): void
    {
        // Clean up any environment variables we might have set
        $varsToClean = [
            'IOS_DISTRIBUTION_CERTIFICATE_PATH',
            'IOS_DISTRIBUTION_CERTIFICATE',
            'IOS_DISTRIBUTION_PROVISIONING_PROFILE_PATH',
            'IOS_DISTRIBUTION_PROVISIONING_PROFILE',
            'APP_STORE_API_KEY_PATH',
            'APP_STORE_API_KEY',
        ];

        foreach ($varsToClean as $var) {
            putenv($var);
        }

        parent::tearDown();
    }
}
