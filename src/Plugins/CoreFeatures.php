<?php

namespace Native\Mobile\Plugins;

use Illuminate\Support\Collection;

/**
 * Core code that is only compiled into an app when a particular plugin is
 * installed.
 *
 * The code lives in core because it has to run before any plugin does (OTA
 * payloads are applied at boot, before PHP starts). But an app that never
 * installs the plugin should not ship it. An update-and-run-new-code path,
 * or a "leave this demo app" gesture, is the kind of thing App Review's
 * automated scan is likely to flag, and NativePHP apps keep being asked for
 * a demo video before anyone at Apple looks at them.
 *
 * iOS gets each enabled feature as a Swift compilation condition
 * (NATIVEPHP_OTA), so the code is not in the binary at all. Android gets a
 * generated PluginFeatures object of constants that switch the code off at
 * runtime. The code itself stays in the APK there: the ProGuard rule that
 * keeps com.**.bridge.** keeps every member of LaravelEnvironment.
 */
class CoreFeatures
{
    /**
     * Plugin package => feature name.
     */
    public const PLUGINS = [
        'nativephp/mobile-ota' => 'OTA',
        'nativephp/mobile-discovery' => 'JUMP',
    ];

    /**
     * Every feature, and whether the installed plugins switch it on.
     *
     * @param  Collection<int, Plugin>  $plugins
     * @return array<string, bool>
     */
    public static function for(Collection $plugins): array
    {
        $installed = $plugins->map(fn (Plugin $plugin) => $plugin->name)->all();

        $features = [];

        foreach (self::PLUGINS as $package => $feature) {
            $features[$feature] = in_array($package, $installed, true);
        }

        return $features;
    }

    /**
     * The Swift compilation condition for a feature.
     */
    public static function swiftCondition(string $feature): string
    {
        return 'NATIVEPHP_'.$feature;
    }
}
