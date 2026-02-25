<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Command\Command;

use function Laravel\Prompts\outro;

trait PackagesIos
{
    use ManagesIosSigning;

    protected ?string $temporaryKeychainPath = null;

    protected const NFC_ENTITLEMENTS_KEY = 'com.apple.developer.nfc.readersession.formats';

    protected function buildIos(?array $iosSigningConfig = null): void
    {
        // If test-upload flag is set, just test upload without building
        if ($this->option('test-upload')) {
            $this->testAppStoreUpload();

            return;
        }

        $iosPath = base_path('nativephp/ios');

        if (! is_dir($iosPath)) {
            \Laravel\Prompts\error('No iOS project found at [nativephp/ios].');
            \Laravel\Prompts\note('Run `php artisan native:install` first.');

            return;
        }

        if (PHP_OS_FAMILY !== 'Darwin') {
            \Laravel\Prompts\error('iOS builds are only supported on macOS.');

            return;
        }

        // Validate version for release builds
        $this->validateAppVersion($this->option('build-type'));

        // Check for required tools
        if (! $this->checkIosTools()) {
            return;
        }

        // Validate Team ID is provided (required for CI)
        try {
            $this->getTeamId($iosSigningConfig);
        } catch (\Exception $e) {
            return;
        }

        $exportMethod = $this->option('export-method') ?: 'app-store';
        if (! $this->setupSigningCredentials($exportMethod, $iosSigningConfig)) {
            \Laravel\Prompts\error('Failed to setup signing credentials');

            return;
        }

        $archivePath = base_path('nativephp/ios/build/NativePHP.xcarchive');

        if ($this->option('rebuild') && file_exists($archivePath)) {
            $this->components->task('Removing existing archive', fn () => Process::run(['rm', '-rf', $archivePath]));
        }

        if (! file_exists($archivePath)) {
            if (! $this->buildArchive()) {
                return;
            }
        } else {
            $this->components->twoColumnDetail('Archive', 'Using existing');
        }

        if (! $this->configureAppSettings($archivePath)) {
            \Laravel\Prompts\warning('Failed to configure app settings - you may need to answer encryption questions in App Store Connect');
        }

        // Export IPA first (needed for validation)
        $ipaPath = $this->exportArchive($archivePath);
        if (! $ipaPath) {
            return;
        }

        // Validate IPA if requested (using exported IPA, not archive)
        if ($this->option('validate-only')) {
            $this->validateIpa($ipaPath);

            return;
        }

        // NEW: Verify NFC entitlements are ["TAG"] only before any upload
        if (! $this->verifyIpaNfcEntitlements($ipaPath)) {
            return;
        }

        // Upload to App Store Connect if requested
        if ($this->option('upload-to-app-store')) {
            $this->uploadToAppStore($ipaPath, $iosSigningConfig);
        }

        $this->handleBuildArtifacts($ipaPath);

        // Clean up CI-specific provisioning profile settings to ensure device runs work
        $this->call('native:build', ['--cleanup-provisioning-profile' => true]);

        outro('iOS app packaged successfully');
    }

    protected function buildArchive(): bool
    {
        // Only clear caches when explicitly requested or in CI/packaging scenarios
        $shouldCleanCaches = $this->option('clean-caches') ||
                           $this->option('upload-to-app-store') ||
                           getenv('CI') === 'true' ||
                           getenv('GITHUB_ACTIONS') === 'true';

        if ($shouldCleanCaches) {
            if (! $this->prepareBuildEnvironment()) {
                \Laravel\Prompts\error('Failed to prepare build environment');

                return false;
            }
        }

        // Set export method environment variable for the build command
        $exportMethod = $this->option('export-method') ?: 'app-store';
        putenv("NATIVEPHP_EXPORT_METHOD={$exportMethod}");

        // Pass through relevant options to build command
        $buildOptions = ['--release' => true];

        // Pass through App Store options
        if ($this->option('upload-to-app-store')) {
            $buildOptions['--upload-to-app-store'] = true;
        }
        if ($this->option('jump-by')) {
            $buildOptions['--jump-by'] = $this->option('jump-by');
        }
        if ($this->option('api-key-path')) {
            $buildOptions['--api-key-path'] = $this->option('api-key-path');
        }
        if ($this->option('api-key-id')) {
            $buildOptions['--api-key-id'] = $this->option('api-key-id');
        }
        if ($this->option('api-issuer-id')) {
            $buildOptions['--api-issuer-id'] = $this->option('api-issuer-id');
        }

        $result = $this->call('native:build', $buildOptions);

        // Clean up environment variable
        putenv('NATIVEPHP_EXPORT_METHOD');

        return $result === Command::SUCCESS;
    }

    /**
     * Prepare build environment by clearing caches if needed
     */
    protected function prepareBuildEnvironment(): bool
    {
        $iosPath = base_path('nativephp/ios');

        // Check if we should clean caches (CI environment or --clean-caches flag)
        $shouldCleanCaches = $this->option('clean-caches') ||
                           getenv('CI') === 'true' ||
                           getenv('GITHUB_ACTIONS') === 'true';

        if ($shouldCleanCaches) {
            $derivedDataPath = $_SERVER['HOME'].'/Library/Developer/Xcode/DerivedData';
            if (is_dir($derivedDataPath)) {
                Process::run(['rm', '-rf', $derivedDataPath]);
            }

            $spmCachePaths = [
                $_SERVER['HOME'].'/Library/Caches/org.swift.swiftpm',
                $_SERVER['HOME'].'/Library/org.swift.swiftpm',
            ];

            foreach ($spmCachePaths as $cachePath) {
                if (is_dir($cachePath)) {
                    Process::run(['rm', '-rf', $cachePath]);
                }
            }

            $result = Process::path($iosPath)
                ->timeout(300)
                ->run(['xcodebuild', '-resolvePackageDependencies']);

            if (! $result->successful()) {
                \Laravel\Prompts\error('Failed to resolve package dependencies');
                $this->newLine();
                $this->line('<fg=red>Error output:</>');
                $this->line($result->errorOutput() ?: $result->output());
                $this->newLine();

                return false;
            }
        }

        return true;
    }

