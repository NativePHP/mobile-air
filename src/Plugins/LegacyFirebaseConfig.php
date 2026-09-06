<?php

namespace Native\Mobile\Plugins;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;

/**
 * Back-compat shim for Firebase plugins released before `project_files`.
 *
 * Core used to install the Firebase config itself — copying
 * google-services.json / GoogleService-Info.plist out of the project root
 * and, on Android, applying the Google Services Gradle plugin. That work now
 * belongs to the plugin that needs it, declared as `project_files` and
 * `gradle_plugins.apply_to` in its manifest.
 *
 * Dropping core's copy outright would silently break every app still on an
 * older plugin: the build still succeeds, push just stops arriving. So core
 * keeps doing the job, but only while nobody else has claimed it.
 *
 * The check is on capability, not version — "has some installed plugin
 * declared that it owns this destination?" — so a plugin release that has
 * not shipped yet takes over automatically, with no floor to bump here.
 *
 * Removing it: once the supported floor for Firebase plugins declares its own
 * project_files, delete this class along with its two call sites, and add the
 * matching `conflict` entry to composer.json in the same change. Not tagged
 * deprecated on purpose — nothing outside core may call it, and the tag makes
 * static analysis flag the call sites that are supposed to exist.
 */
class LegacyFirebaseConfig
{
    public const GRADLE_PLUGIN_ID = 'com.google.gms.google-services';

    /** Where core used to put each platform's config, relative to the native project. */
    private const DESTINATIONS = [
        'android' => 'app/google-services.json',
        'ios' => 'NativePHP/GoogleService-Info.plist',
    ];

    /** Where core used to look for it, relative to the project root. */
    private const SOURCES = [
        'android' => 'google-services.json',
        'ios' => 'GoogleService-Info.plist',
    ];

    private string $projectPath;

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
    ) {
        $this->projectPath = dirname(rtrim($basePath, '/\\'));
    }

    /**
     * Install the platform's Firebase config the way core used to.
     *
     * Returns the name of the plugin core covered for, so the caller can say
     * so, or null when the shim had nothing to do — which is the case for
     * every app whose plugin already owns its own config.
     */
    public function sync(Collection $plugins, string $platform): ?string
    {
        if (! isset(self::DESTINATIONS[$platform]) || $this->isClaimed($plugins, $platform)) {
            return null;
        }

        $plugin = $this->firebasePlugin($plugins, $platform);

        if ($plugin === null) {
            return null;
        }

        $source = $this->projectPath.'/'.self::SOURCES[$platform];

        // No config file is not an error: core's old copy was conditional
        // too, and Firebase was never going to work without one anyway.
        if (! $this->files->isFile($source)) {
            return null;
        }

        $target = $this->basePath.'/'.$platform.'/'.self::DESTINATIONS[$platform];
        $this->files->ensureDirectoryExists(dirname($target));
        $this->files->copy($source, $target);

        return $plugin->name;
    }

    /**
     * The nudge to show when the shim ran.
     */
    public static function deprecationNotice(string $pluginName, string $platform): string
    {
        $source = self::SOURCES[$platform] ?? 'its Firebase config';

        return "Plugin '{$pluginName}' relies on core to install {$source}. "
            ."Update it to a version that declares {$platform}.project_files — "
            .'core will stop doing this on its behalf.';
    }

    /**
     * Has an installed plugin declared that it owns this destination?
     */
    private function isClaimed(Collection $plugins, string $platform): bool
    {
        foreach ($plugins as $plugin) {
            foreach ($plugin->getProjectFiles($platform) as $declaration) {
                if (! is_array($declaration)) {
                    continue;
                }

                $destination = $declaration['destination'] ?? null;

                if (is_string($destination)
                    && $this->normalizePath($destination) === self::DESTINATIONS[$platform]) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Find an installed plugin that wants Firebase on this platform.
     *
     * Android has an unambiguous marker — the Google Services Gradle plugin.
     * iOS has no equivalent, so a Firebase CocoaPod stands in for one. Both
     * exist to keep a stray config file in a project with no Firebase plugin
     * from being copied anywhere.
     */
    private function firebasePlugin(Collection $plugins, string $platform): ?Plugin
    {
        return $plugins->first(fn (Plugin $plugin) => $platform === 'android'
            ? $this->declaresGoogleServicesPlugin($plugin)
            : $this->declaresFirebasePod($plugin));
    }

    private function declaresGoogleServicesPlugin(Plugin $plugin): bool
    {
        foreach ($plugin->getAndroidGradlePlugins() as $gradlePlugin) {
            if (is_array($gradlePlugin) && ($gradlePlugin['id'] ?? null) === self::GRADLE_PLUGIN_ID) {
                return true;
            }
        }

        return false;
    }

    private function declaresFirebasePod(Plugin $plugin): bool
    {
        foreach ($plugin->getIosDependencies()['pods'] ?? [] as $pod) {
            $name = is_array($pod) ? ($pod['name'] ?? null) : $pod;

            if (is_string($name) && str_starts_with($name, 'Firebase')) {
                return true;
            }
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
