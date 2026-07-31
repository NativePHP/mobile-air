<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Facades\Http;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

trait ChecksLatestBuildNumber
{
    public function getLatestBuildNumberFromStore(string $platform): ?int
    {
        if ($platform === 'android') {
            return $this->getLatestAndroidBuildNumber();
        }

        if ($platform === 'ios') {
            return $this->getLatestIosBuildNumber();
        }

        return null;
    }

    private function getLatestAndroidBuildNumber(): ?int
    {
        try {
            $googleServiceKey = $this->option('google-service-key') ?? env('GOOGLE_SERVICE_ACCOUNT_KEY');

            if (! $googleServiceKey) {
                note('No Google Service Account Key found, skipping Play Store check');

                return null;
            }

            $this->components->twoColumnDetail('Play Store', 'Checking for latest version code');

            $config = [
                'service_account_key' => $googleServiceKey,
                'package_name' => config('nativephp.app_id'),
            ];

            $latestVersion = $this->getLatestPlayStoreVersionCode($config);

            if ($latestVersion) {
                $this->components->twoColumnDetail('Play Store version', $latestVersion);

                return $latestVersion;
            } else {
                note('No releases found in Play Store (new app)');

                return null;
            }

        } catch (\Exception $e) {
            warning("Could not check Play Store: {$e->getMessage()}");

            return null;
        }
    }

    private function getLatestIosBuildNumber(): ?int
    {
        try {
            // Check for App Store Connect credentials
            $apiKey = $this->getApiKeyPath();

            if (! $apiKey) {
                note('No App Store Connect API key found, skipping App Store check');

                return null;
            }

            $this->components->twoColumnDetail('App Store Connect', 'Checking for latest build number');

            // Get current app version
            $appVersion = config('nativephp.version');
            if (! $appVersion) {
                warning('No app version found in config');

                return null;
            }

            $this->components->twoColumnDetail('Version', $appVersion);

            $latestBuildNumber = $this->getLatestAppStoreConnectBuildNumber($appVersion);

            if ($latestBuildNumber) {
                $this->components->twoColumnDetail('Latest build', "{$latestBuildNumber} (version {$appVersion})");

                return $latestBuildNumber;
            } else {
                note("No builds found for version {$appVersion} (new version)");

                return null;
            }

        } catch (\Exception $e) {
            warning("Could not check App Store Connect: {$e->getMessage()}");

            return null;
        }
    }

    private function getApiKeyPath(): ?string
    {
        // Check for API key file path - try flags first, then environment variables
        $apiKeyPath = $this->option('api-key-path') ?? $this->option('api-key') ?? env('APP_STORE_API_KEY_PATH');

        if ($apiKeyPath && file_exists($apiKeyPath)) {
            return $apiKeyPath;
        }

        return null;
    }

    public function updateBuildNumberFromStore(string $platform, int $jumpBy = 0): bool
    {
        $latestBuildNumber = $this->getLatestBuildNumberFromStore($platform);

        if ($latestBuildNumber === null) {
            note('Using local build number increment');

            return false;
        }

        $newBuildNumber = $latestBuildNumber + 1 + $jumpBy;

        if ($jumpBy > 0) {
            $originalSuggested = $latestBuildNumber + 1;
            $this->components->twoColumnDetail('Original suggested', "{$originalSuggested} (store latest: {$latestBuildNumber})");
            $this->components->twoColumnDetail('Jump by', $jumpBy);
            $this->components->twoColumnDetail('Build number', $newBuildNumber);
        } else {
            $this->components->twoColumnDetail('Build number', "{$newBuildNumber} (store latest: {$latestBuildNumber})");
        }

        // Update build number - use same variable for both platforms
        $this->updateEnvFile('NATIVEPHP_APP_VERSION_CODE', $newBuildNumber);
        // Clear config cache to ensure updated env value is read
        if (function_exists('config')) {
            config(['nativephp.version_code' => $newBuildNumber]);
        }

        // Set flag to prevent double increment
        $this->buildNumberUpdatedFromStore = true;

        return true;
    }

