<?php

namespace Native\Mobile\Commands;

use Illuminate\Console\Command;
use Native\Mobile\Concerns\ManagesViteDevServer;
use Native\Mobile\Concerns\ManagesWatchman;
use Native\Mobile\Concerns\RunsIos;
use Native\Mobile\Concerns\WatchesAndroid;
use Native\Mobile\Concerns\WatchesIos;

use function Laravel\Prompts\select;

class WatchCommand extends Command
{
    use ManagesViteDevServer, ManagesWatchman, RunsIos, WatchesAndroid, WatchesIos;

    protected $signature = 'native:watch
        {platform? : Platform to watch (android/a or ios/i)}
        {--ios : Target iOS platform (shorthand for platform=ios)}
        {--android : Target Android platform (shorthand for platform=android)}
        {--vite : Start the Vite dev server (opt-in; off by default)}
        {--no-vite : Force-disable the Vite dev server (redundant — this is the default)}
        {target? : The device/simulator UDID to watch}';

    protected $description = 'Watch for file changes and sync to running mobile app';

    public function handle(): int
    {
        // Get platform (flags take priority over argument)
        if ($this->option('ios')) {
            $platform = 'ios';
        } elseif ($this->option('android')) {
            $platform = 'android';
        } else {
            $platform = $this->argument('platform');

            if (! $platform) {
                // iOS watching needs the Xcode toolchain, so only offer it on macOS
                if (PHP_OS_FAMILY !== 'Darwin') {
                    $platform = 'android';
                } else {
                    $platform = select(
                        label: 'Select platform to watch',
                        options: [
                            'ios' => 'iOS',
                            'android' => 'Android',
                        ]
                    );
                }
            } else {
                // Support shorthands: 'a' for android, 'i' for ios
                $platform = match (strtolower($platform)) {
                    'android', 'a' => 'android',
                    'ios', 'i' => 'ios',
                    default => $platform,
                };
            }
        }

        // iOS watching depends on the Xcode toolchain (xcrun), which only exists
        // on macOS — fail fast with a clear error for explicit --ios / ios / i
        if ($platform === 'ios' && PHP_OS_FAMILY !== 'Darwin') {
            $this->error('Watching iOS apps requires macOS (Xcode toolchain).');

            return self::FAILURE;
        }

        $targetUdid = $this->argument('target');

        if ($platform === 'ios') {
            $this->startIosHotReload($targetUdid);
        } elseif ($platform === 'android') {
            $this->startAndroidHotReload($targetUdid);
        } else {
            $this->error('Invalid platform. Use: ios, android (or i, a as shortcuts)');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
