<?php

namespace Tests\Feature;

use Native\Mobile\Traits\ManagesIosSigning;
use Tests\TestCase;

class IosFilePathCredentialsTest extends TestCase
{
    use ManagesIosSigning;

    protected function setUp(): void
    {
        parent::setUp();

        // Clean up any environment variables that might affect tests
        $this->cleanupEnvironmentVars();
    }

    protected function tearDown(): void
    {
        // Clean up environment variables after each test
        $this->cleanupEnvironmentVars();

        parent::tearDown();
    }

    public function test_end_to_end_certificate_resolution_with_file_path()
    {
        // Create a temporary certificate file
        $tempDir = sys_get_temp_dir().'/nativephp-test-'.uniqid();
        mkdir($tempDir, 0755, true);
        $certFile = $tempDir.'/test-cert.p12';
        $certContent = 'test certificate content for integration';
        file_put_contents($certFile, $certContent);

        // Set environment variable to point to file path
        putenv("IOS_DISTRIBUTION_CERTIFICATE_PATH={$certFile}");

        // Test the full resolution
        $result = $this->getCertificateForExportMethod('app-store');

        // Verify result is properly base64 encoded
        $this->assertEquals(base64_encode($certContent), $result);

        // Clean up
        unlink($certFile);
        rmdir($tempDir);
    }

    public function test_end_to_end_provisioning_profile_resolution_with_file_path()
    {
        // Create a temporary provisioning profile file
        $tempDir = sys_get_temp_dir().'/nativephp-test-'.uniqid();
        mkdir($tempDir, 0755, true);
        $profileFile = $tempDir.'/test-profile.mobileprovision';
        $profileContent = '<?xml version="1.0"?><plist><dict><key>Name</key><string>Test</string></dict></plist>';
        file_put_contents($profileFile, $profileContent);

        // Set environment variable to point to file path
        putenv("IOS_DISTRIBUTION_PROVISIONING_PROFILE_PATH={$profileFile}");

        // Test the full resolution
        $result = $this->getProvisioningProfileForExportMethod('app-store');

        // Verify result is properly base64 encoded
        $this->assertEquals(base64_encode($profileContent), $result);

        // Clean up
        unlink($profileFile);
        rmdir($tempDir);
    }

    public function test_backward_compatibility_with_base64_vars()
    {
        $base64Content = 'dGVzdCBjZXJ0aWZpY2F0ZSBjb250ZW50'; // base64 encoded content

        // Set the legacy base64 environment variable
        putenv("IOS_DISTRIBUTION_CERTIFICATE={$base64Content}");

        // Test that it still works
        $result = $this->getCertificateForExportMethod('app-store');

        // Should return the base64 content as-is
        $this->assertEquals($base64Content, $result);
    }

    public function test_path_variable_takes_precedence_over_base64()
    {
        // Create a temporary certificate file
        $tempDir = sys_get_temp_dir().'/nativephp-test-'.uniqid();
        mkdir($tempDir, 0755, true);
        $certFile = $tempDir.'/test-cert.p12';
        $certContent = 'certificate from file path';
        file_put_contents($certFile, $certContent);

        // Set both path and base64 variables
        putenv("IOS_DISTRIBUTION_CERTIFICATE_PATH={$certFile}");
        putenv('IOS_DISTRIBUTION_CERTIFICATE=base64fallback==');

        // Test that path variable takes precedence
        $result = $this->getCertificateForExportMethod('app-store');

        // Should use the file path content, not the base64 fallback
        $this->assertEquals(base64_encode($certContent), $result);

        // Clean up
        unlink($certFile);
        rmdir($tempDir);
    }

    public function test_handles_missing_file_gracefully()
    {
        $nonExistentFile = '/path/that/does/not/exist.p12';

        // Set path to non-existent file
        putenv("IOS_DISTRIBUTION_CERTIFICATE_PATH={$nonExistentFile}");

        // Should return null when file doesn't exist
        $result = $this->getCertificateForExportMethod('app-store');
        $this->assertNull($result);
    }

    private function cleanupEnvironmentVars(): void
    {
        $varsToClean = [
            'IOS_DISTRIBUTION_CERTIFICATE_PATH',
            'IOS_DISTRIBUTION_CERTIFICATE',
            'IOS_DEVELOPMENT_CERTIFICATE_PATH',
            'IOS_DEVELOPMENT_CERTIFICATE',
            'IOS_DISTRIBUTION_PROVISIONING_PROFILE_PATH',
            'IOS_DISTRIBUTION_PROVISIONING_PROFILE',
            'IOS_DEVELOPMENT_PROVISIONING_PROFILE_PATH',
            'IOS_DEVELOPMENT_PROVISIONING_PROFILE',
            'IOS_CERTIFICATE_P12_BASE64',
            'IOS_PROVISIONING_PROFILE_BASE64',
        ];

        foreach ($varsToClean as $var) {
            putenv($var);
        }
    }
}
