<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ReleaseCommand extends Command
{
    protected $signature = 'native:release {type : The type of release (patch, minor, major)}';

    protected $description = 'Bump the version number in .env file';

    public function handle(): void
    {
        $type = $this->argument('type');

        if (! in_array($type, ['patch', 'minor', 'major'])) {
            $this->error('❌ Invalid release type. Use: patch, minor, or major');

            return;
        }

        $currentVersion = config('nativephp.version');
        $newVersion = $this->bumpVersion($currentVersion, $type);

        $this->updateEnvFile($currentVersion, $newVersion);

        $this->info("✅ Version bumped: {$currentVersion} → {$newVersion}");
    }

    private function bumpVersion(string $currentVersion, string $type): string
    {
        // Parse version parts
        $parts = explode('.', $currentVersion);

        // Ensure we have at least major.minor.patch
        while (count($parts) < 3) {
            $parts[] = '0';
        }

        [$major, $minor, $patch] = array_map('intval', $parts);

        // Bump according to type
        switch ($type) {
            case 'major':
                $major++;
                $minor = 0;
                $patch = 0;
                break;
            case 'minor':
                $minor++;
                $patch = 0;
                break;
            case 'patch':
                $patch++;
                break;
        }

        return "{$major}.{$minor}.{$patch}";
    }

    private function updateEnvFile(string $currentVersion, string $newVersion): void
    {
        $envPath = base_path('.env');
        $envContent = File::get($envPath);

        if (str_contains($envContent, 'NATIVEPHP_APP_VERSION=')) {
            // Update existing version
            $updatedContent = preg_replace(
                '/^NATIVEPHP_APP_VERSION=.+$/m',
                "NATIVEPHP_APP_VERSION={$newVersion}",
                $envContent
            );
        } else {
            // Add new version line
            $updatedContent = $envContent."\nNATIVEPHP_APP_VERSION={$newVersion}\n";
        }

        File::put($envPath, $updatedContent);
    }
}
