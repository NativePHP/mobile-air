<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Native\Mobile\Traits\ManagesViteDevServer;
use Native\Mobile\Traits\ManagesWatchman;
use Native\Mobile\Traits\RunsIos;
use Native\Mobile\Traits\WatchesAndroid;
use Native\Mobile\Traits\WatchesIos;

use function Laravel\Prompts\select;

class WatchCommand extends Command
{
    use ManagesViteDevServer, ManagesWatchman, RunsIos, WatchesAndroid, WatchesIos;

    protected $signature = 'native:watch
        {platform? : Platform to watch (android/a or ios/i)}
        {--ios : Target iOS platform (shorthand for platform=ios)}
        {--android : Target Android platform (shorthand for platform=android)}
        {target? : The device/simulator UDID to watch}';

    protected $description = 'Watch for file changes and sync to running mobile app';

    public function handle(): int
    {
        if (! $this->checkWatchmanDependencies()) {
            return self::FAILURE;
        }

        // Get platform (flags take priority over argument)
        if ($this->option('ios')) {
            $platform = 'ios';
        } elseif ($this->option('android')) {
            $platform = 'android';
        } else {
            $platform = $this->argument('platform');

            if (! $platform) {
                $platform = select(
                    label: 'Select platform to watch',
                    options: [
                        'ios' => 'iOS',
                        'android' => 'Android',
                    ]
                );
            } else {
                // Support shorthands: 'a' for android, 'i' for ios
                $platform = match(strtolower($platform)) {
                    'android', 'a' => 'android',
                    'ios', 'i' => 'ios',
                    default => $platform,
                };
            }
        }

        $targetUdid = $this->argument('target');

        if ($platform === 'ios') {
            $this->startIosHotReload($targetUdid);
        } elseif ($platform === 'android') {
            $this->startAndroidHotReload();
        } else {
            $this->error('Invalid platform. Use: ios, android (or i, a as shortcuts)');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