    protected function validateIpa(string $ipaPath): bool
    {
        $valid = false;

        $this->components->task('Validating IPA', function () use ($ipaPath, &$valid) {
            $result = Process::run([
                'xcrun', 'altool',
                '--validate-app',
                '--file', $ipaPath,
                '--type', 'ios',
                '--output-format', 'xml',
            ]);

            $valid = $result->successful();

            return $valid;
        });

        return $valid;
    }

    protected function exportArchive(string $archivePath): ?string
    {
        $manualIpaPath = null;

        $this->components->task('Exporting IPA (manual)', function () use ($archivePath, &$manualIpaPath) {
            $manualIpaPath = $this->exportArchiveManually($archivePath);

            return $manualIpaPath !== null;
        });

        if ($manualIpaPath) {
            return $manualIpaPath;
        }

        \Laravel\Prompts\warning('Manual export failed, falling back to Xcode export');

        return $this->exportArchiveWithXcode($archivePath);
    }

    protected function exportArchiveWithXcode(string $archivePath): ?string
    {
        $basePath = base_path('nativephp/ios');
        $exportPath = $basePath.'/build/export';
        $exportOptionsPath = $this->createExportOptions($basePath);

        if (is_dir($exportPath)) {
            Process::run('rm -rf '.escapeshellarg($exportPath));
        }

        $result = Process::path($basePath)
            ->timeout(600)
            ->run([
                'xcodebuild',
                '-exportArchive',
                '-archivePath', $archivePath,
                '-exportPath', $exportPath,
                '-exportOptionsPlist', $exportOptionsPath,
                // Remove -allowProvisioningUpdates to prevent Xcode from overriding our custom entitlements
            ]);

        if (! $result->successful()) {
            \Laravel\Prompts\error('IPA export failed');
            $this->newLine();
            $this->line('<fg=red>Export error output:</>');
            $this->line($result->errorOutput() ?: $result->output());
            $this->newLine();

            return null;
        }

        $ipaFiles = glob($exportPath.'/*.ipa');
        if (empty($ipaFiles)) {
            \Laravel\Prompts\error('No IPA file was generated');

            return null;
        }

        $ipaPath = $ipaFiles[0];

        if (! $this->verifyIpaCodeSignature($ipaPath)) {
            \Laravel\Prompts\error('IPA verification failed - unsigned executables detected');

            return null;
        }

        return $ipaPath;
    }

    protected function exportArchiveManually(string $archivePath): ?string
    {
        $basePath = base_path('nativephp/ios');
        $exportPath = $basePath.'/build/export';

        if (is_dir($exportPath)) {
            Process::run('rm -rf '.escapeshellarg($exportPath));
        }

        if (! mkdir($exportPath, 0755, true)) {
            return null;
        }

        $archiveAppPath = $archivePath.'/Products/Applications/NativePHP.app';
        if (! is_dir($archiveAppPath)) {
            return null;
        }

        $payloadDir = $exportPath.'/Payload';
        $appBundleDestination = $payloadDir.'/NativePHP.app';

        if (! mkdir($payloadDir, 0755, true)) {
            return null;
        }

        $copyResult = Process::run([
            'cp', '-R', $archiveAppPath, $appBundleDestination,
        ]);

        if (! $copyResult->successful()) {
            return null;
        }

        if (! $this->reSignAppBundlePreservingEntitlements($appBundleDestination)) {
            return null;
        }

        $ipaPath = $exportPath.'/NativePHP.ipa';
        $zipResult = Process::path($exportPath)->run([
            'zip', '-r', '-q', 'NativePHP.ipa', 'Payload',
        ]);

        if (! $zipResult->successful()) {
            return null;
        }

        if (! $this->verifyIpaCodeSignature($ipaPath)) {
            return null;
        }

        return $ipaPath;
    }

    /**
     * NEW: Re-sign app preserving entitlements from archived binary, coercing NFC formats to ["TAG"].
     */
    protected function reSignAppBundlePreservingEntitlements(string $appBundlePath): bool
    {
        if (! $this->replaceEmbeddedProvisioningProfile($appBundlePath)) {
            return false;
        }

        $signingIdentity = $this->getCiSigningIdentity();
        if (! $signingIdentity) {
            return false;
        }

        // Extract current entitlements from archived binary
        $binary = trim(shell_exec(
            '/usr/libexec/PlistBuddy -c "Print :CFBundleExecutable" '.escapeshellarg($appBundlePath.'/Info.plist')
        ));
        $binaryPath = $appBundlePath.'/'.$binary;

        // Extract entitlements to a temp file (avoids deprecated ':- ' syntax warning)
        $tempEntitlementsFile = sys_get_temp_dir().'/nativephp-entitlements-'.uniqid().'.xml';
        $result = Process::run([
            'codesign', '-d', '--entitlements', $tempEntitlementsFile, $binaryPath,
        ]);

        if (! $result->successful() || ! file_exists($tempEntitlementsFile)) {
            @unlink($tempEntitlementsFile);
            return false;
        }

        $entitlementsXml = file_get_contents($tempEntitlementsFile);
        @unlink($tempEntitlementsFile);

        if (! $entitlementsXml) {
            return false;
        }

        // Coerce NFC formats to ["TAG"] if the key exists
        $entitlementsXml = $this->coerceNfcFormatsToTag($entitlementsXml);

        $entitlementsPath = $appBundlePath.'/Preserved.entitlements';
        if (file_put_contents($entitlementsPath, $entitlementsXml) === false) {
            return false;
        }

        if (! $this->signEmbeddedBundles($appBundlePath, $signingIdentity)) {
            return false;
        }

        $result = Process::run([
            'codesign', '--force', '--sign', $signingIdentity,
            '--entitlements', $entitlementsPath, '--timestamp', '--options', 'runtime',
            $appBundlePath,
        ]);
        if (! $result->successful()) {
            return false;
        }

        $verify = Process::run(['codesign', '--verify', '--deep', '--strict', $appBundlePath]);
        if (! $verify->successful()) {
            return false;
        }

        return true;
    }

