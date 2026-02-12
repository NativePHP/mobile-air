<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Native\Mobile\Traits\ChecksLatestBuildNumber;
use Native\Mobile\Traits\DisplaysMarketingBanners;
use Native\Mobile\Traits\PackagesIos;
use Native\Mobile\Traits\PlatformFileOperations;
use Native\Mobile\Traits\PublishesToPlayStore;
use Native\Mobile\Traits\RunsAndroid;
use Native\Mobile\Traits\ValidatesAppConfig;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;

class PackageCommand extends Command
{
    use ChecksLatestBuildNumber, DisplaysMarketingBanners, PackagesIos, PlatformFileOperations, PublishesToPlayStore, RunsAndroid, ValidatesAppConfig {
        RunsAndroid::updateEnvFile insteadof ChecksLatestBuildNumber;
        PublishesToPlayStore::base64UrlEncode insteadof ChecksLatestBuildNumber;
    }

    protected $signature = 'native:package 
        {platform? : The platform to build for (android/a or ios/i)}
        {--ios : Target iOS platform (shorthand for platform=ios)}
        {--android : Target Android platform (shorthand for platform=android)}
        {--keystore= : Path to Android keystore file for signing}
        {--keystore-password= : Keystore password}
        {--key-alias= : Key alias for signing}
        {--key-password= : Key password}
        {--fcm-key= : FCM Server Key for push notifications (optional)}
        {--google-service-key= : Google Service Account Key file path (optional)}
        {--build-type=release : Build type (release|bundle)}
        {--output= : Output directory for signed artifacts}
        {--export-method=app-store : iOS export method (app-store|ad-hoc|enterprise|development)}
        {--validate-only : Only validate the archive without exporting}
        {--rebuild : Force rebuild by removing existing archive}
        {--upload-to-app-store : Upload iOS app to App Store Connect after packaging}
        {--test-upload : Test upload existing IPA to App Store Connect (skip build)}
        {--clean-caches : Clear Xcode and SPM caches before building (iOS only)}
        {--validate-profile : Validate iOS provisioning profile entitlements}
        {--upload-to-play-store : Upload Android app to Play Store after packaging}
        {--play-store-track=internal : Play Store track (internal|alpha|beta|production)}
        {--test-push= : Test Play Store upload with existing AAB file (skip build)}
        {--jump-by= : Add extra number to the suggested version (e.g. --jump-by=10 to skip ahead)}
        {--api-key= : Path to App Store Connect API key file (iOS)}
        {--api-key-path= : Path to App Store Connect API key file (.p8) - same as --api-key}
        {--api-key-id= : App Store Connect API key ID}
        {--api-issuer-id= : App Store Connect API issuer ID}
        {--certificate-path= : Path to iOS distribution certificate (.p12/.cer)}
        {--certificate-password= : iOS certificate password}
        {--provisioning-profile-path= : Path to provisioning profile (.mobileprovision)}
        {--team-id= : Apple Developer Team ID}
        {--skip-prepare : Skip prepareAndroidBuild() to preserve existing project files}
        {--no-tty : Disable TTY mode for non-interactive environments}';

    protected $description = 'Package signed Android/iOS apps for distribution';

    protected string $buildType;

    protected string $platform;

