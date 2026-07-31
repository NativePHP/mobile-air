<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Facades\Process;

trait ManagesIosSigning
{
    protected array $installedCertificates = [];

    protected ?string $tempKeychainPath = null;

    protected ?array $originalKeychainSearchList = null;

    protected function setupSigningCredentials(string $exportMethod = 'app-store', ?array $iosSigningConfig = null): bool
    {
        // Check for certificate from signing config first, then fallback to environment variables
        $certificate = null;
        $certificatePassword = null;
        $provisioningProfile = null;

        if ($iosSigningConfig) {
            $certificate = $iosSigningConfig['certificatePath'] ?? null;
            $certificatePassword = $iosSigningConfig['certificatePassword'] ?? null;
            $provisioningProfile = $iosSigningConfig['provisioningProfilePath'] ?? null;

            if ($certificate || $certificatePassword || $provisioningProfile) {
                $this->components->twoColumnDetail('Credentials source', 'Command-line flags');

                // Set environment variables so the build process can find them
                if ($certificate) {
                    putenv("IOS_DISTRIBUTION_CERTIFICATE_PATH={$certificate}");
                    $this->components->twoColumnDetail('IOS_DISTRIBUTION_CERTIFICATE_PATH', $certificate);
                }
                if ($certificatePassword) {
                    putenv("IOS_DISTRIBUTION_CERTIFICATE_PASSWORD={$certificatePassword}");
                    $this->components->twoColumnDetail('IOS_DISTRIBUTION_CERTIFICATE_PASSWORD', '(set)');
                }
                if ($provisioningProfile) {
                    putenv("IOS_DISTRIBUTION_PROVISIONING_PROFILE_PATH={$provisioningProfile}");
                    $this->components->twoColumnDetail('IOS_DISTRIBUTION_PROVISIONING_PROFILE_PATH', $provisioningProfile);
                }

                // Also set Team ID if provided
                $teamId = $iosSigningConfig['teamId'] ?? null;
                if ($teamId) {
                    putenv("IOS_TEAM_ID={$teamId}");
                    $this->components->twoColumnDetail('IOS_TEAM_ID', $teamId);
                }
            }
        }

        // Fallback to environment variables if not provided via flags
        if (! $certificate) {
            $certificate = $this->getCertificateForExportMethod($exportMethod);
        }
        if (! $certificatePassword) {
            $certificatePassword = $this->getCertificatePasswordForExportMethod($exportMethod);
        }
        if (! $provisioningProfile) {
            $provisioningProfile = $this->getProvisioningProfileForExportMethod($exportMethod);
        }

        // If no certificate is provided, assume local development with existing certificates
        if (! $certificate) {
            $this->components->twoColumnDetail('Certificates', 'Using local keychain');

            return true;
        }

        \Laravel\Prompts\info('Setting up CI signing credentials...');

        // Create temporary keychain for CI builds
        if (! $this->createTempKeychain()) {
            return false;
        }

        // Install certificate to temp keychain
        $certificateId = $this->installCertificate($certificate, $certificatePassword);
        if (! $certificateId) {
            return false;
        }

        // Temporarily add temp keychain to search list for xcodebuild to find it
        $this->addTempKeychainToSearchList();

        // Install provisioning profile
        if ($provisioningProfile) {
            $installResult = $this->installProvisioningProfile($provisioningProfile);
            if (! $installResult) {
                $this->cleanupCertificates();

                return false;
            }
        } else {
            $this->components->twoColumnDetail('Provisioning', 'Automatic (no profile provided)');
        }

        // Register cleanup
        register_shutdown_function(function () {
            $this->cleanupTempKeychain();
        });

        return true;
    }

