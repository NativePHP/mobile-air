<?php

namespace Native\Mobile\Concerns;

use Native\Mobile\Plugins\PluginHookRunner;
use Native\Mobile\Plugins\PluginRegistry;

/**
 * Shared by the Android and iOS bundle-staging pipelines to run the
 * prepare_bundle hook with an identically-shaped context on both platforms.
 */
trait RunsBundleHooks
{
    /**
     * Run the prepare_bundle hook for all registered plugins against a
     * fully-staged Laravel bundle tree. Throws if a hook fails, aborting
     * the build before the tree is archived.
     */
    protected function runPrepareBundleHook(string $platform, string $buildPath, string $bundlePath, string $buildType, string $bundleVersionId): void
    {
        $plugins = app(PluginRegistry::class);

        if ($plugins->count() === 0) {
            return;
        }

        $hookRunner = new PluginHookRunner(
            platform: $platform,
            buildPath: $buildPath,
            appId: config('nativephp.app_id', ''),
            config: [
                'version' => config('nativephp.version'),
                'version_code' => config('nativephp.version_code'),
                'build_type' => $buildType,
                'release' => $buildType !== 'debug',
                'bundle_version_id' => $bundleVersionId,
            ],
            plugins: $plugins->all(),
            output: $this->output ?? null
        );

        $hookRunner->runPrepareBundleHooks($bundlePath);
    }
}