    protected function extractAndValidateEntitlements(string $appBundlePath): ?array
    {
        // Get the provisioning profile UUID that was used during build
        $provisioningProfileUuid = getenv('EXTRACTED_PROVISIONING_PROFILE_UUID');

        if (! $provisioningProfileUuid) {
            return null;
        }

        $homeDir = getenv('HOME');
        $profilePath = "{$homeDir}/Library/MobileDevice/Provisioning Profiles/{$provisioningProfileUuid}.mobileprovision";

        if (! file_exists($profilePath)) {
            return null;
        }

        $result = Process::run([
            'security', 'cms', '-D', '-i', $profilePath,
        ]);

        if (! $result->successful()) {
            return null;
        }

        $profileXml = $result->output();
        $profileData = simplexml_load_string($profileXml);

        if ($profileData === false) {
            return null;
        }

        $entitlements = $this->extractEntitlementsFromProfile($profileData);
        if ($entitlements === null) {
            return null;
        }

        return $entitlements;
    }

    protected function signEmbeddedBundles(string $appBundlePath, string $signingIdentity): bool
    {
        // Find all embedded bundles that need signing
        $bundleDirs = [
            $appBundlePath.'/Frameworks',
            $appBundlePath.'/PlugIns',
            $appBundlePath.'/Extensions',
        ];

        foreach ($bundleDirs as $bundleDir) {
            if (! is_dir($bundleDir)) {
                continue;
            }

            $items = glob($bundleDir.'/*');
            if (! $items) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_dir($item)) {
                    continue;
                }

                $extension = pathinfo($item, PATHINFO_EXTENSION);
                if (in_array($extension, ['framework', 'bundle', 'app', 'xpc', 'plugin', 'appex'])) {
                    $result = Process::run([
                        'codesign',
                        '--force',
                        '--sign', $signingIdentity,
                        '--timestamp',
                        '--options', 'runtime',
                        $item,
                    ]);

                    if (! $result->successful()) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    protected function createExportOptions(string $basePath, ?array $iosSigningConfig = null): string
    {
        $exportMethod = $this->option('export-method');
        $appId = config('nativephp.app_id');

        // Handle deprecated "development" export method
        if ($exportMethod === 'development') {
            $exportMethod = 'debugging';
        }

        $teamId = $this->getTeamId($iosSigningConfig);
        $exportOptions = [
            'method' => $exportMethod,
            'uploadBitcode' => false,
            'uploadSymbols' => true,
            'compileBitcode' => false,
            'destination' => 'export',
            'teamID' => $teamId,
            'manageAppVersionAndBuildNumber' => false, // Prevent Xcode from making changes during export
        ];

        // Use automatic for debugging only; manual for everything else (app-store, ad-hoc, enterprise, etc.)
        if ($exportMethod === 'debugging') {
            $exportOptions['signingStyle'] = 'automatic';
        } else {
            $exportOptions['signingStyle'] = 'manual';
            // Use Apple Distribution for modern signing
            $exportOptions['signingCertificate'] = 'Apple Distribution';
        }

        if ($exportMethod !== 'debugging') {
            $uuid = getenv('EXTRACTED_PROVISIONING_PROFILE_UUID') ?: null;
            $name = $uuid ? null : $this->getProvisioningProfile($appId, $exportMethod);

            if ($uuid) {
                $exportOptions['provisioningProfiles'] = [$appId => $uuid];
                $this->components->twoColumnDetail('Provisioning profile', $uuid);
            } elseif ($name && $name !== '*') {
                $exportOptions['provisioningProfiles'] = [$appId => $name];
                $this->components->twoColumnDetail('Provisioning profile', $name);
            } else {
                \Laravel\Prompts\error('No deterministic provisioning profile available (UUID or explicit name)');
                throw new \Exception('Missing deterministic provisioning profile for distribution build');
            }
        }

        $plistPath = $basePath.'/build/ExportOptions.plist';
        $this->createPlistFile($plistPath, $exportOptions);

        return $plistPath;
    }

    protected function createPlistFile(string $path, array $data): void
    {
        $plist = new \DOMDocument('1.0', 'UTF-8');
        $plist->formatOutput = true;

        // Create the DOCTYPE manually using DOMImplementation
        $implementation = new \DOMImplementation;
        $doctype = $implementation->createDocumentType('plist', '-//Apple//DTD PLIST 1.0//EN', 'http://www.apple.com/DTDs/PropertyList-1.0.dtd');
        $plist->appendChild($doctype);

        $root = $plist->createElement('plist');
        $root->setAttribute('version', '1.0');
        $plist->appendChild($root);

        $dict = $plist->createElement('dict');
        $root->appendChild($dict);

        $this->addPlistElements($plist, $dict, $data);
        $plist->save($path);
    }

    // FIX: Properly serialize arrays (array vs dict)
    protected function addPlistElements(\DOMDocument $plist, \DOMElement $parent, array $data): void
    {
        foreach ($data as $key => $value) {
            $parent->appendChild($plist->createElement('key', $key));

            if (is_array($value)) {
                $isAssoc = array_keys($value) !== range(0, count($value) - 1);
                if ($isAssoc) {
                    $dict = $plist->createElement('dict');
                    $parent->appendChild($dict);
                    $this->addPlistElements($plist, $dict, $value);
                } else {
                    $array = $plist->createElement('array');
                    foreach ($value as $item) {
                        $array->appendChild($plist->createElement('string', htmlspecialchars((string) $item)));
                    }
                    $parent->appendChild($array);
                }
            } elseif (is_bool($value)) {
                $parent->appendChild($plist->createElement($value ? 'true' : 'false'));
            } else {
                $parent->appendChild($plist->createElement('string', htmlspecialchars((string) $value)));
            }
        }
    }

    protected function getTeamId(?array $iosSigningConfig = null): string
    {
        // Check signing config first (from flags)
        if ($iosSigningConfig && ! empty($iosSigningConfig['teamId'])) {
            return $iosSigningConfig['teamId'];
        }

        $teamId = config('nativephp.development_team');

        if (! $teamId) {
            // Check environment variable (required for CI)
            $teamId = getenv('IOS_TEAM_ID');
        }

        if (! $teamId) {
            \Laravel\Prompts\error('iOS Team ID is required for packaging');
            \Laravel\Prompts\note('Provide via --team-id flag, nativephp.development_team config, or IOS_TEAM_ID env var');
            throw new \Exception('iOS Team ID not provided');
        }

        return $teamId;
    }

    protected function getProvisioningProfile(string $appId, string $exportMethod): ?string
    {
        // Check for environment variable first
        $profileName = getenv('IOS_PROVISIONING_PROFILE_NAME');
        if ($profileName) {
            return $profileName;
        }

        // Check if we extracted a profile name during installation
        $extractedProfileName = getenv('EXTRACTED_PROVISIONING_PROFILE_NAME');
        if ($extractedProfileName) {
            return $extractedProfileName;
        }

        if (in_array($exportMethod, ['app-store', 'ad-hoc', 'enterprise'])) {
            return null;
        }

        // If provisioning profile is provided as base64, use a wildcard for manual signing (debugging only)
        $provisioningProfile = $this->getProvisioningProfileForExportMethod($exportMethod);
        if ($provisioningProfile) {
            // For manual signing, we'll use wildcard matching (debugging builds only)
            return '*';
        }

        // Fallback: try to find installed provisioning profiles
        $profilesDir = $_SERVER['HOME'].'/Library/MobileDevice/Provisioning Profiles';
        if (is_dir($profilesDir)) {
            $profiles = glob($profilesDir.'/*.mobileprovision');
            if (! empty($profiles)) {
                // Use the first available profile name (simplified)
                return basename($profiles[0], '.mobileprovision');
            }
        }

        return null;
    }

    protected function checkIosTools(): bool
    {
        $result = Process::run(['xcode-select', '--print-path']);
        if (! $result->successful()) {
            \Laravel\Prompts\error('Xcode command line tools not found');
            \Laravel\Prompts\note('Install Xcode and run: xcode-select --install');

            return false;
        }

        $result = Process::run(['which', 'xcodebuild']);
        if (! $result->successful()) {
            \Laravel\Prompts\error('xcodebuild not found. Please install Xcode.');

            return false;
        }

        return true;
    }

    protected function cleanBuildDirectory(string $basePath): void
    {
        $buildPath = $basePath.'/build';
        if (is_dir($buildPath)) {
            Process::run('rm -rf '.escapeshellarg($buildPath));
        }
    }

    protected function getProvisioningProfileForExportMethod(string $exportMethod): ?string
    {
        switch ($exportMethod) {
            case 'development':
            case 'debugging':
                // Try file path first, then fall back to base64
                $pathValue = env('IOS_DEVELOPMENT_PROVISIONING_PROFILE_PATH');
                if ($pathValue) {
                    $resolved = $this->resolveCredentialFromPath($pathValue);
                    if ($resolved) {
                        return $resolved;
                    }
                }

                return getenv('IOS_DEVELOPMENT_PROVISIONING_PROFILE');
            case 'ad-hoc':
                // Try file path first, then fall back to base64
                $pathValue = env('IOS_ADHOC_PROVISIONING_PROFILE_PATH');
                if ($pathValue) {
                    $resolved = $this->resolveCredentialFromPath($pathValue);
                    if ($resolved) {
                        return $resolved;
                    }
                }

                return getenv('IOS_ADHOC_PROVISIONING_PROFILE');
            case 'app-store':
            case 'app-store-connect':
            default:
                // Try file path first, then fall back to base64 variables
                $pathValue = env('IOS_DISTRIBUTION_PROVISIONING_PROFILE_PATH');
                if ($pathValue) {
                    $resolved = $this->resolveCredentialFromPath($pathValue);
                    if ($resolved) {
                        return $resolved;
                    }
                }

                // Try new distribution variable first, then fall back to legacy
                return getenv('IOS_DISTRIBUTION_PROVISIONING_PROFILE') ?: getenv('IOS_PROVISIONING_PROFILE_BASE64');
        }
    }

    protected function getAppStoreApiKey(): ?string
    {
        // Try file path approach first (newer approach)
        $apiKeyPath = env('APP_STORE_API_KEY_PATH');
        if ($apiKeyPath) {
            $resolved = $this->resolveCredentialFromPath($apiKeyPath);
            if ($resolved) {
                return $resolved;
            }
        }

        // Fall back to base64 from config (legacy approach)
        return config('nativephp.app_store_connect.api_key');
    }

    protected function replaceEmbeddedProvisioningProfile(string $appBundlePath): bool
    {
        // Get the UUID of the correct provisioning profile that was installed
        $correctProfileUuid = getenv('EXTRACTED_PROVISIONING_PROFILE_UUID');
        if (! $correctProfileUuid) {
            $exportMethod = $this->option('export-method') ?: 'app-store';
            if (in_array($exportMethod, ['app-store', 'ad-hoc', 'enterprise'])) {
                return false;
            }

            return true;
        }

        $homeDir = getenv('HOME');
        $correctProfilePath = "{$homeDir}/Library/MobileDevice/Provisioning Profiles/{$correctProfileUuid}.mobileprovision";

        if (! file_exists($correctProfilePath)) {
            return false;
        }

        $embeddedProfilePath = $appBundlePath.'/embedded.mobileprovision';

        if (! copy($correctProfilePath, $embeddedProfilePath)) {
            return false;
        }

        return true;
    }

    protected function uploadToAppStore(string $ipaPath, ?array $iosSigningConfig = null): void
    {
        if ($iosSigningConfig) {
            $apiKey = $this->resolveApiKeyFromPath($iosSigningConfig['apiKeyPath']);
            $apiKeyId = $iosSigningConfig['apiKeyId'];
            $apiIssuerId = $iosSigningConfig['apiIssuerId'];
        } else {
            // Fallback to existing config-based approach
            $apiKey = $this->getAppStoreApiKey();
            $apiKeyId = config('nativephp.app_store_connect.api_key_id');
            $apiIssuerId = config('nativephp.app_store_connect.api_issuer_id');
        }

        if (! $apiKey || ! $apiKeyId || ! $apiIssuerId) {
            \Laravel\Prompts\error('App Store Connect API credentials not configured');

            return;
        }

        // Create temporary API key file
        $tempApiKeyPath = $this->createTemporaryApiKeyFile($apiKey, $apiKeyId);
        if (! $tempApiKeyPath) {
            return;
        }

        try {
            $command = [
                'xcrun', 'altool',
                '--upload-app',
                '-f', $ipaPath,
                '-t', 'ios',
                '--apiKey', $apiKeyId,
                '--apiIssuer', $apiIssuerId,
                '--output-format', 'json',
            ];

            $result = Process::timeout(600)
                ->env([
                    'API_PRIVATE_KEYS_DIR' => dirname($tempApiKeyPath),
                ])
                ->run($command);

            $stdout = $result->output();
            $stderr = $result->errorOutput();
            $allOutput = $stdout."\n".$stderr;
            $jsonResponse = $stdout ? json_decode($stdout, true) : null;

            // Check for product-errors even if exit code is 0 (Apple's tool is buggy)
            $hasProductErrors = $jsonResponse && ! empty($jsonResponse['product-errors']);

            // altool can return exit code 0 while still failing — check raw output for failure indicators
            $hasFailureInOutput = str_contains($allOutput, 'Failed to upload package')
                || str_contains($allOutput, 'Upload failed')
                || str_contains($allOutput, 'ERROR ITMS-')
                || str_contains($allOutput, 'error uploading');

            if ($result->successful() && ! $hasProductErrors && ! $hasFailureInOutput) {
                \Laravel\Prompts\info('Successfully uploaded to App Store Connect');
            } else {
                \Laravel\Prompts\error('Upload to App Store Connect failed');

                if ($hasProductErrors) {
                    foreach ($jsonResponse['product-errors'] as $error) {
                        $this->newLine();
                        $this->line('<fg=red>Error: '.($error['message'] ?? 'Unknown error').'</>');

                        // Extract the actual failure reason from nested user-info
                        $reason = $error['user-info']['NSLocalizedFailureReason'] ?? null;
                        if ($reason) {
                            $this->line('<fg=yellow>'.$reason.'</>');
                        }
                    }
                }

                // Always show raw output when upload fails so the reason isn't swallowed
                if ($stderr) {
                    $this->newLine();
                    $this->line('<fg=yellow>stderr:</>');
                    $this->line($stderr);
                }
                if ($stdout) {
                    $this->newLine();
                    $this->line('<fg=yellow>stdout:</>');
                    $this->line($stdout);
                }
            }

        } finally {
            // Clean up temporary API key file
            if (file_exists($tempApiKeyPath)) {
                unlink($tempApiKeyPath);
            }
        }
    }

    protected function createTemporaryApiKeyFile(string $apiKey, string $apiKeyId): ?string
    {
        try {
            $apiKeyContent = base64_decode($apiKey);
            if ($apiKeyContent === false) {
                return null;
            }

            $tempDir = sys_get_temp_dir().'/nativephp-upload';
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0700, true);
            }

            $tempApiKeyPath = $tempDir."/AuthKey_{$apiKeyId}.p8";
            if (file_put_contents($tempApiKeyPath, $apiKeyContent) === false) {
                return null;
            }

            chmod($tempApiKeyPath, 0600);

            return $tempApiKeyPath;

        } catch (\Exception $e) {
            return null;
        }
    }