    private function getLatestAppStoreConnectBuildNumber(string $appVersion): ?int
    {
        try {
            $apiKeyPath = $this->getApiKeyPath();
            $apiKeyId = $this->option('api-key-id') ?? env('APP_STORE_API_KEY_ID');
            $apiIssuerId = $this->option('api-issuer-id') ?? env('APP_STORE_API_ISSUER_ID');
            $appId = config('nativephp.app_id');

            if (! $apiKeyPath || ! $apiKeyId || ! $apiIssuerId || ! $appId) {
                warning('Missing App Store Connect API credentials');

                return null;
            }

            // Create JWT token for authentication
            $jwt = $this->createAppStoreConnectJWT($apiKeyPath, $apiKeyId, $apiIssuerId);
            if (! $jwt) {
                return null;
            }

            // Find the app in App Store Connect
            $appStoreAppId = $this->findAppStoreAppId($jwt, $appId);
            if (! $appStoreAppId) {
                return null;
            }

            // Get builds for this specific version
            $builds = $this->getBuildsForVersion($jwt, $appStoreAppId, $appVersion);

            if (empty($builds)) {
                return null;
            }

            // Find the highest build number for this version
            $maxBuildNumber = 0;
            foreach ($builds as $build) {
                $buildNumber = (int) $build['attributes']['version'];
                $maxBuildNumber = max($maxBuildNumber, $buildNumber);
            }

            return $maxBuildNumber > 0 ? $maxBuildNumber : null;

        } catch (\Exception $e) {
            warning("Error accessing App Store Connect API: {$e->getMessage()}");

            return null;
        }
    }

