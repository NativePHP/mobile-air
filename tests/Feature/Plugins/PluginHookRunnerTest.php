<?php

namespace Tests\Feature\Plugins;

use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use Native\Mobile\Plugins\Exceptions\PluginHookFailedException;
use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginHookRunner;
use Native\Mobile\Plugins\PluginManifest;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Feature tests for PluginHookRunner.
 *
 * A hook stages what a plugin needs to work, so the build has to say when
 * one fails. Every assertion here is about that being visible: the runner
 * writes to a real OutputStyle, the shape the build actually hands it.
 */
class PluginHookRunnerTest extends TestCase
{
    private BufferedOutput $buffer;

    private OutputStyle $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buffer = new BufferedOutput;
        $this->output = new OutputStyle(new ArrayInput([]), $this->buffer);
    }

    /** Register a hook command that returns the given exit code. */
    private function registerHook(string $signature, int $exitCode, string $say = ''): void
    {
        Artisan::registerCommand(new ClosureCommand(
            $signature.' {--platform=} {--build-path=} {--plugin-path=} {--app-id=} {--config=} {--plugins=}',
            function () use ($exitCode, $say) {
                if ($say !== '') {
                    $this->line($say);
                }

                return $exitCode;
            }
        ));
    }

    private function runnerFor(string $hookCommand): PluginHookRunner
    {
        $plugin = new Plugin(
            name: 'vendor/probe',
            path: sys_get_temp_dir(),
            version: '1.0.0',
            manifest: new PluginManifest([
                'namespace' => 'Probe',
                'hooks' => ['copy_assets' => $hookCommand],
            ]),
        );

        return new PluginHookRunner(
            platform: 'android',
            buildPath: sys_get_temp_dir(),
            appId: 'com.example.probe',
            config: [],
            plugins: collect([$plugin]),
            output: $this->output,
        );
    }

    public function test_it_announces_the_hook_it_is_running(): void
    {
        $this->registerHook('probe:hook-ok', 0);

        $this->runnerFor('probe:hook-ok')->runCopyAssetsHooks();

        $this->assertStringContainsString('Running copy_assets hook', $this->buffer->fetch());
    }

    public function test_it_surfaces_output_the_hook_writes(): void
    {
        $this->registerHook('probe:hook-talks', 0, 'staging prebuilt libraries');

        $this->runnerFor('probe:hook-talks')->runCopyAssetsHooks();

        $this->assertStringContainsString('staging prebuilt libraries', $this->buffer->fetch());
    }

    public function test_it_fails_the_build_when_a_hook_exits_non_zero(): void
    {
        $this->registerHook('probe:hook-fails', 1, 'download failed: HTTP 404');

        $this->expectException(PluginHookFailedException::class);
        $this->expectExceptionMessage('Hook copy_assets for vendor/probe failed');

        $this->runnerFor('probe:hook-fails')->runCopyAssetsHooks();
    }

    public function test_it_reports_a_hook_command_that_does_not_exist(): void
    {
        $this->expectException(PluginHookFailedException::class);

        $this->runnerFor('probe:hook-missing')->runCopyAssetsHooks();
    }

    public function test_a_plugin_without_the_hook_is_left_alone(): void
    {
        $plugin = new Plugin(
            name: 'vendor/no-hooks',
            path: sys_get_temp_dir(),
            version: '1.0.0',
            manifest: new PluginManifest(['namespace' => 'NoHooks']),
        );

        $runner = new PluginHookRunner(
            platform: 'android',
            buildPath: sys_get_temp_dir(),
            appId: 'com.example.probe',
            config: [],
            plugins: collect([$plugin]),
            output: $this->output,
        );

        $runner->runCopyAssetsHooks();

        $this->assertSame('', $this->buffer->fetch());
    }
}
