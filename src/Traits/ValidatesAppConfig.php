<?php

namespace Native\Mobile\Traits;

trait ValidatesAppConfig
{
    private function validateAppVersion(string $buildType): void
    {
        $appVersion = config('nativephp.version');

        // Check if this is a release build
        if ($buildType === 'release') {
            if ($appVersion === 'DEBUG') {
                \Laravel\Prompts\error('Cannot create release build with DEBUG version!');
                $this->line('Please set a proper version using: php artisan native:release patch|minor|major');
                $this->line('Or manually update NATIVEPHP_APP_VERSION in your .env file');
                exit(1);
            }

            if (empty($appVersion)) {
                \Laravel\Prompts\error('NATIVEPHP_APP_VERSION is not set!');
                $this->line('Please set a version using: php artisan native:release patch|minor|major');
                $this->line('Or manually add NATIVEPHP_APP_VERSION to your .env file');
                exit(1);
            }

            $this->components->twoColumnDetail('Release version', $appVersion);
        }
    }

    protected function validateAppId(): void
    {
        $appId = config('nativephp.app_id');

        if (str($appId)->isEmpty()) {
            \Laravel\Prompts\error('Set your NATIVEPHP_APP_ID');
            $this->line('Please add a NATIVEPHP_APP_ID to your .env file (e.g. com.example.myapp).');
            exit(1);
        }

        if (str($appId)->startsWith('com.nativephp.')) {
            \Laravel\Prompts\warning('Please change your NATIVEPHP_APP_ID. Must not contain "nativephp"');
        }
    }
}