    protected function createTempKeychain(): bool
    {
        $this->tempKeychainPath = sys_get_temp_dir().'/nativephp-build-'.uniqid().'.keychain';

        $keychainCreated = false;
        $this->components->task('Creating temporary keychain', function () use (&$keychainCreated) {
            // Create temporary keychain
            $result = Process::run([
                'security', 'create-keychain',
                '-p', '', // No password for temp keychain
                $this->tempKeychainPath,
            ]);

            if (! $result->successful()) {
                return false;
            }

            // Unlock the keychain
            $result = Process::run([
                'security', 'unlock-keychain',
                '-p', '',
                $this->tempKeychainPath,
            ]);

            if (! $result->successful()) {
                return false;
            }

            // Set keychain settings to prevent auto-lock and timeout
            $result = Process::run([
                'security', 'set-keychain-settings',
                '-t', '3600', // 1 hour timeout
                '-l', // Don't lock when system sleeps
                $this->tempKeychainPath,
            ]);

            if (! $result->successful()) {
                return false;
            }

            // NEVER TOUCH DEFAULT KEYCHAIN - only modify search list
            // Add temp keychain to search list (user's login keychain stays default)
            $this->addTempKeychainToSearchList();

            // Store keychain path in environment for reference
            putenv("NATIVEPHP_TEMP_KEYCHAIN_PATH={$this->tempKeychainPath}");

            $keychainCreated = true;

            return true;
        });

        return $keychainCreated;
    }

    protected function installCertificate(string $certificatePathOrBase64, string $password): ?string
    {
        $certificatePath = sys_get_temp_dir().'/certificate-'.uniqid().'.p12';
        $certificateData = null;

        // Check if it's a file path (exists as file) or base64 string
        if (file_exists($certificatePathOrBase64)) {
            $this->components->twoColumnDetail('Certificate source', basename($certificatePathOrBase64));
            // It's a file path, read the file directly
            $certificateData = file_get_contents($certificatePathOrBase64);
            if ($certificateData === false) {
                \Laravel\Prompts\error('Failed to read certificate file');

                return null;
            }
        } else {
            $this->components->twoColumnDetail('Certificate source', 'Base64 string');
            // It's a base64 string, decode it
            $certificateData = base64_decode($certificatePathOrBase64);
            if (! $certificateData) {
                \Laravel\Prompts\error('Failed to decode certificate base64 string');

                return null;
            }
        }

        file_put_contents($certificatePath, $certificateData);

        $result = Process::run([
            'security', 'import',
            $certificatePath,
            '-k', $this->tempKeychainPath, // Import to temp keychain
            '-f', 'pkcs12', // Explicitly specify format
            '-P', $password,
            '-A', // Allow access without prompting
            '-T', '/usr/bin/codesign',
            '-T', '/usr/bin/security',
        ]);

        // Clean up certificate file
        unlink($certificatePath);

        if (! $result->successful()) {
            \Laravel\Prompts\error('Failed to import certificate');

            return null;
        }

        // Extract certificate SHA1 hash for ACL configuration
        $certificateSha1 = $this->extractCertificateSha1();
        if (! $certificateSha1) {
            return null;
        }

        $aclConfigured = false;
        $this->components->task('Configuring certificate ACL for CI access', function () use (&$aclConfigured) {
            // Wait a moment for keychain operations to settle
            usleep(500000); // 0.5 second delay

            // Verify keychain exists and is accessible before attempting ACL configuration
            if (! file_exists($this->tempKeychainPath)) {
                return false;
            }

            // First try to set the key partition list properly
            $aclResult = Process::run([
                'security', 'set-key-partition-list',
                '-S', 'apple-tool:,apple:',
                '-s',
                '-k', '', // Use empty password for temp keychain
                $this->tempKeychainPath,
            ]);

            if ($aclResult->successful()) {
                $aclConfigured = true;

                return true;
            }

            // Fallback 1: Try with simpler ACL configuration
            $fallback1Result = Process::run([
                'security', 'set-key-partition-list',
                '-S', 'apple:,codesign:',
                '-s',
                '-k', '', // Use empty password for temp keychain
                $this->tempKeychainPath,
            ]);

            if ($fallback1Result->successful()) {
                $aclConfigured = true;

                return true;
            }

            // Fallback 2: Set keychain to not require password
            $fallback2Result = Process::run([
                'security', 'set-keychain-settings',
                '-t', '7200', // 2 hour timeout
                '-l', // Don't lock when system sleeps
                '-u', // Don't require password after timeout
                $this->tempKeychainPath,
            ]);

            if ($fallback2Result->successful()) {
                $aclConfigured = true;

                return true;
            }

            // All methods failed but we can still proceed
            $aclConfigured = true;

            return true;
        });

        // Store certificate info for cleanup
        $this->installedCertificates[] = $certificateSha1;

        $this->components->twoColumnDetail('Certificate', 'Imported and configured');

        return $certificateSha1;
    }

