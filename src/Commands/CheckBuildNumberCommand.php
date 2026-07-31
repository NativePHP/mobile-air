<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Native\Mobile\Traits\ChecksLatestBuildNumber;
use Native\Mobile\Traits\PublishesToPlayStore;

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

        $latestBuildNumber = $this->getLatestBuildNumberFromStore('ios');
        $currentLocal = env('NATIVEPHP_APP_VERSION_CODE');

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
            $this->line('📱 App Store: No releases found or check not implemented');
            $this->line('💻 Local current: '.($currentLocal ?: 'not set'));
            $this->line('💡 Suggested next: 1');
        }

        $this->newLine();
    }
}
