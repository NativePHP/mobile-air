<?php

namespace Tests\Unit\Plugins;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Native\Mobile\Plugins\IOS\ExtensionProvisioningProfileManager;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use Tests\TestCase;

class IOSExtensionProvisioningProfileManagerTest extends TestCase
{
    private Filesystem $files;

    private string $testPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem;
        $this->testPath = sys_get_temp_dir().'/nativephp-extension-profiles-'.uniqid();
        $this->files->ensureDirectoryExists($this->testPath);
        putenv(ExtensionProvisioningProfileManager::CONFIGURATION_ENV);
        putenv(ExtensionProvisioningProfileManager::METADATA_ENV);
    }

    protected function tearDown(): void
    {
        putenv(ExtensionProvisioningProfileManager::CONFIGURATION_ENV);
        putenv(ExtensionProvisioningProfileManager::METADATA_ENV);
        $this->files->deleteDirectory($this->testPath);

        parent::tearDown();
    }

    public function test_it_derives_full_bundle_ids_from_declared_extension_targets(): void
    {
        $manager = $this->manager();
        $plugins = collect([
            $this->plugin('nativephp/widgets', [['bundle_id_suffix' => 'widgets']]),
            $this->plugin('nativephp/status', [['name' => 'StatusExtension', 'bundle_id_suffix' => 'status-live']]),
        ]);

        $this->assertSame([
            'com.example.product.widgets',
            'com.example.product.status-live',
        ], $manager->bundleIds($plugins, 'com.example.product'));
    }

    public function test_it_rejects_cross_plugin_bundle_id_collisions_case_insensitively(): void
    {
        $plugins = collect([
            $this->plugin('nativephp/one', [['bundle_id_suffix' => 'Widgets']]),
            $this->plugin('nativephp/two', [['name' => 'OtherExtension', 'bundle_id_suffix' => 'widgets']]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate iOS extension bundle ID');

        $this->manager()->bundleIds($plugins, 'com.example.product');
    }

    public function test_it_installs_path_and_strict_base64_profiles_and_stores_only_metadata(): void
    {
        $pathContents = 'signed-profile-from-path';
        $base64Contents = 'signed-profile-from-base64';
        $profilePath = $this->testPath.'/widget.mobileprovision';
        $this->files->put($profilePath, $pathContents);

        $configuration = [
            'com.example.product.widgets' => $profilePath,
            'com.example.product.status' => base64_encode($base64Contents),
        ];
        putenv(ExtensionProvisioningProfileManager::CONFIGURATION_ENV.'='.json_encode($configuration));

        $firstUuid = 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE';
        $secondUuid = '11111111-2222-4333-8444-555555555555';
        $sequence = Process::sequence()
            ->push(Process::result(output: $this->profileXml('com.example.product.widgets', $firstUuid, 'Widget &amp; Profile')))
            ->push(Process::result(output: $this->profileXml('com.example.product.status', $secondUuid, 'Status Profile')));
        Process::fake(fn () => $sequence());

        $plugins = collect([$this->plugin('nativephp/widgets', [
            ['bundle_id_suffix' => 'widgets'],
            ['name' => 'StatusExtension', 'bundle_id_suffix' => 'status'],
        ])]);

        $installed = $this->manager()->install($plugins, 'com.example.product');

        $expected = [
            'com.example.product.widgets' => ['uuid' => $firstUuid, 'name' => 'Widget & Profile'],
            'com.example.product.status' => ['uuid' => $secondUuid, 'name' => 'Status Profile'],
        ];
        $this->assertSame($expected, $installed);
        $this->assertSame($expected, $this->manager()->installedProfiles());
        $this->assertSame($pathContents, $this->files->get($this->profilesPath().'/'.$firstUuid.'.mobileprovision'));
        $this->assertSame($base64Contents, $this->files->get($this->profilesPath().'/'.$secondUuid.'.mobileprovision'));

        $storedMetadata = (string) getenv(ExtensionProvisioningProfileManager::METADATA_ENV);
        $this->assertStringNotContainsString($profilePath, $storedMetadata);
        $this->assertStringNotContainsString(base64_encode($base64Contents), $storedMetadata);
        Process::assertRanTimes(
            fn ($process) => array_slice($process->command, 0, 4) === ['security', 'cms', '-D', '-i'],
            2,
        );
    }

    public function test_it_clears_stale_metadata_when_no_profiles_are_configured(): void
    {
        putenv(ExtensionProvisioningProfileManager::METADATA_ENV.'={"stale":"secret"}');

        $this->assertSame([], $this->manager()->install(collect(), 'com.example.product'));
        $this->assertSame('{}', getenv(ExtensionProvisioningProfileManager::METADATA_ENV));
        $this->assertSame([], $this->manager()->installedProfiles());
    }

    public function test_it_requires_an_object_with_every_declared_bundle_id_and_no_unknown_ids(): void
    {
        $plugin = $this->plugin('nativephp/widgets', [['bundle_id_suffix' => 'widgets']]);

        putenv(ExtensionProvisioningProfileManager::CONFIGURATION_ENV.'=[]');
        try {
            $this->manager()->install(collect([$plugin]), 'com.example.product');
            $this->fail('A list configuration should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('JSON object', $exception->getMessage());
        }

        putenv(ExtensionProvisioningProfileManager::CONFIGURATION_ENV.'={}');
        try {
            $this->manager()->install(collect([$plugin]), 'com.example.product');
            $this->fail('A missing profile should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Missing', $exception->getMessage());
        }

        $unknown = ['com.example.product.other' => base64_encode('profile')];
        putenv(ExtensionProvisioningProfileManager::CONFIGURATION_ENV.'='.json_encode($unknown));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('undeclared');
        $this->manager()->install(collect(), 'com.example.product');
    }

    public function test_it_rejects_noncanonical_or_whitespace_base64(): void
    {
        $configuration = ['com.example.product.widgets' => base64_encode('profile')."\n"];
        putenv(ExtensionProvisioningProfileManager::CONFIGURATION_ENV.'='.json_encode($configuration));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('strict base64');

        $this->manager()->install(
            collect([$this->plugin('nativephp/widgets', [['bundle_id_suffix' => 'widgets']])]),
            'com.example.product',
        );
    }

    public function test_it_validates_the_exact_profile_application_identifier_before_installing(): void
    {
        $configuration = ['com.example.product.widgets' => base64_encode('signed-profile')];
        putenv(ExtensionProvisioningProfileManager::CONFIGURATION_ENV.'='.json_encode($configuration));
        Process::fake([
            '*' => Process::result(output: $this->profileXml(
                'com.example.product.wrong',
                'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE',
                'Wrong Profile',
            )),
        ]);

        try {
            $this->manager()->install(
                collect([$this->plugin('nativephp/widgets', [['bundle_id_suffix' => 'widgets']])]),
                'com.example.product',
            );
            $this->fail('A mismatched profile should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('does not exactly match', $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->profilesPath());
        $this->assertSame('{}', getenv(ExtensionProvisioningProfileManager::METADATA_ENV));
        $this->assertSame([], $this->manager()->installedProfiles());
    }

    public function test_it_rejects_invalid_mobileprovision_and_internal_metadata(): void
    {
        $configuration = ['com.example.product.widgets' => base64_encode('not-a-profile')];
        putenv(ExtensionProvisioningProfileManager::CONFIGURATION_ENV.'='.json_encode($configuration));
        Process::fake(['*' => Process::result(errorOutput: 'decode failed', exitCode: 1)]);

        try {
            $this->manager()->install(
                collect([$this->plugin('nativephp/widgets', [['bundle_id_suffix' => 'widgets']])]),
                'com.example.product',
            );
            $this->fail('An invalid mobileprovision should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('not a valid mobileprovision', $exception->getMessage());
        }

        putenv(ExtensionProvisioningProfileManager::METADATA_ENV.'={"com.example.product.widgets":{"uuid":"bad","name":"Profile","secret":"value"}}');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid profile metadata');
        $this->manager()->installedProfiles();
    }

    private function manager(): ExtensionProvisioningProfileManager
    {
        return new ExtensionProvisioningProfileManager($this->files, $this->profilesPath());
    }

    /**
     * @param  list<array<string, string>>  $targets
     */
    private function plugin(string $name, array $targets): Plugin
    {
        $targets = array_map(fn (array $target, int $index): array => [
            'name' => $target['name'] ?? 'WidgetExtension'.($index + 1),
            'type' => 'widget-extension',
            'bundle_id_suffix' => $target['bundle_id_suffix'],
            'sources_dir' => 'extension-'.($index + 1),
        ], $targets, array_keys($targets));

        return new Plugin($name, '1.0.0', $this->testPath, new PluginManifest([
            'namespace' => 'NativePHPWidgets',
            'ios' => ['extension_targets' => $targets],
        ]));
    }

    private function profilesPath(): string
    {
        return $this->testPath.'/installed-profiles';
    }

    private function profileXml(string $bundleId, string $uuid, string $name): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<plist version="1.0"><dict>
<key>UUID</key><string>{$uuid}</string>
<key>Name</key><string>{$name}</string>
<key>ExpirationDate</key><date>2030-01-01T00:00:00Z</date>
<key>ApplicationIdentifierPrefix</key><array><string>TEAMID1234</string></array>
<key>Entitlements</key><dict>
<key>application-identifier</key><string>TEAMID1234.{$bundleId}</string>
<key>com.apple.developer.team-identifier</key><string>TEAMID1234</string>
</dict>
</dict></plist>
XML;
    }
}