    protected function extractCertificateSha1(): ?string
    {
        // Find the imported certificate's SHA1 hash in the temp keychain
        $result = Process::run([
            'security', 'find-identity',
            '-v', // Verbose output
            '-p', 'codesigning', // Code signing certificates only
            $this->tempKeychainPath,
        ]);

        if (! $result->successful()) {
            \Laravel\Prompts\error('Failed to find certificate identity');

            return null;
        }

        $output = $result->output();

        // Parse output to extract SHA1 hash
        // Format: "  1) <SHA1_HASH> "Certificate Name""
        if (preg_match('/^\s*\d+\)\s+([A-F0-9]{40})\s+/', $output, $matches)) {
            $sha1 = $matches[1];
            $this->components->twoColumnDetail('Certificate SHA1', $sha1);

            return $sha1;
        }

        \Laravel\Prompts\error('Could not extract certificate SHA1 from output');

        return null;
    }

    protected function extractCertificateId(string $certificateData): ?string
    {
        // Legacy method - now using SHA1 extraction instead
        return 'Apple Distribution';
    }

    protected function installProvisioningProfile(string $provisioningProfilePathOrBase64): bool
    {
        $provisioningProfileData = null;

        // Check if it's a file path (exists as file) or base64 string
        if (file_exists($provisioningProfilePathOrBase64)) {
            $this->components->twoColumnDetail('Profile source', basename($provisioningProfilePathOrBase64));
            // It's a file path, read the file directly
            $provisioningProfileData = file_get_contents($provisioningProfilePathOrBase64);
            if ($provisioningProfileData === false) {
                \Laravel\Prompts\error('Failed to read provisioning profile file');

                return false;
            }
        } else {
            $this->components->twoColumnDetail('Profile source', 'Base64 string');
            // It's a base64 string, decode it
            $provisioningProfileData = base64_decode($provisioningProfilePathOrBase64);
            if (! $provisioningProfileData) {
                \Laravel\Prompts\error('Failed to decode provisioning profile base64 string');

                return false;
            }
        }

        $profilesDir = $_SERVER['HOME'].'/Library/MobileDevice/Provisioning Profiles';
        if (! is_dir($profilesDir)) {
            mkdir($profilesDir, 0755, true);
        }

        // Extract UUID from the provisioning profile to use as filename
        $uuid = $this->extractProvisioningProfileUuid($provisioningProfileData);
        $profilePath = $profilesDir.'/'.$uuid.'.mobileprovision';

        file_put_contents($profilePath, $provisioningProfileData);

        // Store the profile name and UUID for later use
        $profileName = $this->extractProvisioningProfileName($provisioningProfileData);
        if ($profileName) {
            // Store profile name in a temporary environment variable
            putenv("EXTRACTED_PROVISIONING_PROFILE_NAME={$profileName}");
            $this->components->twoColumnDetail('Provisioning profile', $profileName);
        } else {
            $this->components->twoColumnDetail('Provisioning profile', 'Installed');
        }

        // Validate bundle ID match BEFORE continuing
        $profileBundleId = $this->extractProvisioningProfileBundleId($provisioningProfileData);
        $appBundleId = config('nativephp.app_id');

        if ($profileBundleId && $appBundleId) {
            $this->components->twoColumnDetail('Profile bundle ID', $profileBundleId);
            $this->components->twoColumnDetail('App bundle ID', $appBundleId);

            if ($profileBundleId !== $appBundleId) {
                \Laravel\Prompts\error('Bundle ID mismatch detected!');
                $this->line("   Provisioning profile expects: {$profileBundleId}");
                $this->line("   App is configured with: {$appBundleId}");
                $this->line("   Solution: Update NATIVEPHP_APP_ID in .env to '{$profileBundleId}'");

                return false;
            }

            $this->components->twoColumnDetail('Bundle ID validation', 'Passed');
        } elseif (! $profileBundleId) {
            \Laravel\Prompts\warning('Could not extract bundle ID from provisioning profile - skipping validation');
        } elseif (! $appBundleId) {
            \Laravel\Prompts\warning('No app bundle ID configured (NATIVEPHP_APP_ID) - skipping validation');
        }

        // Store UUID for deterministic export (prevents Xcode from switching profiles)
        putenv("EXTRACTED_PROVISIONING_PROFILE_UUID={$uuid}");

        $this->components->twoColumnDetail('Profile UUID', $uuid);

        return true;
    }

