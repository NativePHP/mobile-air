<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Native\Mobile\Concerns\ChecksLatestBuildNumber;
use Native\Mobile\Concerns\PublishesToPlayStore;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;

class CheckBuildNumberCommand extends Command
{
    use ChecksLatestBuildNumber, PublishesToPlayStore {
        PublishesToPlayStore::base64UrlEncode insteadof ChecksLatestBuildNumber;
    }

    protected $signature = 'native:check-build-number 
        {platform : The platform to check (android/a, ios/i, or both)}
        {--google-service-key= : Path to Google Service Account JSON key file (Android)}
        {--api-key= : Path to App Store Connect API key file (iOS)}
        {--api-key-path= : Path to App Store Connect API key file (.p8) - same as --api-key}
        {--api-key-id= : App Store Connect API key ID}
        {--api-issuer-id= : App Store Connect API issuer ID}
        {--update : Update local build number to store latest + 1}
        {--jump-by= : Add extra number to the suggested version (e.g. --jump-by=10 to skip ahead)}';

    protected $description = 'Check latest build numbers from app stores';

    public function handle(): void
    {
        $platform = match (strtolower($this->argument('platform'))) {
            'a' => 'android',
            'i' => 'ios',
            default => $this->argument('platform'),
        };

        if (! in_array($platform, ['android', 'ios', 'both'])) {
            $this->error('❌ Platform must be android (a), ios (i), or both');

            return;
        }

        intro('🔍 Checking latest build numbers...');

        if ($platform === 'android' || $platform === 'both') {
            $this->checkAndroidBuildNumber();
        }

        if ($platform === 'ios' || $platform === 'both') {
            $this->checkIosBuildNumber();
        }

        outro('✅ Build number check complete!');
    }

    private function checkAndroidBuildNumber(): void
    {
        $this->info('🤖 Checking Android (Google Play Store)...');

        $latestBuildNumber = $this->getLatestBuildNumberFromStore('android');
        $currentLocal = env('NATIVEPHP_APP_VERSION_CODE');
        $jumpBy = (int) $this->option('jump-by') ?: 0;

        if ($latestBuildNumber !== null) {
            $this->line("📱 Play Store latest: {$latestBuildNumber}");
            $this->line('💻 Local current: '.($currentLocal ?: 'not set'));

            if ($this->option('update')) {
                $this->updateBuildNumberFromStore('android', $jumpBy);
            } else {
                $suggested = $latestBuildNumber + 1 + $jumpBy;
                if ($jumpBy > 0) {
                    $originalSuggested = $latestBuildNumber + 1;
                    $this->line("💡 Original suggested: {$originalSuggested}");
                    $this->line("🦘 Jumping by: {$jumpBy}");
                    $this->line("💡 Final suggested: {$suggested}");
                } else {
                    $this->line("💡 Suggested next: {$suggested}");
                }
                $this->line('🔧 To update: add --update flag');
            }
        } else {
            $baseSuggested = 1;
            $suggested = $baseSuggested + $jumpBy;
            $this->line('📱 Play Store: No releases found (new app)');
            $this->line('💻 Local current: '.($currentLocal ?: 'not set'));
            if ($jumpBy > 0) {
                $this->line("💡 Original suggested: {$baseSuggested}");
                $this->line("🦘 Jumping by: {$jumpBy}");
                $this->line("💡 Final suggested: {$suggested}");
            } else {
                $this->line("💡 Suggested next: {$suggested}");
            }
        }

        $this->newLine();
    }

    private function checkIosBuildNumber(): void
    {
        $this->info('🍎 Checking iOS (App Store Connect)...');

        $currentLocal = env('NATIVEPHP_APP_VERSION_CODE');

        // Suggesting a number without having reached Apple is worse than not
        // answering: "1" looks like a fresh app rather than a failed lookup.
        if (! $this->hasAppStoreConnectCredentials()) {
            $this->line('📱 App Store: not checked - no API credentials');
            $this->line('💻 Local current: '.($currentLocal ?: 'not set'));
            $this->line('🔑 Provide --api-key-path, --api-key-id and --api-issuer-id, or set');
            $this->line('   APP_STORE_API_KEY, APP_STORE_API_KEY_ID and APP_STORE_API_ISSUER_ID');
            $this->newLine();

            return;
        }

        $latestBuildNumber = $this->getLatestBuildNumberFromStore('ios');

        if ($latestBuildNumber !== null) {
            $this->line("📱 App Store latest: {$latestBuildNumber}");
            $this->line('💻 Local current: '.($currentLocal ?: 'not set'));

            if ($this->option('update')) {
                $this->updateBuildNumberFromStore('ios');
            } else {
                $suggested = $latestBuildNumber + 1;
                $this->line("💡 Suggested next: {$suggested}");
                $this->line('🔧 To update: add --update flag');
            }
        } else {
            // Reached Apple, found nothing. Build numbers only have to be unique
            // within one version string, so a new version really does start at 1.
            $version = config('nativephp.version');
            $this->line("📱 App Store: no builds yet for version {$version}");
            $this->line('💻 Local current: '.($currentLocal ?: 'not set'));
            $this->line('💡 Suggested next: 1');
        }

        $this->newLine();
    }
}
