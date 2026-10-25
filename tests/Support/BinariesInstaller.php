<?php

namespace Tests\Support;

use GuzzleHttp\Client;
use Native\Mobile\Traits\InstallsAndroid;
use Native\Mobile\Traits\InstallsIos;
use Native\Mobile\Traits\PlatformFileOperations;

/**
 * Runs the real binary installers outside InstallCommand, with the parts of
 * the console a test doesn't care about stubbed out.
 */
class BinariesInstaller
{
    use InstallsAndroid, InstallsIos, PlatformFileOperations;

    public string $phpVersion = '8.4';

    public ?array $versionsManifest = null;

    protected bool $forcing = true;

    public object $components;

    /** Downloads go through this when set, so a test can serve the archive. */
    public ?Client $client = null;

    public function __construct()
    {
        $this->components = new class
        {
            public function task(string $title, callable $callback): mixed
            {
                return $callback();
            }

            public function twoColumnDetail(mixed ...$args): void {}

            public function warn(mixed ...$args): void {}
        };
    }

    public function option(string $key): mixed
    {
        return false;
    }

    public function installIos(): void
    {
        $this->iosPath = base_path('nativephp/ios');
        $this->installPHPIos();
    }

    protected function binariesClient(): Client
    {
        return $this->client ?? new Client;
    }
}