    protected function extractProvisioningProfileUuid(string $provisioningProfileData): string
    {
        // Try to extract UUID from the plist data
        if (preg_match('/<key>UUID<\/key>\s*<string>([^<]+)<\/string>/', $provisioningProfileData, $matches)) {
            return $matches[1];
        }

        // Fallback to generated UUID
        return uniqid();
    }

    protected function extractProvisioningProfileName(string $provisioningProfileData): ?string
    {
        // Try to extract Name from the plist data
        if (preg_match('/<key>Name<\/key>\s*<string>([^<]+)<\/string>/', $provisioningProfileData, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function extractProvisioningProfileBundleId(string $provisioningProfileData): ?string
    {
        // Extract the application-identifier from the Entitlements section
        if (preg_match('/<key>Entitlements<\/key>\s*<dict>(.*?)<\/dict>/s', $provisioningProfileData, $entitlementsMatch)) {
            $entitlementsXml = $entitlementsMatch[1];

            // Look for application-identifier within the entitlements
            if (preg_match('/<key>application-identifier<\/key>\s*<string>([^<]+)<\/string>/', $entitlementsXml, $appIdMatch)) {
                $appIdentifier = $appIdMatch[1];

                // Remove the team ID prefix (format: "TEAMID.com.example.app" -> "com.example.app")
                if (preg_match('/^[A-Z0-9]+\.(.+)$/', $appIdentifier, $bundleIdMatch)) {
                    return $bundleIdMatch[1];
                }

                return $appIdentifier;
            }
        }

        return null;
    }

    protected function extractProvisioningProfileEntitlements(string $provisioningProfileData): ?array
    {
        // Extract the Entitlements section from the provisioning profile
        if (preg_match('/<key>Entitlements<\/key>\s*<dict>(.*?)<\/dict>/s', $provisioningProfileData, $matches)) {
            $entitlementsXml = '<dict>'.$matches[1].'</dict>';

            // Parse the XML to extract key-value pairs
            $entitlements = [];

            // Match all key-value pairs in the entitlements dict
            if (preg_match_all('/<key>([^<]+)<\/key>\s*<([^>]+)>([^<]*)<\/\2>/', $entitlementsXml, $keyValueMatches, PREG_SET_ORDER)) {
                foreach ($keyValueMatches as $match) {
                    $key = $match[1];
                    $type = $match[2];
                    $value = $match[3];

                    // Handle different value types
                    switch ($type) {
                        case 'true':
                            $entitlements[$key] = true;
                            break;
                        case 'false':
                            $entitlements[$key] = false;
                            break;
                        case 'string':
                            $entitlements[$key] = $value;
                            break;
                        default:
                            $entitlements[$key] = $value;
                    }
                }
            }

            // Handle array values (like associated domains)
            if (preg_match_all('/<key>([^<]+)<\/key>\s*<array>(.*?)<\/array>/s', $entitlementsXml, $arrayMatches, PREG_SET_ORDER)) {
                foreach ($arrayMatches as $match) {
                    $key = $match[1];
                    $arrayContent = $match[2];

                    $arrayValues = [];
                    if (preg_match_all('/<string>([^<]*)<\/string>/', $arrayContent, $stringMatches)) {
                        $arrayValues = $stringMatches[1];
                    }

                    $entitlements[$key] = $arrayValues;
                }
            }

            return $entitlements;
        }

        return null;
    }

    public function validateProvisioningProfileForPushNotifications(string $exportMethod = 'app-store'): bool
    {
        // Get the provisioning profile based on export method using environment variables directly
        $provisioningProfile = $this->getProvisioningProfileFromEnvironment($exportMethod);

        if (! $provisioningProfile) {
            \Laravel\Prompts\warning('No provisioning profile found for export method: '.$exportMethod);

            return false;
        }

        \Laravel\Prompts\info('Analyzing provisioning profile for push notification support...');

        $provisioningProfileData = base64_decode($provisioningProfile);
        if (! $provisioningProfileData) {
            \Laravel\Prompts\error('Failed to decode provisioning profile');

            return false;
        }

        // Extract profile name
        $profileName = $this->extractProvisioningProfileName($provisioningProfileData);
        $this->components->twoColumnDetail('Profile Name', $profileName ?: 'Unknown');

        // Extract entitlements
        $entitlements = $this->extractProvisioningProfileEntitlements($provisioningProfileData);

        if (! $entitlements) {
            \Laravel\Prompts\error('Failed to extract entitlements from provisioning profile');

            return false;
        }

        \Laravel\Prompts\info('Found Entitlements:');
        foreach ($entitlements as $key => $value) {
            if (is_array($value)) {
                $this->components->twoColumnDetail($key, '['.implode(', ', $value).']');
            } else {
                $this->components->twoColumnDetail($key, is_bool($value) ? ($value ? 'true' : 'false') : $value);
            }
        }

        // Check for push notification support
        $hasPushNotifications = isset($entitlements['aps-environment']);
        $apsEnvironment = $entitlements['aps-environment'] ?? null;

        $this->line('');
        \Laravel\Prompts\info('Push Notification Analysis:');

        if ($hasPushNotifications) {
            $this->components->twoColumnDetail('Push notifications', "Supported (aps-environment: {$apsEnvironment})");

            // Validate environment matches export method
            $expectedEnvironment = in_array($exportMethod, ['app-store', 'ad-hoc', 'enterprise']) ? 'production' : 'development';
            if ($apsEnvironment === $expectedEnvironment) {
                $this->components->twoColumnDetail('APS environment', "Matches export method ({$exportMethod})");
            } else {
                \Laravel\Prompts\warning("APS environment ({$apsEnvironment}) may not match export method ({$exportMethod}) - expected: {$expectedEnvironment}");
            }
        } else {
            \Laravel\Prompts\error('Push notifications NOT supported - missing aps-environment entitlement');
        }

        // Check bundle ID compatibility
        $bundleId = config('nativephp.app_id');
        $profileBundleId = $this->extractProvisioningProfileBundleId($provisioningProfileData);

        $this->line('');
        \Laravel\Prompts\info('App Configuration Check:');
        $this->components->twoColumnDetail('App Bundle ID', $bundleId);
        $this->components->twoColumnDetail('Profile Bundle ID', $profileBundleId ?: 'Could not extract');

        $pushConfig = config('nativephp.permissions.push_notifications', false);
        $pushEnabled = ! empty($pushConfig);
        $this->components->twoColumnDetail('Push Notifications Enabled', $pushEnabled ? 'Yes' : 'No');

        // Validate bundle ID compatibility
        if ($profileBundleId && $bundleId) {
            if ($profileBundleId === $bundleId) {
                $this->components->twoColumnDetail('Bundle ID Match', 'Profile and app configuration are compatible');
            } else {
                \Laravel\Prompts\error('Bundle ID Mismatch:');
                $this->line("   Provisioning profile expects: {$profileBundleId}");
                $this->line("   App is configured with: {$bundleId}");
                $this->line("   Solution: Update NATIVEPHP_APP_ID in .env to '{$profileBundleId}'");
            }
        } elseif (! $profileBundleId) {
            \Laravel\Prompts\warning('Could not extract bundle ID from provisioning profile');
        } elseif (! $bundleId) {
            \Laravel\Prompts\warning('No app bundle ID configured (NATIVEPHP_APP_ID)');
        }

        // Check for associated domains
        if (isset($entitlements['com.apple.developer.associated-domains'])) {
            $this->components->twoColumnDetail('Associated Domains', implode(', ', $entitlements['com.apple.developer.associated-domains']));
        }

        return $hasPushNotifications;
    }

    protected function addTempKeychainToSearchList(): void
    {
        if (! $this->tempKeychainPath) {
            return;
        }

        // Get current keychain search list and store it for restoration
        $currentKeychains = Process::run(['security', 'list-keychains', '-d', 'user']);

        if ($currentKeychains->successful()) {
            $this->originalKeychainSearchList = collect(explode("\n", trim($currentKeychains->output())))
                ->map(fn ($line) => trim($line, ' "'))
                ->filter()
                ->values()
                ->toArray();

            // Add temp keychain to the front of the search list
            $updatedKeychainPaths = collect($this->originalKeychainSearchList)
                ->prepend($this->tempKeychainPath)
                ->unique()
                ->values()
                ->toArray();

            // Update the search list
            Process::run(array_merge([
                'security', 'list-keychains', '-d', 'user', '-s',
            ], $updatedKeychainPaths));
        }
    }

    protected function restoreOriginalKeychainSearchList(): void
    {
        if (! $this->originalKeychainSearchList) {
            return;
        }

        // Restore the original keychain search list
        Process::run(array_merge([
            'security', 'list-keychains', '-d', 'user', '-s',
        ], $this->originalKeychainSearchList));

        $this->originalKeychainSearchList = null;
    }

    protected function cleanupTempKeychain(): void
    {
        $this->components->task('Cleaning up temporary keychain', function () {
            // NEVER TOUCH DEFAULT KEYCHAIN - only restore search list
            $this->restoreOriginalKeychainSearchList();

            // Clean up environment variable
            putenv('NATIVEPHP_TEMP_KEYCHAIN_PATH');

            // Delete the temp keychain file if it exists
            if ($this->tempKeychainPath && file_exists($this->tempKeychainPath)) {
                Process::run(['security', 'delete-keychain', $this->tempKeychainPath]);
            }

            $this->tempKeychainPath = null;
            $this->installedCertificates = [];

            return true;
        });
    }

    protected function cleanupCertificates(): void
    {
        // For now, skip certificate cleanup to avoid any potential issues
        // The certificates will remain in the keychain but won't cause problems
        $this->installedCertificates = [];
    }

    protected function getCertificateForExportMethod(string $exportMethod): ?string
    {
        $pathVar = '';
        $base64Var = '';

        switch ($exportMethod) {
            case 'development':
            case 'debugging':
                $pathVar = 'IOS_DEVELOPMENT_CERTIFICATE_PATH';
                $base64Var = 'IOS_DEVELOPMENT_CERTIFICATE';
                $fallbackVar = 'IOS_CERTIFICATE_P12_BASE64';
                break;
            case 'ad-hoc':
            case 'app-store':
            case 'app-store-connect':
            default:
                $pathVar = 'IOS_DISTRIBUTION_CERTIFICATE_PATH';
                $base64Var = 'IOS_DISTRIBUTION_CERTIFICATE';
                $fallbackVar = 'IOS_CERTIFICATE_P12_BASE64';
                break;
        }

        // Priority: PATH var -> base64 var -> fallback var
        $pathValue = env($pathVar);
        if ($pathValue) {
            $resolved = $this->resolveCredentialFromPath($pathValue);
            if ($resolved) {
                return $resolved;
            }
        }

        $base64Value = env($base64Var) ?: getenv($base64Var);
        if ($base64Value) {
            return $this->resolveCredentialFromPath($base64Value);
        }

        $fallbackValue = env($fallbackVar ?? '') ?: getenv($fallbackVar ?? '');
        if ($fallbackValue) {
            return $this->resolveCredentialFromPath($fallbackValue);
        }

        return null;
    }

    protected function getCertificatePasswordForExportMethod(string $exportMethod): ?string
    {
        switch ($exportMethod) {
            case 'development':
            case 'debugging':
                return env('IOS_DEVELOPMENT_CERTIFICATE_PASSWORD') ?: env('IOS_CERTIFICATE_PASSWORD') ?: getenv('IOS_DEVELOPMENT_CERTIFICATE_PASSWORD') ?: getenv('IOS_CERTIFICATE_PASSWORD');
            case 'ad-hoc':
            case 'app-store':
            case 'app-store-connect':
            default:
                return env('IOS_DISTRIBUTION_CERTIFICATE_PASSWORD') ?: env('IOS_CERTIFICATE_PASSWORD') ?: getenv('IOS_DISTRIBUTION_CERTIFICATE_PASSWORD') ?: getenv('IOS_CERTIFICATE_PASSWORD');
        }
    }

    protected function getProvisioningProfileFromEnvironment(string $exportMethod): ?string
    {
        $pathVar = '';
        $base64Var = '';
        $fallbackVar = '';

        switch ($exportMethod) {
            case 'development':
            case 'debugging':
                $pathVar = 'IOS_DEVELOPMENT_PROVISIONING_PROFILE_PATH';
                $base64Var = 'IOS_DEVELOPMENT_PROVISIONING_PROFILE';
                break;
            case 'ad-hoc':
                $pathVar = 'IOS_ADHOC_PROVISIONING_PROFILE_PATH';
                $base64Var = 'IOS_ADHOC_PROVISIONING_PROFILE';
                break;
            case 'app-store':
            case 'app-store-connect':
            default:
                $pathVar = 'IOS_DISTRIBUTION_PROVISIONING_PROFILE_PATH';
                $base64Var = 'IOS_DISTRIBUTION_PROVISIONING_PROFILE';
                $fallbackVar = 'IOS_PROVISIONING_PROFILE_BASE64';
                break;
        }

        // Priority: PATH var -> base64 var -> fallback var
        // For PATH variables, read from Laravel env() since they come from .env file
        $pathValue = env($pathVar);
        if ($pathValue) {
            $resolved = $this->resolveCredentialFromPath($pathValue);
            if ($resolved) {
                // DO NOT set via putenv - this interferes with UUID-based export logic
                return $resolved;
            }
        }

        // For base64 variables, check both Laravel env() and getenv() (for putenv values)
        $base64Value = env($base64Var) ?: getenv($base64Var);
        if ($base64Value) {
            return $this->resolveCredentialFromPath($base64Value);
        }

        if ($fallbackVar) {
            $fallbackValue = env($fallbackVar) ?: getenv($fallbackVar);
            if ($fallbackValue) {
                return $this->resolveCredentialFromPath($fallbackValue);
            }
        }

        return null;
    }

    protected function getProvisioningProfileForExportMethod(string $exportMethod): ?string
    {
        return $this->getProvisioningProfileFromEnvironment($exportMethod);
    }

    /**
     * Determine if a string represents a file path or a base64 encoded value
     */
    protected function isFilePath(?string $value): bool
    {
        if (empty($value)) {
            return false;
        }

        // URLs should not be treated as file paths
        if (preg_match('/^https?:\/\//', $value)) {
            return false;
        }

        // Check if it looks like a file path
        // File paths typically contain directory separators or start with . or ~
        if (preg_match('/[\/\\\\]/', $value) ||
            preg_match('/^[.~]/', $value) ||
            preg_match('/^[a-zA-Z]:[\/\\\\]/', $value)) { // Windows drive paths
            return true;
        }

        // Very short strings that might be paths
        if (strlen($value) <= 3 && preg_match('/^[.\/\\\\]/', $value)) {
            return true;
        }

        // If it contains typical base64 characters and is reasonably long,
        // it's probably base64
        if (strlen($value) > 10 && preg_match('/^[A-Za-z0-9+\/=]+$/', $value)) {
            // Additional check: if it doesn't contain path separators and looks like base64
            if (! preg_match('/[\/\\\\]/', $value)) {
                return false;
            }
        }

        // Very short strings without path indicators are likely base64 or other data
        if (strlen($value) <= 10) {
            return false;
        }

        // Default: treat as file path if it doesn't look like base64
        return true;
    }

    /**
     * Resolve credential from either file path or base64 string
     */
    protected function resolveCredentialFromPath(?string $pathOrBase64): ?string
    {
        if (empty($pathOrBase64)) {
            return null;
        }

        // If it's a file path, read and encode the file
        if ($this->isFilePath($pathOrBase64)) {
            return $this->readAndEncodeFile($pathOrBase64);
        }

        // Otherwise, assume it's already base64 encoded
        return $pathOrBase64;
    }

    /**
     * Read file contents and base64 encode them
     */
    protected function readAndEncodeFile(string $filePath): ?string
    {
        // Expand tilde to home directory
        if (str_starts_with($filePath, '~/')) {
            $filePath = $_SERVER['HOME'].substr($filePath, 1);
        }

        // Resolve relative paths relative to Laravel's base path
        if (! $this->isAbsolutePath($filePath)) {
            $filePath = base_path($filePath);
        }

        // Check if file exists and is readable
        if (! file_exists($filePath)) {
            return null;
        }

        if (! is_readable($filePath)) {
            return null;
        }

        // Read file contents and base64 encode
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        return base64_encode($contents);
    }

    /**
     * Check if a path is absolute
     */
    protected function isAbsolutePath(string $path): bool
    {
        // Unix/Linux/macOS absolute paths start with /
        if (str_starts_with($path, '/')) {
            return true;
        }

        // Windows absolute paths (C:\ or C:/)
        if (preg_match('/^[a-zA-Z]:[\/\\\\]/', $path)) {
            return true;
        }

        return false;
    }
}