    public function handle(): void
    {
        // Get platform (flags take priority over argument)
        if ($this->option('ios')) {
            $this->platform = 'ios';
        } elseif ($this->option('android')) {
            $this->platform = 'android';
        } else {
            $platform = $this->argument('platform');
            if (!$platform) {
                \Laravel\Prompts\error('Platform must be specified via argument or flags (--ios/--android)');
                return;
            }
            // Support shorthands: 'a' for android, 'i' for ios
            $this->platform = match(strtolower($platform)) {
                'android', 'a' => 'android',
                'ios', 'i' => 'ios',
                default => $platform,
            };
        }

        if (! in_array($this->platform, ['android', 'ios'])) {
            \Laravel\Prompts\error('Platform must be either "android" or "ios" (or "a" / "i" as shortcuts)');

            return;
        }

        $this->validateAppId();

        // Handle test push for Android (skip build, just upload existing AAB)
        if ($this->option('test-push') && $this->platform === 'android') {
            $this->testPlayStorePush();

            return;
        }

        if ($this->option('validate-profile') && $this->platform === 'ios') {
            $exportMethod = $this->option('export-method') ?: 'app-store';
            $this->validateIosProvisioningProfile($exportMethod);

            return;
        }

        intro("Building signed NativePHP {$this->platform} app");

        if (! $this->validateBuildEnvironment()) {
            return;
        }

        $this->buildType = $this->option('build-type');
        if (! in_array($this->buildType, ['release', 'bundle'])) {
            \Laravel\Prompts\error('Build type must be either "release" or "bundle"');

            return;
        }

        if ($this->platform === 'android') {
            if ($this->buildType === 'bundle' && ($this->option('google-service-key') || env('GOOGLE_SERVICE_ACCOUNT_KEY'))) {
                $jumpBy = (int) $this->option('jump-by') ?: 0;
                $this->updateBuildNumberFromStore('android', $jumpBy);
            }

            $this->buildAndroid();
        } elseif ($this->platform === 'ios') {
            // Validate and prepare iOS signing configuration
            $iosSigningConfig = $this->validateAndPrepareIosSigningConfig();
            if (! $iosSigningConfig) {
                return;
            }

            $this->buildIos($iosSigningConfig);
        }
    }

    protected function buildAndroid(): void
    {
        $androidPath = base_path('nativephp/android');

        if (! is_dir($androidPath)) {
            \Laravel\Prompts\error('No Android project found at [nativephp/android].');
            \Laravel\Prompts\note('Run `php artisan native:install android` first.');

            return;
        }

        // Validate signing configuration
        $signingConfig = $this->validateAndPrepareSigningConfig();
        if (! $signingConfig) {
            return;
        }

        if (! $this->option('skip-prepare')) {
            $this->prepareAndroidBuild();
        }

        // Build with signing
        $gradleTask = $this->buildType === 'bundle' ? 'bundleRelease' : 'assembleRelease';

        try {
            $buildSuccessful = $this->executeGradleBuild($gradleTask, $signingConfig);

            if (! $buildSuccessful) {
                \Laravel\Prompts\error('Build failed');

                return;
            }
        } catch (\Exception $e) {
            \Laravel\Prompts\error('Build failed: '.$e->getMessage());

            return;
        }

        // Handle artifacts
        $outputPath = $this->handleBuildArtifacts();

        // Upload to Play Store if requested
        if ($this->option('upload-to-play-store')) {
            $this->uploadToPlayStore($outputPath, $signingConfig);
        }

        outro("Signed {$this->buildType} build complete");

        $this->showBifrostBanner();
    }

    protected function validateAndPrepareSigningConfig(): ?array
    {
        $keystore = $this->option('keystore');
        $keystorePassword = $this->option('keystore-password');
        $keyAlias = $this->option('key-alias');
        $keyPassword = $this->option('key-password');

        // Check for environment variable fallbacks
        if (! $keystore) {
            $keystore = env('ANDROID_KEYSTORE_FILE');
        }
        if (! $keystorePassword) {
            $keystorePassword = env('ANDROID_KEYSTORE_PASSWORD');
        }
        if (! $keyAlias) {
            $keyAlias = env('ANDROID_KEY_ALIAS');
        }
        if (! $keyPassword) {
            $keyPassword = env('ANDROID_KEY_PASSWORD');
        }

        // Validate required signing parameters
        $missing = [];
        if (! $keystore) {
            $missing[] = '--keystore (or ANDROID_KEYSTORE_FILE env var)';
        }
        if (! $keystorePassword) {
            $missing[] = '--keystore-password (or ANDROID_KEYSTORE_PASSWORD env var)';
        }
        if (! $keyAlias) {
            $missing[] = '--key-alias (or ANDROID_KEY_ALIAS env var)';
        }
        if (! $keyPassword) {
            $missing[] = '--key-password (or ANDROID_KEY_PASSWORD env var)';
        }

        if (! empty($missing)) {
            \Laravel\Prompts\error('Missing required signing configuration');
            foreach ($missing as $param) {
                $this->line("   - $param");
            }

            return null;
        }

        if (! File::exists($keystore)) {
            \Laravel\Prompts\error("Keystore file not found: $keystore");

            return null;
        }

        if (! is_readable($keystore)) {
            \Laravel\Prompts\error("Keystore file is not readable: $keystore");

            return null;
        }

        $this->components->twoColumnDetail('Keystore', $keystore);
        $this->components->twoColumnDetail('Key alias', $keyAlias);

        $fcmKey = $this->option('fcm-key') ?: env('FCM_SERVER_KEY');
        $googleServiceKey = $this->option('google-service-key') ?: env('GOOGLE_SERVICE_ACCOUNT_KEY');

        return [
            'keystore' => realpath($keystore),
            'keystorePassword' => $keystorePassword,
            'keyAlias' => $keyAlias,
            'keyPassword' => $keyPassword,
            'fcmKey' => $fcmKey,
            'googleServiceKey' => $googleServiceKey,
        ];
    }

