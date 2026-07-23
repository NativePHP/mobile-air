<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;

/**
 * Stubs for the abstract and undeclared methods required by PreparesBuild.
 */
trait MocksPreparesBuildDependencies
{
    protected function info($message) {}

    protected function warn($message) {}

    protected function error($message) {}

    protected function line($message) {}

    protected function newLine() {}

    protected function logToFile(string $message): void {}

    protected function removeDirectory(string $path): void
    {
        if (is_dir($path)) {
            File::deleteDirectory($path);
        }
    }

    protected function platformOptimizedCopy(string $source, string $destination, array $excludedDirs): void {}

    protected function detectCurrentAppId(): ?string
    {
        return null;
    }

    protected function updateAppId(string $oldAppId, string $newAppId): void {}

    protected function updateLocalProperties(): void {}

    protected function updateVersionConfiguration(): void {}

    protected function updateAppDisplayName(): void {}

    protected function updateDeepLinkConfiguration(): void {}

    protected function updatePermissions(): void {}

    protected function updateIcuConfiguration(): void {}

    protected function updateFirebaseConfiguration(): void {}

    protected function updateOrientationConfiguration(): void {}
}
