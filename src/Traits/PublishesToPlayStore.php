<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

trait PublishesToPlayStore
{
    protected function publishToPlayStore(array $config): bool
    {
        $this->info('🚀 Starting Play Store upload...');

        if (! $this->validatePlayStoreConfig($config)) {
            return false;
        }

        try {
            // Get access token
            $accessToken = $this->getPlayStoreAccessToken($config);
            if (! $accessToken) {
                $this->error('❌ Failed to obtain access token');

                return false;
            }

            // Create edit
            $editId = $this->createPlayStoreEdit($config, $accessToken);
            if (! $editId) {
                $this->error('❌ Failed to create edit');

                return false;
            }

            // Upload bundle
            $bundleResponse = $this->uploadBundleToPlayStore($config, $accessToken, $editId);
            if (! $bundleResponse) {
                $this->error('❌ Failed to upload bundle');

                return false;
            }

            // Update track
            if (! $this->updatePlayStoreTrack($config, $accessToken, $editId, $bundleResponse)) {
                $this->error('❌ Failed to update track');

                return false;
            }

            // Commit edit
            if (! $this->commitPlayStoreEdit($config, $accessToken, $editId)) {
                $this->error('❌ Failed to commit edit');

                return false;
            }

            $this->info('✅ Successfully published to Play Store!');

            return true;

        } catch (\Exception $e) {
            $this->error('❌ Play Store upload failed: '.$e->getMessage());

            return false;
        }
    }