    protected function validateAndPrepareIosSigningConfig(): ?array
    {
        // Get values from flags first, then fall back to environment variables
        $apiKeyPath = $this->option('api-key-path') ?: $this->option('api-key');
        $apiKeyId = $this->option('api-key-id');
        $apiIssuerId = $this->option('api-issuer-id');
        $certificatePath = $this->option('certificate-path');
        $certificatePassword = $this->option('certificate-password');
        $provisioningProfilePath = $this->option('provisioning-profile-path');
        $teamId = $this->option('team-id');

        // Check for environment variable fallbacks
        if (! $apiKeyPath) {
            $apiKeyPath = env('APP_STORE_API_KEY_PATH');
        }
        if (! $apiKeyId) {
            $apiKeyId = env('APP_STORE_API_KEY_ID');
        }
        if (! $apiIssuerId) {
            $apiIssuerId = env('APP_STORE_API_ISSUER_ID');
        }
        if (! $certificatePath) {
            $certificatePath = env('IOS_DISTRIBUTION_CERTIFICATE_PATH');
        }
        if (! $certificatePassword) {
            $certificatePassword = env('IOS_DISTRIBUTION_CERTIFICATE_PASSWORD');
        }
        if (! $provisioningProfilePath) {
            $provisioningProfilePath = env('IOS_DISTRIBUTION_PROVISIONING_PROFILE_PATH');
        }
        if (! $teamId) {
            $teamId = env('IOS_TEAM_ID');
        }

        // For App Store uploads, API credentials are required
        if ($this->option('upload-to-app-store')) {
            $missing = [];
            if (! $apiKeyPath) {
                $missing[] = '--api-key-path (or APP_STORE_API_KEY_PATH env var)';
            }
            if (! $apiKeyId) {
                $missing[] = '--api-key-id (or APP_STORE_API_KEY_ID env var)';
            }
            if (! $apiIssuerId) {
                $missing[] = '--api-issuer-id (or APP_STORE_API_ISSUER_ID env var)';
            }

            if (! empty($missing)) {
                \Laravel\Prompts\error('Missing required App Store Connect API configuration');
                foreach ($missing as $param) {
                    $this->line("   - $param");
                }

                return null;
            }

            if (! File::exists($apiKeyPath)) {
                \Laravel\Prompts\error("App Store Connect API key file not found: $apiKeyPath");

                return null;
            }

            if (! is_readable($apiKeyPath)) {
                \Laravel\Prompts\error("App Store Connect API key file is not readable: $apiKeyPath");

                return null;
            }

            $this->components->twoColumnDetail('API key', $apiKeyPath);
            $this->components->twoColumnDetail('API Key ID', $apiKeyId);
            $this->components->twoColumnDetail('Issuer ID', $apiIssuerId);
        }

        if ($certificatePath) {
            if (! File::exists($certificatePath)) {
                \Laravel\Prompts\error("Certificate file not found: $certificatePath");

                return null;
            }
            if (! is_readable($certificatePath)) {
                \Laravel\Prompts\error("Certificate file is not readable: $certificatePath");

                return null;
            }
            $this->components->twoColumnDetail('Certificate', $certificatePath);
        }

        if ($provisioningProfilePath) {
            if (! File::exists($provisioningProfilePath)) {
                \Laravel\Prompts\error("Provisioning profile not found: $provisioningProfilePath");

                return null;
            }
            if (! is_readable($provisioningProfilePath)) {
                \Laravel\Prompts\error("Provisioning profile is not readable: $provisioningProfilePath");

                return null;
            }
            $this->components->twoColumnDetail('Provisioning profile', $provisioningProfilePath);
        }

        if ($teamId) {
            $this->components->twoColumnDetail('Team ID', $teamId);
        }

        return [
            'apiKeyPath' => $apiKeyPath ? realpath($apiKeyPath) : null,
            'apiKeyId' => $apiKeyId,
            'apiIssuerId' => $apiIssuerId,
            'certificatePath' => $certificatePath ? realpath($certificatePath) : null,
            'certificatePassword' => $certificatePassword,
            'provisioningProfilePath' => $provisioningProfilePath ? realpath($provisioningProfilePath) : null,
            'teamId' => $teamId,
        ];
    }

