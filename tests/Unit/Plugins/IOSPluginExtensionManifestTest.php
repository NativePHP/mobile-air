<?php

namespace Tests\Unit\Plugins;

use InvalidArgumentException;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IOSPluginExtensionManifestTest extends TestCase
{
    public function test_it_exposes_widget_extension_targets(): void
    {
        $manifest = new PluginManifest($this->manifest());
        $plugin = new Plugin('nativephp/widgets', '1.0.0', __DIR__, $manifest);

        $targets = $plugin->getIosExtensionTargets();

        $this->assertCount(1, $targets);
        $this->assertSame('NativePHPWidgetsExtension', $targets[0]['name']);
        $this->assertSame('widget-extension', $targets[0]['type']);
    }

    public function test_it_defaults_to_no_extension_targets(): void
    {
        $plugin = new Plugin(
            'nativephp/example',
            '1.0.0',
            __DIR__,
            new PluginManifest(['namespace' => 'Example'])
        );

        $this->assertSame([], $plugin->getIosExtensionTargets());
    }

    #[DataProvider('invalidTargets')]
    public function test_it_rejects_invalid_extension_targets(array $target, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new PluginManifest($this->manifest([$target]));
    }

    public function test_it_rejects_duplicate_target_names_and_bundle_suffixes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate');

        $target = $this->manifest()['ios']['extension_targets'][0];

        new PluginManifest($this->manifest([$target, $target]));
    }

    public function test_it_rejects_case_insensitive_target_collisions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate');

        $target = $this->manifest()['ios']['extension_targets'][0];
        $duplicate = [
            ...$target,
            'name' => strtolower($target['name']),
            'bundle_id_suffix' => 'another-widget',
        ];

        new PluginManifest($this->manifest([$target, $duplicate]));
    }

    public function test_it_rejects_a_null_extension_target_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a list');

        $manifest = $this->manifest();
        $manifest['ios']['extension_targets'] = null;

        new PluginManifest($manifest);
    }

    public function test_it_rejects_a_non_object_ios_configuration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ios must be an object');

        new PluginManifest([
            'namespace' => 'NativePHPWidgets',
            'ios' => 'invalid',
        ]);
    }

    public function test_it_validates_extension_targets_after_legacy_manifest_normalization(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sources_dir');

        new PluginManifest([
            'namespace' => 'NativePHPWidgets',
            'manifest' => [
                'ios' => [
                    'extension_targets' => [[...$this->manifest()['ios']['extension_targets'][0], 'sources_dir' => '../extension']],
                ],
            ],
        ]);
    }

    public static function invalidTargets(): array
    {
        $valid = [
            'name' => 'NativePHPWidgetsExtension',
            'type' => 'widget-extension',
            'bundle_id_suffix' => 'widgets',
            'deployment_target' => '17.0',
            'sources_dir' => 'extension',
        ];

        return [
            'unsupported type' => [[...$valid, 'type' => 'share-extension'], 'widget-extension'],
            'missing name' => [[...$valid, 'name' => ''], 'name'],
            'unsafe source path' => [[...$valid, 'sources_dir' => '../extension'], 'sources_dir'],
            'absolute source path' => [[...$valid, 'sources_dir' => '/extension'], 'sources_dir'],
            'invalid bundle suffix' => [[...$valid, 'bundle_id_suffix' => '.widgets'], 'bundle_id_suffix'],
            'old deployment target' => [[...$valid, 'deployment_target' => '13.0'], 'deployment_target'],
            'invalid plist' => [[...$valid, 'info_plist' => 'invalid'], 'info_plist'],
            'invalid extension plist' => [[...$valid, 'info_plist' => ['NSExtension' => 'invalid']], 'NSExtension'],
            'list extension plist' => [[...$valid, 'info_plist' => ['NSExtension' => ['invalid']]], 'NSExtension'],
            'null extension point' => [[...$valid, 'info_plist' => ['NSExtension' => ['NSExtensionPointIdentifier' => null]]], 'WidgetKit extension point'],
            'managed plist key' => [[...$valid, 'info_plist' => ['CFBundleIdentifier' => 'invalid']], 'compiler-managed'],
            'source path whitespace' => [[...$valid, 'sources_dir' => ' extension '], 'whitespace'],
        ];
    }

    private function manifest(?array $targets = null): array
    {
        return [
            'namespace' => 'NativePHPWidgets',
            'ios' => [
                'min_version' => '17.0',
                'extension_targets' => $targets ?? [[
                    'name' => 'NativePHPWidgetsExtension',
                    'type' => 'widget-extension',
                    'bundle_id_suffix' => 'widgets',
                    'deployment_target' => '17.0',
                    'sources_dir' => 'extension',
                ]],
            ],
        ];
    }
}