    private function createAppStoreConnectJWT(string $apiKeyPath, string $apiKeyId, string $apiIssuerId): ?string
    {
        try {
            $this->components->twoColumnDetail('API Key ID', $apiKeyId);
            $this->components->twoColumnDetail('Issuer ID', $apiIssuerId);

            // Read the private key
            $privateKey = file_get_contents($apiKeyPath);
            if ($privateKey === false) {
                error('Failed to read App Store Connect API key file');

                return null;
            }

            // If it's base64 encoded, decode it
            if (! str_contains($privateKey, '-----BEGIN PRIVATE KEY-----')) {
                note('API key appears to be base64 encoded, decoding...');
                $privateKey = base64_decode($privateKey);
            }

            // Verify key format
            if (str_contains($privateKey, '-----BEGIN PRIVATE KEY-----')) {
                $this->components->twoColumnDetail('Private key', 'Validated');
            } else {
                warning('Private key format may be incorrect');
            }

            $header = [
                'alg' => 'ES256',
                'kid' => $apiKeyId,
                'typ' => 'JWT',
            ];

            $now = time();
            $payload = [
                'iss' => $apiIssuerId,
                'aud' => 'appstoreconnect-v1',
                'iat' => $now,
                'exp' => $now + 1200, // 20 minutes
            ];

            $headerEncoded = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
            $payloadEncoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

            $dataToSign = $headerEncoded.'.'.$payloadEncoded;

            // Sign with ES256 (ECDSA using P-256 and SHA-256)
            $derSignature = '';

            // Sign the data with ECDSA
            if (! openssl_sign($dataToSign, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
                error('Failed to sign JWT token with ECDSA');

                return null;
            }

            // Convert DER-encoded signature to raw format (64 bytes for ES256)
            $signature = $this->convertDerSignatureToRaw($derSignature);
            if ($signature === null) {
                error('Failed to convert DER signature to raw format');

                return null;
            }

            $signatureEncoded = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

            return $headerEncoded.'.'.$payloadEncoded.'.'.$signatureEncoded;

        } catch (\Exception $e) {
            error("Failed to create JWT token: {$e->getMessage()}");

            return null;
        }
    }

    private function findAppStoreAppId(string $jwt, string $bundleId): ?string
    {
        try {
            $this->components->twoColumnDetail('Bundle ID', $bundleId);

            $response = Http::withToken($jwt)
                ->get('https://api.appstoreconnect.apple.com/v1/apps', [
                    'filter[bundleId]' => $bundleId,
                ]);

            if (! $response->successful()) {
                error('Failed to search for app in App Store Connect');
                error('Response: '.$response->body());

                return null;
            }

            $data = $response->json();
            if (empty($data['data'])) {
                warning("App with bundle ID {$bundleId} not found in App Store Connect");

                return null;
            }

            $appId = $data['data'][0]['id'];
            $this->components->twoColumnDetail('App Store Connect ID', $appId);

            return $appId;

        } catch (\Exception $e) {
            error("Error finding app: {$e->getMessage()}");

            return null;
        }
    }

    private function getBuildsForVersion(string $jwt, string $appStoreAppId, string $appVersion): array
    {
        try {
            // First, get all preReleaseVersions for this app to find the matching version
            $preReleaseVersionId = $this->findPreReleaseVersionId($jwt, $appStoreAppId, $appVersion);

            if (! $preReleaseVersionId) {
                note("No preReleaseVersion found for {$appVersion}");

                return [];
            }

            $this->components->twoColumnDetail('PreRelease version ID', $preReleaseVersionId);

            // Now get builds for this specific preReleaseVersion
            $response = Http::withToken($jwt)
                ->get("https://api.appstoreconnect.apple.com/v1/preReleaseVersions/{$preReleaseVersionId}/builds", [
                    'limit' => 200,
                ]);

            if (! $response->successful()) {
                error('Failed to get builds for preReleaseVersion');
                error("HTTP Status: {$response->status()}");
                error('Response: '.$response->body());

                return [];
            }

            $data = $response->json();
            $builds = $data['data'] ?? [];
            $this->components->twoColumnDetail('Builds found', count($builds)." for version {$appVersion}");

            return $builds;

        } catch (\Exception $e) {
            error("Error getting builds for version: {$e->getMessage()}");

            return [];
        }
    }

    private function findPreReleaseVersionId(string $jwt, string $appStoreAppId, string $appVersion): ?string
    {
        try {
            // Get all preReleaseVersions for this app
            $response = Http::withToken($jwt)
                ->get("https://api.appstoreconnect.apple.com/v1/apps/{$appStoreAppId}/preReleaseVersions", [
                    'limit' => 50,
                ]);

            if (! $response->successful()) {
                warning("Could not get preReleaseVersions: HTTP {$response->status()}");
                warning('Response: '.$response->body());

                return null;
            }

            $data = $response->json();
            $versions = $data['data'] ?? [];

            $this->components->twoColumnDetail('PreRelease versions', count($versions).' found');

            // Debug: Show all available preRelease versions
            $availableVersions = [];
            foreach ($versions as $version) {
                $versionString = $version['attributes']['version'] ?? 'unknown';
                $availableVersions[] = $versionString;
            }

            $this->components->twoColumnDetail('Available versions', implode(', ', array_slice($availableVersions, 0, 10)));
            $this->components->twoColumnDetail('Looking for', $appVersion);

            // Look for matching version
            foreach ($versions as $version) {
                $versionString = $version['attributes']['version'] ?? null;
                if ($versionString === $appVersion) {
                    $this->components->twoColumnDetail('Match found', $versionString);

                    return $version['id'];
                }
            }

            return null;

        } catch (\Exception $e) {
            warning("Error finding preReleaseVersion: {$e->getMessage()}");

            return null;
        }
    }

    private function findAppStoreVersionId(string $jwt, string $appStoreAppId, string $appVersion): ?string
    {
        try {
            // Get all app store versions for this app
            $response = Http::withToken($jwt)
                ->get("https://api.appstoreconnect.apple.com/v1/apps/{$appStoreAppId}/appStoreVersions", [
                    'limit' => 50,
                ]);

            if (! $response->successful()) {
                warning("Could not get App Store versions: HTTP {$response->status()}");
                warning('Response: '.$response->body());

                return null;
            }

            $data = $response->json();
            $versions = $data['data'] ?? [];

            $this->components->twoColumnDetail('App Store versions', count($versions).' found');

            // Debug: Show all available versions and their states
            $availableVersions = [];
            foreach ($versions as $version) {
                $versionString = $version['attributes']['versionString'] ?? 'unknown';
                $appStoreState = $version['attributes']['appStoreState'] ?? 'unknown';
                $availableVersions[] = "{$versionString} ({$appStoreState})";
            }

            $this->components->twoColumnDetail('Available versions', implode(', ', array_slice($availableVersions, 0, 10)));
            $this->components->twoColumnDetail('Looking for', $appVersion);

            // Look for matching version
            foreach ($versions as $version) {
                $versionString = $version['attributes']['versionString'] ?? null;
                if ($versionString === $appVersion) {
                    $this->components->twoColumnDetail('Match found', $versionString);

                    return $version['id'];
                }
            }

            return null;

        } catch (\Exception $e) {
            warning("Error finding App Store version: {$e->getMessage()}");

            return null;
        }
    }

    private function updateEnvFile(string $key, $value): void
    {
        $envFilePath = base_path('.env');

        if (! file_exists($envFilePath)) {
            error('.env file not found');

            return;
        }

        $envContent = file_get_contents($envFilePath);
        $newLine = "{$key}={$value}";

        // Check if the key already exists
        if (preg_match("/^{$key}=.*$/m", $envContent)) {
            // Update existing line
            $envContent = preg_replace("/^{$key}=.*$/m", $newLine, $envContent);
        } else {
            // Add new line
            $envContent = PHP_EOL.rtrim($envContent).PHP_EOL.$newLine.PHP_EOL;
        }

        file_put_contents($envFilePath, $envContent);
    }

    /**
     * Convert DER-encoded ECDSA signature to raw format for ES256 JWT
     * Apple expects a 64-byte signature (32 bytes r + 32 bytes s)
     */
    private function convertDerSignatureToRaw(string $derSignature): ?string
    {
        try {
            $offset = 0;
            $length = strlen($derSignature);

            // Parse DER SEQUENCE
            if ($offset >= $length || ord($derSignature[$offset]) !== 0x30) {
                return null;
            }
            $offset++; // Skip SEQUENCE tag

            // Skip sequence length
            $seqLen = ord($derSignature[$offset]);
            $offset++;
            if ($seqLen & 0x80) {
                $lenBytes = $seqLen & 0x7F;
                $offset += $lenBytes;
            }

            // Parse r component
            if ($offset >= $length || ord($derSignature[$offset]) !== 0x02) {
                return null;
            }
            $offset++; // Skip INTEGER tag

            $rLen = ord($derSignature[$offset]);
            $offset++;

            $r = substr($derSignature, $offset, $rLen);
            $offset += $rLen;

            // Parse s component
            if ($offset >= $length || ord($derSignature[$offset]) !== 0x02) {
                return null;
            }
            $offset++; // Skip INTEGER tag

            $sLen = ord($derSignature[$offset]);
            $offset++;

            $s = substr($derSignature, $offset, $sLen);

            // Remove leading zero bytes if present (DER encoding requirement)
            if (strlen($r) > 32 && ord($r[0]) === 0x00) {
                $r = substr($r, 1);
            }
            if (strlen($s) > 32 && ord($s[0]) === 0x00) {
                $s = substr($s, 1);
            }

            // Pad to 32 bytes if needed
            $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
            $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

            // Ensure exactly 32 bytes each
            $r = substr($r, -32);
            $s = substr($s, -32);

            return $r.$s;

        } catch (\Exception $e) {
            error("Error parsing DER signature: {$e->getMessage()}");

            return null;
        }
    }
}
