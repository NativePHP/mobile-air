<?php

namespace Tests\Unit\Plugins;

use InvalidArgumentException;
use Native\Mobile\Plugins\Compilers\IOS\ManifestValueResolver;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginManifest;
use PHPUnit\Framework\TestCase;

class IOSManifestValueResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('WIDGET_PUBLIC_KEY');
        putenv('CI_SIGNING_SECRET');

        parent::tearDown();
    }

    public function test_it_resolves_the_app_id_and_declared_plugin_secrets(): void
    {
        putenv('WIDGET_PUBLIC_KEY=public-widget-value');
        $resolver = ManifestValueResolver::forPlugin(
            'com.example.app',
            $this->plugin(['WIDGET_PUBLIC_KEY'])
        );

        $this->assertSame([
            'group' => 'group.com.example.app.widgets',
            'key' => 'public-widget-value',
        ], $resolver->resolve([
            'group' => 'group.${APP_ID}.widgets',
            'key' => '${WIDGET_PUBLIC_KEY}',
        ]));
    }

    public function test_it_refuses_to_read_an_undeclared_build_environment_variable(): void
    {
        putenv('CI_SIGNING_SECRET=must-not-leak');
        $resolver = ManifestValueResolver::forPlugin('com.example.app', $this->plugin([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not declared in the plugin manifest secrets allowlist');

        $resolver->resolve('${CI_SIGNING_SECRET}');
    }

    public function test_it_fails_when_a_declared_environment_variable_is_unavailable(): void
    {
        $resolver = ManifestValueResolver::forPlugin(
            'com.example.app',
            $this->plugin(['WIDGET_PUBLIC_KEY'])
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('declared by the plugin but is not available');

        $resolver->resolve('${WIDGET_PUBLIC_KEY}');
    }

    /** @param list<string> $secrets */
    private function plugin(array $secrets): Plugin
    {
        return new Plugin(
            'nativephp/widgets',
            '1.0.0',
            __DIR__,
            new PluginManifest([
                'namespace' => 'NativePHPWidgets',
                'secrets' => $secrets,
            ])
        );
    }
}