    protected function handleBuildArtifacts(?string $customOutputPath = null): ?string
    {
        $outputPath = $customOutputPath ?: $this->findBuildOutput();
        $customOutputDir = $this->option('output');

        if (! $outputPath || ! file_exists($outputPath)) {
            \Laravel\Prompts\warning('Could not locate build output file');

            return null;
        }

        $this->components->twoColumnDetail('Build output', $outputPath);
        $this->components->twoColumnDetail('File size', round(filesize($outputPath) / 1024 / 1024, 2).' MB');

        if ($customOutputDir) {
            if (! is_dir($customOutputDir)) {
                File::makeDirectory($customOutputDir, 0755, true);
            }

            $filename = basename($outputPath);
            $destinationPath = rtrim($customOutputDir, '/\\').DIRECTORY_SEPARATOR.$filename;

            File::copy($outputPath, $destinationPath);
            $this->components->twoColumnDetail('Copied to', $destinationPath);

            $this->openOutputDirectory($customOutputDir);
        } else {
            $this->openOutputDirectory(dirname($outputPath));
        }

        return $outputPath;
    }

    protected function findBuildOutput(): ?string
    {
        // Normalize path to use forward slashes for glob() compatibility across platforms
        $basePath = str_replace('\\', '/', base_path('nativephp/android/app/build/outputs'));

        if ($this->buildType === 'bundle') {
            // Look for AAB files
            $patterns = [
                $basePath.'/bundle/release/app-release.aab',
                $basePath.'/bundle/release/*.aab',
            ];
        } else {
            // Look for APK files
            $patterns = [
                $basePath.'/apk/release/app-release.apk',
                $basePath.'/apk/release/*.apk',
            ];
        }

        foreach ($patterns as $pattern) {
            $files = glob($pattern);
            if (! empty($files)) {
                return $files[0];
            }
        }

        return null;
    }