    protected function validatePlayStoreConfig(array $config): bool
    {
        $required = ['service_account_key', 'package_name', 'bundle_path'];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                $this->error("❌ Missing required Play Store config: $key");

                return false;
            }
        }

        if (! File::exists($config['service_account_key'])) {
            $this->error('❌ Service account key file not found: '.$config['service_account_key']);

            return false;
        }

        if (! File::exists($config['bundle_path'])) {
            $this->error('❌ Bundle file not found: '.$config['bundle_path']);

            return false;
        }

        return true;
    }

    protected function getPlayStoreAccessToken(array $config): ?string
    {
        $this->info('🔐 Obtaining access token...');

        $serviceAccountKey = json_decode(File::get($config['service_account_key']), true);

        if (! $serviceAccountKey) {
            $this->error('❌ Invalid service account key file');

            return null;
        }

        // Create JWT token
        $jwt = $this->createJwtToken($serviceAccountKey);
        if (! $jwt) {
            return null;
        }

        // Exchange JWT for access token
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (! $response->successful()) {
            $this->error('❌ Failed to get access token: '.$response->body());

            return null;
        }

        return $response->json('access_token');
    }

    protected function createJwtToken(array $serviceAccountKey): ?string
    {
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $now = time();
        $payload = [
            'iss' => $serviceAccountKey['client_email'],
            'scope' => 'https://www.googleapis.com/auth/androidpublisher',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $signature = '';
        $dataToSign = $headerEncoded.'.'.$payloadEncoded;

        if (! openssl_sign($dataToSign, $signature, $serviceAccountKey['private_key'], 'SHA256')) {
            $this->error('❌ Failed to sign JWT token');

            return null;
        }

        $signatureEncoded = $this->base64UrlEncode($signature);

        return $headerEncoded.'.'.$payloadEncoded.'.'.$signatureEncoded;
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected function createPlayStoreEdit(array $config, string $accessToken): ?string
    {
        $this->info('📝 Creating Play Store edit...');

        $response = Http::withToken($accessToken)
            ->post("https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$config['package_name']}/edits");

        if (! $response->successful()) {
            $this->error('❌ Failed to create edit: '.$response->body());

            return null;
        }

        $editId = $response->json('id');
        $this->info("✅ Created edit: $editId");

        return $editId;
    }

    protected function uploadBundleToPlayStore(array $config, string $accessToken, string $editId): ?array
    {
        $this->info('📦 Uploading bundle to Play Store...');

        $bundleContent = File::get($config['bundle_path']);
        $url = "https://androidpublisher.googleapis.com/upload/androidpublisher/v3/applications/{$config['package_name']}/edits/{$editId}/bundles";

        // Use cURL for proper binary upload control
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $bundleContent,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120, // 2 minute timeout as recommended
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$accessToken,
                'Content-Type: application/octet-stream',
                'Content-Length: '.strlen($bundleContent),
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->error('❌ cURL error: '.$error);

            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->error("❌ Failed to upload bundle (HTTP $httpCode): ".$response);

            return null;
        }

        $bundleInfo = json_decode($response, true);
        if (! $bundleInfo) {
            $this->error('❌ Invalid response from Play Store API');

            return null;
        }

        $this->info("✅ Bundle uploaded successfully (Version Code: {$bundleInfo['versionCode']})");

        return $bundleInfo;
    }

    protected function updatePlayStoreTrack(array $config, string $accessToken, string $editId, array $bundleInfo): bool
    {
        $track = $config['track'] ?? 'internal';
        $this->info("🎯 Updating track: $track");

        $trackData = [
            'track' => $track,
            'releases' => [
                [
                    'versionCodes' => [(string) $bundleInfo['versionCode']],
                    'status' => 'draft',
                ],
            ],
        ];

        $this->info("🐛 Debug: Using release status 'draft' for all tracks (current: '{$track}')");

        $response = Http::withToken($accessToken)
            ->put("https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$config['package_name']}/edits/{$editId}/tracks/{$track}", $trackData);

        if (! $response->successful()) {
            $this->error('❌ Failed to update track: '.$response->body());

            return false;
        }

        $this->info('✅ Track updated successfully');

        return true;
    }

    protected function commitPlayStoreEdit(array $config, string $accessToken, string $editId): bool
    {
        $this->info('✅ Committing edit...');

        $response = Http::withToken($accessToken)
            ->post("https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$config['package_name']}/edits/{$editId}:commit");

        if (! $response->successful()) {
            $this->error('❌ Failed to commit edit: '.$response->body());

            return false;
        }

        $this->info('✅ Edit committed successfully!');

        return true;
    }

    public function getLatestPlayStoreVersionCode(array $config): ?int
    {
        try {
            // Get access token
            $accessToken = $this->getPlayStoreAccessToken($config);
            if (! $accessToken) {
                return null;
            }

            $allVersionCodes = [];

            // Strategy 1: Check all historical edits (includes discarded drafts)
            $editVersionCodes = $this->getAllVersionCodesFromEdits($config, $accessToken);
            if ($editVersionCodes) {
                $allVersionCodes = array_merge($allVersionCodes, $editVersionCodes);
                $this->info('🔍 Found '.count($editVersionCodes).' version codes from edit history (including discarded drafts)');
            }

            // Strategy 2: Check published tracks (current method)
            $trackVersionCodes = $this->getAllVersionCodesFromTracks($config, $accessToken);
            if ($trackVersionCodes) {
                $allVersionCodes = array_merge($allVersionCodes, $trackVersionCodes);
                $this->info('🔍 Found '.count($trackVersionCodes).' version codes from published tracks');
            }

            // Strategy 3: Check recent internal testing tracks specifically (these might contain versions not in main tracks)
            $internalVersionCodes = $this->getVersionCodesFromInternalTracks($config, $accessToken);
            if ($internalVersionCodes) {
                $allVersionCodes = array_merge($allVersionCodes, $internalVersionCodes);
                $this->info('🔍 Found '.count($internalVersionCodes).' version codes from internal/testing tracks');
            }

            if (empty($allVersionCodes)) {
                return null;
            }

            // Get the highest version code across all sources
            $uniqueVersionCodes = array_unique($allVersionCodes);
            $maxVersionCode = max($uniqueVersionCodes);
            $this->info("📊 Highest version code found: {$maxVersionCode} (from ".count($uniqueVersionCodes).' unique versions)');

            // Debug: Show all found version codes for troubleshooting
            $sortedVersions = $uniqueVersionCodes;
            rsort($sortedVersions);
            $topVersions = array_slice($sortedVersions, 0, 10);
            $this->info('🔍 Top version codes found: '.implode(', ', $topVersions));

            // Strategy 4: Test if the next version code is actually available by attempting a dry run
            $suggestedNext = $maxVersionCode + 1;
            if ($this->testVersionCodeAvailability($config, $accessToken, $suggestedNext)) {
                $this->info("✅ Version code {$suggestedNext} appears to be available");

                return $maxVersionCode;
            } else {
                $this->warn("⚠️ Version code {$suggestedNext} appears to be taken, checking higher numbers...");

                // Try a few higher numbers to find the next available one
                for ($testVersion = $suggestedNext + 1; $testVersion <= $suggestedNext + 10; $testVersion++) {
                    if ($this->testVersionCodeAvailability($config, $accessToken, $testVersion)) {
                        $this->info("✅ Found next available version code: {$testVersion} (actual latest: ".($testVersion - 1).')');

                        return $testVersion - 1;
                    }
                }

                $this->warn("⚠️ Could not determine actual latest version code, using detected maximum: {$maxVersionCode}");

                return $maxVersionCode;
            }

        } catch (\Exception $e) {
            $this->warn("⚠️ Error checking Play Store version codes: {$e->getMessage()}");

            return null;
        }
    }

    private function getAllVersionCodesFromEdits(array $config, string $accessToken): array
    {
        $versionCodes = [];

        try {
            // Create a new edit to access all bundles/APKs Google Play knows about
            $editResponse = Http::withToken($accessToken)
                ->post("https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$config['package_name']}/edits");

            if (! $editResponse->successful()) {
                $this->warn('⚠️ Failed to create edit for version code check: '.$editResponse->body());

                return [];
            }

            $editData = $editResponse->json();
            $editId = $editData['id'];

            $this->info('🔍 Checking all bundles/APKs Google Play has seen (including discarded drafts)...');

            // Check all bundles Google Play knows about
            $bundleVersions = $this->getVersionCodesFromEditBundles($config, $accessToken, $editId);
            $versionCodes = array_merge($versionCodes, $bundleVersions);
            if (! empty($bundleVersions)) {
                $this->info('📦 Found '.count($bundleVersions).' version codes from bundles (including discarded)');
            }

            // Check all APKs Google Play knows about
            $apkVersions = $this->getVersionCodesFromEditApks($config, $accessToken, $editId);
            $versionCodes = array_merge($versionCodes, $apkVersions);
            if (! empty($apkVersions)) {
                $this->info('📦 Found '.count($apkVersions).' version codes from APKs (including discarded)');
            }

            // Clean up the edit (delete it since we only used it for checking)
            Http::withToken($accessToken)
                ->delete("https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$config['package_name']}/edits/{$editId}");

        } catch (\Exception $e) {
            $this->warn("⚠️ Error checking consumed version codes: {$e->getMessage()}");
        }

        return array_unique($versionCodes);
    }

    private function getVersionCodesFromEditBundles(array $config, string $accessToken, string $editId): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->get("https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$config['package_name']}/edits/{$editId}/bundles");

            if (! $response->successful()) {
                return [];
            }

            $bundlesData = $response->json();
            $versionCodes = [];

            if (isset($bundlesData['bundles'])) {
                foreach ($bundlesData['bundles'] as $bundle) {
                    if (isset($bundle['versionCode'])) {
                        $versionCodes[] = (int) $bundle['versionCode'];
                    }
                }
            }

            return $versionCodes;

        } catch (\Exception $e) {
            return [];
        }
    }

    private function getVersionCodesFromEditApks(array $config, string $accessToken, string $editId): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->get("https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$config['package_name']}/edits/{$editId}/apks");

            if (! $response->successful()) {
                return [];
            }

            $apksData = $response->json();
            $versionCodes = [];

            if (isset($apksData['apks'])) {
                foreach ($apksData['apks'] as $apk) {
                    if (isset($apk['versionCode'])) {
                        $versionCodes[] = (int) $apk['versionCode'];
                    }
                }
            }

            return $versionCodes;

        } catch (\Exception $e) {
            return [];
        }
    }

    private function getAllVersionCodesFromTracks(array $config, string $accessToken): array
    {
        try {
            // Create edit for querying tracks
            $editId = $this->createPlayStoreEdit($config, $accessToken);
            if (! $editId) {
                return [];
            }

            // Query all tracks
            $response = Http::withToken($accessToken)
                ->get("https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$config['package_name']}/edits/{$editId}/tracks");

            $versionCodes = [];

            if ($response->successful()) {
                $tracksData = $response->json();

                // Parse all tracks and collect all version codes
                if (isset($tracksData['tracks'])) {
                    foreach ($tracksData['tracks'] as $track) {
                        if (isset($track['releases'])) {
                            foreach ($track['releases'] as $release) {
                                if (isset($release['versionCodes'])) {
                                    foreach ($release['versionCodes'] as $versionCode) {
                                        $versionCodes[] = (int) $versionCode;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Clean up edit
            $this->cleanupEdit($config, $accessToken, $editId);

            return array_unique($versionCodes);

        } catch (\Exception $e) {
            return [];
        }
    }

    private function getVersionCodesFromInternalTracks(array $config, string $accessToken): array
    {
        try {
            // Create edit for querying internal tracks specifically
            $editId = $this->createPlayStoreEdit($config, $accessToken);
            if (! $editId) {
                return [];
            }

            $versionCodes = [];
            $internalTracks = ['internal', 'alpha', 'beta'];

            foreach ($internalTracks as $trackName) {
                try {
                    $response = Http::withToken($accessToken)
                        ->get("https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$config['package_name']}/edits/{$editId}/tracks/{$trackName}");

                    if ($response->successful()) {
                        $trackData = $response->json();

                        if (isset($trackData['releases'])) {
                            foreach ($trackData['releases'] as $release) {
                                if (isset($release['versionCodes'])) {
                                    foreach ($release['versionCodes'] as $versionCode) {
                                        $versionCodes[] = (int) $versionCode;
                                    }
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Continue to next track if this one fails
                    continue;
                }
            }

            // Clean up edit
            $this->cleanupEdit($config, $accessToken, $editId);

            return array_unique($versionCodes);

        } catch (\Exception $e) {
            return [];
        }
    }

    private function testVersionCodeAvailability(array $config, string $accessToken, int $versionCode): bool
    {
        try {
            // Create a test edit to see if we can use this version code
            $editId = $this->createPlayStoreEdit($config, $accessToken);
            if (! $editId) {
                return false;
            }

            // Try to create a dummy track assignment with the test version code
            // This will fail if the version code is already used
            $trackData = [
                'track' => 'internal',
                'releases' => [
                    [
                        'versionCodes' => [(string) $versionCode],
                        'status' => 'draft',
                    ],
                ],
            ];

            $response = Http::withToken($accessToken)
                ->put("https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$config['package_name']}/edits/{$editId}/tracks/internal", $trackData);

            // Clean up the test edit
            $this->cleanupEdit($config, $accessToken, $editId);

            // If we got a 400 error about version code already used, it's not available
            if (! $response->successful()) {
                $errorBody = $response->body();
                if (str_contains($errorBody, 'already been used') || str_contains($errorBody, 'Version code')) {
                    return false;
                }
            }

            // If we didn't get an error about version code being used, it might be available
            return true;

        } catch (\Exception $e) {
            // If there's an error, assume it's not available to be safe
            return false;
        }
    }

    private function cleanupEdit(array $config, string $accessToken, string $editId): void
    {
        try {
            // Delete the edit to clean up
            Http::withToken($accessToken)
                ->delete("https://androidpublisher.googleapis.com/androidpublisher/v3/applications/{$config['package_name']}/edits/{$editId}");
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }
    }
}