    public function testAppStoreUpload(?string $ipaPath = null): void
    {
        $ipaPath = $ipaPath ?: base_path('nativephp/ios/build/export/NativePHP.ipa');

        if (! file_exists($ipaPath)) {
            \Laravel\Prompts\error("IPA not found at: {$ipaPath}");

            return;
        }

        $this->components->twoColumnDetail('Testing upload', $ipaPath);
        $this->uploadToAppStore($ipaPath);
    }

    /**
     * Verify and fix archive code signature for CI environments
     * This method ensures all executables are properly signed according to Apple's requirements
     */
    protected function verifyAndFixArchiveCodeSignature(string $archivePath): bool
    {
        $appBundlePath = $archivePath.'/Products/Applications/NativePHP.app';

        if (! is_dir($appBundlePath)) {
            return false;
        }

        if (! $this->fixEntitlementsForProvisioningProfile($appBundlePath)) {
            return false;
        }

        $signingIdentity = $this->getCiSigningIdentity();
        if (! $signingIdentity) {
            return false;
        }

        if (! $this->signNestedComponents($appBundlePath, $signingIdentity)) {
            return false;
        }

        if (! $this->signAppBundle($appBundlePath, $signingIdentity)) {
            return false;
        }

        return true;
    }

    /**
     * Get CI signing identity from environment or keychain
     */
    protected function getCiSigningIdentity(): ?string
    {
        // First try to use the specific certificate SHA1 if available
        if ($this->tempKeychainPath) {
            $result = Process::run([
                'security', 'find-identity',
                '-v', '-p', 'codesigning',
                $this->tempKeychainPath,
            ]);

            if ($result->successful()) {
                $output = $result->output();
                // Extract first valid signing identity
                if (preg_match('/^\s*\d+\)\s+([A-F0-9]{40})\s+"([^"]+)"/', $output, $matches)) {
                    return $matches[1]; // Return SHA1 hash
                }
            }
        }

        // Fallback to standard Apple Distribution certificate
        return 'Apple Distribution';
    }

