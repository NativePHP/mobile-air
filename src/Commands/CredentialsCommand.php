<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Native\Mobile\Traits\CreatesAndroidCredentials;
use Native\Mobile\Traits\CreatesIosCredentials;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;

class CredentialsCommand extends Command
{
    use CreatesAndroidCredentials, CreatesIosCredentials {
        CreatesAndroidCredentials::addCredentialsToGitignore insteadof CreatesIosCredentials;
    }

    protected $signature = 'native:credentials {platform? : The platform to generate credentials for (android/a, ios/i, or both)} {--reset : Generate new keystore and PEM certificate for Google Play Console reset}';

    protected $description = 'Generate credentials for iOS and Android platforms';

    public function handle(): void
    {
        intro('🔐 Generating native credentials...');

        $platform = $this->argument('platform');

        if ($platform) {
            $platform = match (strtolower($platform)) {
                'a' => 'android',
                'i' => 'ios',
                default => $platform,
            };
        }

        if ($platform && ! in_array($platform, ['android', 'ios', 'both'])) {
            $this->error('Invalid platform. Please specify "android" (a), "ios" (i), or "both".');

            return;
        }

        $choice = $platform ?: select(
            label: 'Which platform credentials do you want to generate?',
            options: [
                'android' => 'Android (JKS keystore)',
                'ios' => 'iOS (CSR for certificates)',
                'both' => 'Both',
            ],
            default: 'both'
        );

        if ($choice === 'android' || $choice === 'both') {
            if ($this->option('reset')) {
                $this->generateAndroidKeystoreReset();
            } else {
                $this->generateAndroidCredentials();
            }
        }

        if ($choice === 'ios' || $choice === 'both') {
            $this->generateIosCredentials();
        }

        outro('🎉 Credentials generated successfully!');
    }
}
