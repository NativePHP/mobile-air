<?php

namespace Tests\Unit\Plugins;

use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use PHPUnit\Framework\TestCase;

/**
 * Env-gated feature bundles: a plugin declares optional chunks of itself
 * under `features`, each behind one environment variable. An enabled
 * bundle folds into the manifest's own sections before anything reads
 * them, so the compilers stay feature-agnostic; a disabled one leaves no
 * trace — no SDK products, no Gradle coordinates, and crucially no bridge
 * registrations pointing at classes that were never compiled.
 */
class PluginFeatureGateTest extends TestCase
{
    /**
     * @param  array<string, string|null>  $env
     */
    private function manifest(array $features, array $env = [], array $base = []): PluginManifest
    {
        return new PluginManifest(
            array_merge([
                'namespace' => 'Firebase',
                'bridge_functions' => [
                    ['name' => 'PushNotification.GetToken', 'ios' => 'PushNotificationFunctions.GetToken'],
                ],
                'ios' => [
                    'dependencies' => [
                        'swift_packages' => [
                            ['url' => 'https://github.com/firebase/firebase-ios-sdk', 'version' => '12.6.0', 'products' => ['FirebaseCore']],
                        ],
                    ],
                ],
                'android' => [
                    'dependencies' => ['implementation' => ['com.google.firebase:firebase-messaging']],
                ],
                'features' => $features,
            ], $base),
            fn (string $key) => $env[$key] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function crashlyticsFeature(): array
    {
        return [
            'crashlytics' => [
                'env' => 'NATIVEPHP_FIREBASE_CRASHLYTICS',
                'bridge_functions' => [
                    ['name' => 'Crashlytics.RecordException', 'ios' => 'CrashlyticsFunctions.RecordException'],
                ],
                'events' => ['Native\\Mobile\\Events\\Crashlytics\\Reported'],
                'ios' => [
                    'dependencies' => [
                        'swift_packages' => [
                            ['url' => 'https://github.com/firebase/firebase-ios-sdk', 'products' => ['FirebaseCrashlytics']],
                        ],
                    ],
                ],
                'android' => [
                    'dependencies' => ['implementation' => ['com.google.firebase:firebase-crashlytics']],
                    'gradle_plugins' => [['id' => 'com.google.firebase.crashlytics', 'version' => '3.0.2']],
                ],
            ],
        ];
    }

    /** @test */
    public function a_disabled_feature_leaves_no_trace_in_the_manifest(): void
    {
        $manifest = $this->manifest($this->crashlyticsFeature());

        $this->assertSame([], $manifest->enabledFeatures);
        $this->assertCount(1, $manifest->bridgeFunctions);
        $this->assertSame('PushNotification.GetToken', $manifest->bridgeFunctions[0]['name']);
        $this->assertSame(
            ['FirebaseCore'],
            $manifest->ios['dependencies']['swift_packages'][0]['products']
        );
        $this->assertSame(
            ['com.google.firebase:firebase-messaging'],
            $manifest->android['dependencies']['implementation']
        );
        $this->assertArrayNotHasKey('gradle_plugins', $manifest->android);
    }

    /** @test */
    public function an_enabled_feature_merges_into_every_section(): void
    {
        $manifest = $this->manifest(
            $this->crashlyticsFeature(),
            ['NATIVEPHP_FIREBASE_CRASHLYTICS' => 'true'],
        );

        $this->assertSame(['crashlytics'], $manifest->enabledFeatures);

        $names = array_column($manifest->bridgeFunctions, 'name');
        $this->assertSame(['PushNotification.GetToken', 'Crashlytics.RecordException'], $names);

        $this->assertContains(
            'com.google.firebase:firebase-crashlytics',
            $manifest->android['dependencies']['implementation']
        );
        $this->assertSame(
            [['id' => 'com.google.firebase.crashlytics', 'version' => '3.0.2']],
            $manifest->android['gradle_plugins']
        );
        $this->assertSame(['Native\\Mobile\\Events\\Crashlytics\\Reported'], $manifest->events);
    }

    /**
     * The iOS compiler resolves a repeated package url to the existing
     * XCRemoteSwiftPackageReference and attaches the extra products, so a
     * feature declares the same url carrying only what it needs.
     *
     * @test
     */
    public function a_feature_adds_swift_products_to_the_package_it_shares(): void
    {
        $manifest = $this->manifest(
            $this->crashlyticsFeature(),
            ['NATIVEPHP_FIREBASE_CRASHLYTICS' => 'true'],
        );

        $packages = $manifest->ios['dependencies']['swift_packages'];

        $this->assertCount(2, $packages);
        $this->assertSame('https://github.com/firebase/firebase-ios-sdk', $packages[1]['url']);
        $this->assertSame(['FirebaseCrashlytics'], $packages[1]['products']);
    }

    /** @test */
    public function falsy_env_values_keep_the_feature_off(): void
    {
        foreach (['false', '0', '', null] as $value) {
            $manifest = $this->manifest(
                $this->crashlyticsFeature(),
                ['NATIVEPHP_FIREBASE_CRASHLYTICS' => $value],
            );

            $this->assertSame([], $manifest->enabledFeatures, 'Value: '.var_export($value, true));
        }
    }

    /** @test */
    public function a_feature_without_an_env_key_is_always_on(): void
    {
        $manifest = $this->manifest([
            'always' => [
                'bridge_functions' => [['name' => 'Always.Ping', 'ios' => 'AlwaysFunctions.Ping']],
            ],
        ]);

        $this->assertSame(['always'], $manifest->enabledFeatures);
        $this->assertCount(2, $manifest->bridgeFunctions);
    }

    /** @test */
    public function map_valued_platform_keys_merge_key_wise(): void
    {
        $manifest = $this->manifest(
            [
                'analytics' => [
                    'env' => 'F',
                    'ios' => ['info_plist' => ['FirebaseAnalyticsCollectionEnabled' => false]],
                ],
            ],
            ['F' => '1'],
            ['ios' => ['info_plist' => ['FirebaseAppDelegateProxyEnabled' => false]]],
        );

        $this->assertSame([
            'FirebaseAppDelegateProxyEnabled' => false,
            'FirebaseAnalyticsCollectionEnabled' => false,
        ], $manifest->ios['info_plist']);
    }

    /** @test */
    public function features_are_absent_from_a_manifest_that_declares_none(): void
    {
        $manifest = new PluginManifest(['namespace' => 'Plain']);

        $this->assertSame([], $manifest->features);
        $this->assertSame([], $manifest->enabledFeatures);
    }

    /** @test */
    public function feature_source_paths_are_listed_only_for_enabled_features(): void
    {
        $pluginPath = sys_get_temp_dir().'/nativephp-feature-gate-'.bin2hex(random_bytes(4));
        mkdir($pluginPath.'/resources/features/crashlytics/ios', 0777, true);
        mkdir($pluginPath.'/resources/features/crashlytics/android', 0777, true);

        try {
            $off = new Plugin(
                name: 'nativephp/mobile-firebase',
                version: '1.0.0',
                path: $pluginPath,
                manifest: $this->manifest($this->crashlyticsFeature()),
            );

            $this->assertSame([], $off->getFeatureSourcePaths('ios'));
            $this->assertSame([], $off->getFeatureSourcePaths('android'));

            $on = new Plugin(
                name: 'nativephp/mobile-firebase',
                version: '1.0.0',
                path: $pluginPath,
                manifest: $this->manifest(
                    $this->crashlyticsFeature(),
                    ['NATIVEPHP_FIREBASE_CRASHLYTICS' => 'true'],
                ),
            );

            $this->assertSame(
                [$pluginPath.'/resources/features/crashlytics/ios'],
                $on->getFeatureSourcePaths('ios')
            );
            $this->assertSame(
                [$pluginPath.'/resources/features/crashlytics/android'],
                $on->getFeatureSourcePaths('android')
            );
        } finally {
            exec('rm -rf '.escapeshellarg($pluginPath));
        }
    }
}