    /**
     * Sign all nested components in the correct order (inside-out)
     */
    protected function signNestedComponents(string $appBundlePath, string $signingIdentity): bool
    {
        $nestedPaths = [
            'Contents/Frameworks',
            'Contents/PlugIns',
            'Contents/XPCServices',
            'Contents/Helpers',
            'Contents/Library/LaunchServices',
            'Contents/Library/Automator',
            'Contents/Library/Spotlight',
            'Contents/Library/LoginItems',
        ];

        foreach ($nestedPaths as $relativePath) {
            $fullPath = $appBundlePath.'/'.$relativePath;

            if (is_dir($fullPath)) {
                if (! $this->signComponentsInDirectory($fullPath, $signingIdentity)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Sign all components in a directory recursively
     */
    protected function signComponentsInDirectory(string $directory, string $signingIdentity): bool
    {
        $items = glob($directory.'/*');

        foreach ($items as $item) {
            if (is_dir($item)) {
                // Check if it's a bundle (has .framework, .bundle, .app, etc.)
                $extension = pathinfo($item, PATHINFO_EXTENSION);

                if (in_array($extension, ['framework', 'bundle', 'app', 'xpc', 'plugin'])) {
                    $result = Process::run([
                        'codesign',
                        '--force',
                        '--sign', $signingIdentity,
                        '--timestamp',
                        '--options', 'runtime',
                        $item,
                    ]);

                    if (! $result->successful()) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Sign the main app bundle
     */
    protected function signAppBundle(string $appBundlePath, string $signingIdentity): bool
    {
        $entitlementsPath = $appBundlePath.'/Contents/NativePHP.entitlements';
        $entitlementsArgs = [];

        if (file_exists($entitlementsPath)) {
            $entitlementsArgs = ['--entitlements', $entitlementsPath];
        }

        $result = Process::run([
            'codesign',
            '--force',
            '--sign', $signingIdentity,
            '--timestamp',
            '--options', 'runtime',
            ...$entitlementsArgs,
            $appBundlePath,
        ]);

        return $result->successful();
    }

    /**
     * Verify IPA code signature contains signed executables
     */
    protected function verifyIpaCodeSignature(string $ipaPath): bool
    {
        $tempDir = sys_get_temp_dir().'/nativephp-verify-'.uniqid();
        mkdir($tempDir, 0755, true);

        $result = Process::run([
            'unzip', '-q', $ipaPath, '-d', $tempDir,
        ]);

        if (! $result->successful()) {
            return false;
        }

        try {
            $payloadDir = $tempDir.'/Payload';
            $appDirs = glob($payloadDir.'/*.app');

            if (empty($appDirs)) {
                return false;
            }

            $appBundlePath = $appDirs[0];

            $result = Process::run([
                'codesign', '--verify', '--deep', '--strict', $appBundlePath,
            ]);

            if (! $result->successful()) {
                return false;
            }

            $result = Process::run([
                'find', $appBundlePath, '-type', 'f', '-perm', '+111',
            ]);

            if ($result->successful()) {
                $executables = array_filter(explode("\n", $result->output()));
                $signedCount = 0;

                foreach ($executables as $executable) {
                    if (trim($executable)) {
                        $verifyResult = Process::run(['codesign', '--verify', $executable]);
                        if ($verifyResult->successful()) {
                            $signedCount++;
                        }
                    }
                }

                if ($signedCount === 0) {
                    return false;
                }
            }

            return true;

        } finally {
            Process::run(['rm', '-rf', $tempDir]);
        }
    }

    /**
     * Fix entitlements to exactly match the provisioning profile
     * This is critical for Apple's server-side validation
     */
    protected function fixEntitlementsForProvisioningProfile(string $appBundlePath): bool
    {
        $provisioningProfilePath = $appBundlePath.'/embedded.mobileprovision';

        if (! file_exists($provisioningProfilePath)) {
            return false;
        }

        $result = Process::run(['security', 'cms', '-D', '-i', $provisioningProfilePath]);
        if (! $result->successful()) {
            return false;
        }

        $profilePlist = $result->output();

        $profileData = simplexml_load_string($profilePlist);
        if (! $profileData) {
            return false;
        }

        $profileEntitlements = $this->extractEntitlementsFromProfile($profileData);
        if (! $profileEntitlements) {
            return false;
        }

        $profileEntitlements = $this->ensurePushNotificationEntitlements($profileEntitlements);

        $entitlementsPath = $appBundlePath.'/NativePHP.entitlements';
        if (! $this->createMatchingEntitlementsFile($entitlementsPath, $profileEntitlements)) {
            return false;
        }

        return true;
    }

    /**
     * Extract entitlements dictionary from provisioning profile
     */
    protected function extractEntitlementsFromProfile(\SimpleXMLElement $profileData): ?array
    {
        // The provisioning profile is a plist with a dict containing an Entitlements key
        $rootDict = $profileData->dict;
        $children = $rootDict->children();

        // Parse key-value pairs sequentially to find Entitlements
        $currentKey = null;

        foreach ($children as $child) {
            $nodeName = $child->getName();

            if ($nodeName === 'key') {
                $currentKey = (string) $child;
            } elseif ($currentKey === 'Entitlements' && $nodeName === 'dict') {
                // Found the entitlements dict
                return $this->parseEntitlementsDict($child);
            }
        }

        return null;
    }

    /**
     * Parse entitlements dict from plist format to array
     */
    protected function parseEntitlementsDict(\SimpleXMLElement $dict): array
    {
        $entitlements = [];
        $children = $dict->children();

        // Parse key-value pairs sequentially
        $currentKey = null;

        foreach ($children as $child) {
            $nodeName = $child->getName();

            if ($nodeName === 'key') {
                $currentKey = (string) $child;
            } elseif ($currentKey !== null) {
                // This is the value for the current key
                switch ($nodeName) {
                    case 'string':
                        $entitlements[$currentKey] = (string) $child;
                        break;
                    case 'array':
                        $entitlements[$currentKey] = $this->parseArrayElement($child);
                        break;
                    case 'true':
                        $entitlements[$currentKey] = true;
                        break;
                    case 'false':
                        $entitlements[$currentKey] = false;
                        break;
                    default:
                        $entitlements[$currentKey] = (string) $child;
                }
                $currentKey = null; // Reset for next key-value pair
            }
        }

        return $entitlements;
    }

    /**
     * Parse array elements from plist
     */
    protected function parseArrayElement(\SimpleXMLElement $arrayElement): array
    {
        $array = [];

        if (isset($arrayElement->string)) {
            foreach ($arrayElement->string as $stringElement) {
                $array[] = (string) $stringElement;
            }
        }

        return $array;
    }

    /**
     * Create entitlements file that exactly matches provisioning profile
     */
    protected function createMatchingEntitlementsFile(string $entitlementsPath, array $profileEntitlements): bool
    {
        try {
            $plist = new \DOMDocument('1.0', 'UTF-8');
            $plist->formatOutput = true;

            // Add DOCTYPE
            $implementation = new \DOMImplementation;
            $doctype = $implementation->createDocumentType('plist', '-//Apple//DTD PLIST 1.0//EN', 'http://www.apple.com/DTDs/PropertyList-1.0.dtd');
            $plist->appendChild($doctype);

            // Create plist root
            $root = $plist->createElement('plist');
            $root->setAttribute('version', '1.0');
            $plist->appendChild($root);

            // Create dict
            $dict = $plist->createElement('dict');
            $root->appendChild($dict);

            // Add entitlements exactly as they appear in the profile
            foreach ($profileEntitlements as $key => $value) {
                $keyElement = $plist->createElement('key', $key);
                $dict->appendChild($keyElement);

                if (is_array($value)) {
                    $arrayElement = $plist->createElement('array');
                    foreach ($value as $item) {
                        $stringElement = $plist->createElement('string', htmlspecialchars($item));
                        $arrayElement->appendChild($stringElement);
                    }
                    $dict->appendChild($arrayElement);
                } elseif (is_bool($value)) {
                    $boolElement = $plist->createElement($value ? 'true' : 'false');
                    $dict->appendChild($boolElement);
                } else {
                    $stringElement = $plist->createElement('string', htmlspecialchars($value));
                    $dict->appendChild($stringElement);
                }
            }

            $plist->save($entitlementsPath);

            return true;

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Configure app settings in the archive's Info.plist (export compliance and Firebase)
     */
    protected function configureAppSettings(string $archivePath): bool
    {
        $infoPlistPath = $archivePath.'/Products/Applications/NativePHP.app/Info.plist';

        if (! file_exists($infoPlistPath)) {
            return false;
        }

        if (! $this->setExportCompliance($infoPlistPath)) {
            return false;
        }

        if (! $this->configureBackgroundModes($infoPlistPath)) {
            return false;
        }

        return true;
    }

    /**
     * Set export compliance in Info.plist
     */
    protected function setExportCompliance(string $infoPlistPath): bool
    {
        // NativePHP apps never use non-exempt encryption
        $usesEncryption = false;

        // Try to replace first (if key exists), then insert (if key doesn't exist)
        $result = Process::run([
            'plutil',
            '-replace', 'ITSAppUsesNonExemptEncryption',
            '-bool', $usesEncryption ? 'true' : 'false',
            $infoPlistPath,
        ]);

        if (! $result->successful()) {
            // Key doesn't exist, try to insert
            $result = Process::run([
                'plutil',
                '-insert', 'ITSAppUsesNonExemptEncryption',
                '-bool', $usesEncryption ? 'true' : 'false',
                $infoPlistPath,
            ]);

            if (! $result->successful()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Configure background modes based on enabled features in Info.plist
     */
    protected function configureBackgroundModes(string $infoPlistPath): bool
    {
        return true;
    }

    /**
     * Ensure push notification entitlements are properly configured
     */
    protected function ensurePushNotificationEntitlements(array $entitlements): array
    {
        $pushConfig = config('nativephp.permissions.push_notifications', false);
        $hasPushNotifications = ! empty($pushConfig);

        if (! $hasPushNotifications) {
            return $entitlements;
        }

        if (! isset($entitlements['aps-environment'])) {
            $exportMethod = $this->option('export-method') ?: 'app-store';

            if ($exportMethod === 'development' || $exportMethod === 'debugging') {
                $apsEnvironment = 'development';
            } else {
                $apsEnvironment = 'production';
            }

            $entitlements['aps-environment'] = $apsEnvironment;
        }

        return $entitlements;
    }

    /**
     * Resolve API key content from file path
     */
    protected function resolveApiKeyFromPath(?string $apiKeyPath): ?string
    {
        if (! $apiKeyPath || ! file_exists($apiKeyPath)) {
            return null;
        }

        $content = file_get_contents($apiKeyPath);
        if ($content === false) {
            return null;
        }

        // Return as base64 for consistency with existing system
        return base64_encode($content);
    }

    /**
     * Coerce NFC formats to ["TAG"] in an entitlements plist (XML string).
     */
    protected function coerceNfcFormatsToTag(string $entitlementsXml): string
    {
        $needle = '<key>'.self::NFC_ENTITLEMENTS_KEY.'</key>';
        if (strpos($entitlementsXml, $needle) === false) {
            return $entitlementsXml; // key not present; nothing to change
        }
        $replacement = <<<'XML'
<key>com.apple.developer.nfc.readersession.formats</key>
<array>
    <string>TAG</string>
</array>
XML;

        return preg_replace(
            '#<key>com\.apple\.developer\.nfc\.readersession\.formats</key>\s*<array>.*?</array>#s',
            $replacement,
            $entitlementsXml
        );
    }

    /**
     * NEW: Verify exported IPA has NFC formats == ["TAG"] only.
     */
    protected function verifyIpaNfcEntitlements(string $ipaPath): bool
    {
        $temp = sys_get_temp_dir().'/nativephp-verify-nfc-'.uniqid();
        mkdir($temp, 0755, true);
        $unzip = Process::run(['unzip', '-q', $ipaPath, '-d', $temp]);
        if (! $unzip->successful()) {
            \Laravel\Prompts\error('Failed to unzip IPA for NFC entitlement verification');
            $this->line($unzip->errorOutput());
            return false;
        }
        $appDir = trim(shell_exec('find '.escapeshellarg($temp.'/Payload').' -maxdepth 1 -type d -name "*.app" -print -quit'));
        if (! $appDir) {
            \Laravel\Prompts\error('Could not find .app bundle in IPA Payload directory');
            Process::run(['rm', '-rf', $temp]);
            return false;
        }
        $exe = trim(shell_exec('/usr/libexec/PlistBuddy -c "Print :CFBundleExecutable" '.escapeshellarg($appDir.'/Info.plist')));

        // Extract entitlements as XML using --xml flag (outputs to stdout with -)
        $extractResult = Process::run(['codesign', '-d', '--entitlements', '-', '--xml', $appDir.'/'.$exe]);

        if (! $extractResult->successful()) {
            \Laravel\Prompts\error('Failed to extract entitlements from IPA');
            $this->line($extractResult->errorOutput());
            Process::run(['rm', '-rf', $temp]);
            return false;
        }

        $entitlementsXml = $extractResult->output();
        Process::run(['rm', '-rf', $temp]);

        if (empty($entitlementsXml)) {
            // No entitlements = no NFC entitlements to worry about
            return true;
        }

        // Convert XML plist to human-readable format for easier regex matching
        $tempPlist = sys_get_temp_dir().'/nativephp-ent-'.uniqid().'.plist';
        file_put_contents($tempPlist, $entitlementsXml);
        $dump = Process::run(['plutil', '-p', $tempPlist]);
        @unlink($tempPlist);

        if (! $dump->successful()) {
            \Laravel\Prompts\error('Failed to parse entitlements from IPA');
            $this->line($dump->errorOutput());
            return false;
        }
        $out = $dump->output();
        if (strpos($out, self::NFC_ENTITLEMENTS_KEY) === false) {
            return true;
        }
        if (preg_match('/com\.apple\.developer\.nfc\.readersession\.formats".*?\[\s*(?:\d+\s*=>\s*)?"TAG"\s*\]/s', $out)) {
            return true;
        }

        \Laravel\Prompts\error('NFC entitlements are invalid - must contain only ["TAG"]');
        $this->line('<fg=yellow>Found NFC entitlements:</>');
        $this->line($out);

        return false;
    }
}