    protected function openOutputDirectory(string $directory): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $windowsPath = str_replace('/', '\\', $directory);
            exec("explorer.exe \"$windowsPath\"");
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            exec("open \"$directory\"");
        } elseif (PHP_OS_FAMILY === 'Linux') {
            if (shell_exec('which xdg-open')) {
                exec("xdg-open \"$directory\"");
            }
        }
    }

    private function validateIosProvisioningProfile(string $exportMethod): bool
    {
        $provisioningProfile = $this->getProvisioningProfileForExportMethod($exportMethod);

        if (! $provisioningProfile) {
            \Laravel\Prompts\warning('No provisioning profile found for export method: '.$exportMethod);

            return false;
        }

        $provisioningProfileData = base64_decode($provisioningProfile);
        if (! $provisioningProfileData) {
            \Laravel\Prompts\error('Failed to decode provisioning profile');

            return false;
        }

        $profileName = $this->extractProvisioningProfileName($provisioningProfileData);
        $this->components->twoColumnDetail('Profile Name', $profileName ?: 'Unknown');

        $entitlements = $this->extractProvisioningProfileEntitlements($provisioningProfileData);

        if (! $entitlements) {
            \Laravel\Prompts\error('Failed to extract entitlements from provisioning profile');

            return false;
        }

        $this->newLine();
        $this->components->twoColumnDetail('Entitlements', '');
        foreach ($entitlements as $key => $value) {
            if (is_array($value)) {
                $this->components->twoColumnDetail("  {$key}", '['.implode(', ', $value).']');
            } else {
                $this->components->twoColumnDetail("  {$key}", is_bool($value) ? ($value ? 'true' : 'false') : $value);
            }
        }

        $hasPushNotifications = isset($entitlements['aps-environment']);
        $apsEnvironment = $entitlements['aps-environment'] ?? null;

        $this->newLine();
        if ($hasPushNotifications) {
            $this->components->twoColumnDetail('Push notifications', "supported ({$apsEnvironment})");

            $expectedEnvironment = in_array($exportMethod, ['app-store', 'ad-hoc', 'enterprise']) ? 'production' : 'development';
            if ($apsEnvironment !== $expectedEnvironment) {
                \Laravel\Prompts\warning("APS environment ({$apsEnvironment}) may not match export method ({$exportMethod})");
            }
        } else {
            \Laravel\Prompts\error('Push notifications NOT supported - missing aps-environment entitlement');
        }

        $bundleId = config('nativephp.app_id');
        $this->components->twoColumnDetail('App Bundle ID', $bundleId);

        $pushConfig = config('nativephp.permissions.push_notifications', false);
        $pushEnabled = ! empty($pushConfig);
        $this->components->twoColumnDetail('Push Notifications Enabled', $pushEnabled ? 'Yes' : 'No');

        if (isset($entitlements['com.apple.developer.associated-domains'])) {
            $domains = $entitlements['com.apple.developer.associated-domains'];
            $domainsDisplay = is_array($domains) ? implode(', ', $domains) : $domains;
            $this->components->twoColumnDetail('Associated Domains', $domainsDisplay);
        }

        return $hasPushNotifications;
    }

    private function extractProvisioningProfileName(string $provisioningProfileData): ?string
    {
        if (preg_match('/<key>Name<\/key>\s*<string>([^<]+)<\/string>/', $provisioningProfileData, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractProvisioningProfileEntitlements(string $provisioningProfileData): ?array
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

    protected function uploadToPlayStore(?string $bundlePath, array $signingConfig): void
    {
        if (! $bundlePath) {
            \Laravel\Prompts\error('No bundle path provided for Play Store upload');

            return;
        }

        if ($this->buildType !== 'bundle') {
            \Laravel\Prompts\warning('Play Store upload requires AAB bundle. Use --build-type=bundle');

            return;
        }

        $googleServiceKey = $signingConfig['googleServiceKey'] ?? $this->option('google-service-key') ?? env('GOOGLE_SERVICE_ACCOUNT_KEY');

        if (! $googleServiceKey) {
            \Laravel\Prompts\error('Google Service Account Key required for Play Store upload');
            \Laravel\Prompts\note('Provide via --google-service-key option or GOOGLE_SERVICE_ACCOUNT_KEY environment variable');

            return;
        }

        $config = [
            'service_account_key' => $googleServiceKey,
            'package_name' => config('nativephp.app_id'),
            'bundle_path' => $bundlePath,
            'track' => $this->option('play-store-track'),
        ];

        $success = $this->publishToPlayStore($config);

        if ($success) {
            $track = $config['track'];
            \Laravel\Prompts\info("Successfully published to Play Store ($track track)");
        }
    }

    protected function testPlayStorePush(): void
    {
        $aabPath = $this->option('test-push');

        if (! $aabPath) {
            \Laravel\Prompts\error('Please provide the path to your AAB file with --test-push=/path/to/app.aab');

            return;
        }

        if (! file_exists($aabPath)) {
            \Laravel\Prompts\error("AAB file not found: $aabPath");

            return;
        }

        if (pathinfo($aabPath, PATHINFO_EXTENSION) !== 'aab') {
            \Laravel\Prompts\error('File must be an Android App Bundle (.aab)');

            return;
        }

        intro('Testing Play Store upload with existing AAB');

        $this->components->twoColumnDetail('AAB file', $aabPath);
        $this->components->twoColumnDetail('File size', round(filesize($aabPath) / 1024 / 1024, 2).' MB');

        $googleServiceKey = $this->option('google-service-key') ?? env('GOOGLE_SERVICE_ACCOUNT_KEY');

        if (! $googleServiceKey) {
            \Laravel\Prompts\error('Google Service Account Key required for Play Store upload');
            \Laravel\Prompts\note('Provide via --google-service-key option or GOOGLE_SERVICE_ACCOUNT_KEY environment variable');

            return;
        }

        $config = [
            'service_account_key' => $googleServiceKey,
            'package_name' => config('nativephp.app_id'),
            'bundle_path' => $aabPath,
            'track' => $this->option('play-store-track'),
        ];

        $success = $this->publishToPlayStore($config);

        if ($success) {
            $track = $config['track'];
            outro("Test upload successful - Published to Play Store ($track track)");
        } else {
            outro('Test upload failed');
        }
    }
}
