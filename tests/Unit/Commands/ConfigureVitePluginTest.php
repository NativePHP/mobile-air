<?php

namespace Tests\Unit\Commands;

use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\File;
use Native\Mobile\Commands\InstallCommand;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class ConfigureVitePluginTest extends TestCase
{
    protected string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sprintf('%s/nativephp_vite_test_%s', sys_get_temp_dir(), uniqid());
        File::makeDirectory($this->basePath, 0755, true);
        app()->setBasePath($this->basePath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->basePath);
        parent::tearDown();
    }

    protected function configure(bool $confirm = true): object
    {
        $command = new class($confirm) extends InstallCommand
        {
            public function __construct(private bool $autoConfirm)
            {
                parent::__construct();
                $this->components = new Factory(new NullOutput);
            }

            public function executeConfigureVite(): void
            {
                $this->configureVitePlugin();
            }

            protected function shouldConfigureVite(): bool
            {
                return $this->autoConfirm;
            }

            public bool $manualInstructionsShown = false;

            protected function showViteManualInstructions(): void
            {
                $this->manualInstructionsShown = true;
            }
        };

        $command->setLaravel($this->app);
        $command->executeConfigureVite();

        return $command;
    }

    protected function writePackageJson(bool $withInertia = true): void
    {
        $deps = $withInertia ? '"@inertiajs/react": "^1.0.0"' : '"vue": "^3.0.0"';
        File::put(sprintf('%s/package.json', $this->basePath), sprintf('{"dependencies": {%s}}', $deps));
    }

    protected function writeViteConfig(string $ext = 'js'): string
    {
        $path = sprintf('%s/vite.config.%s', $this->basePath, $ext);
        File::put($path, $this->standardConfig());

        return $path;
    }

    protected function viteConfigPath(string $ext = 'js'): string
    {
        return sprintf('%s/vite.config.%s', $this->basePath, $ext);
    }

    protected function standardConfig(): string
    {
        return <<<'JS'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.tsx',
            refresh: true,
        }),
    ],
});
JS;
    }

    public function test_skips_without_package_json()
    {
        $path = $this->writeViteConfig();
        $this->configure();
        $this->assertEquals($this->standardConfig(), File::get($path));
    }

    public function test_skips_without_inertia()
    {
        $this->writePackageJson(withInertia: false);
        $path = $this->writeViteConfig();
        $this->configure();
        $this->assertEquals($this->standardConfig(), File::get($path));
    }

    public function test_skips_without_vite_config()
    {
        $this->writePackageJson();
        $this->configure();
        $this->assertFileDoesNotExist($this->viteConfigPath());
    }

    public function test_skips_when_already_configured()
    {
        $this->writePackageJson();
        $config = str_replace('import react', "import { nativephpMobile } from 'x';\nimport react", $this->standardConfig());
        File::put($this->viteConfigPath(), $config);

        $this->configure();
        $this->assertEquals($config, File::get($this->viteConfigPath()));
    }

    public function test_shows_manual_instructions_when_declined()
    {
        $this->writePackageJson();
        $this->writeViteConfig();
        $command = $this->configure(confirm: false);

        $this->assertTrue($command->manualInstructionsShown);
        $this->assertEquals($this->standardConfig(), File::get($this->viteConfigPath()));
    }

    public function test_injects_all_three_modifications()
    {
        $this->writePackageJson();
        $this->writeViteConfig();
        $this->configure();

        $result = File::get($this->viteConfigPath());
        $this->assertStringContainsString("import { nativephpMobile, nativephpHotFile } from './vendor/nativephp/mobile/resources/js/vite-plugin.js';", $result);
        $this->assertStringContainsString('hotFile: nativephpHotFile(),', $result);
        $this->assertStringContainsString('nativephpMobile(),', $result);
    }

    public function test_works_with_typescript_config()
    {
        $this->writePackageJson();
        $this->writeViteConfig('ts');
        $this->configure();

        $result = File::get($this->viteConfigPath('ts'));
        $this->assertStringContainsString('nativephpMobile()', $result);
        $this->assertStringContainsString('hotFile: nativephpHotFile(),', $result);
    }

    public function test_import_placed_after_last_import()
    {
        $this->writePackageJson();
        $this->writeViteConfig();
        $this->configure();

        $lines = explode("\n", File::get($this->viteConfigPath()));
        $lastImportIndex = 0;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, 'import ')) {
                $lastImportIndex = $i;
            }
        }
        $this->assertStringContainsString('nativephpMobile', $lines[$lastImportIndex]);
    }

    public function test_is_idempotent()
    {
        $this->writePackageJson();
        $this->writeViteConfig();

        $this->configure();
        $first = File::get($this->viteConfigPath());
        $this->configure();

        $this->assertEquals($first, File::get($this->viteConfigPath()));
    }

    public function test_no_temp_file_left_behind()
    {
        $this->writePackageJson();
        $this->writeViteConfig();
        $this->configure();

        $this->assertFileDoesNotExist(sprintf('%s/vite.config.nativephp-tmp.js', $this->basePath));
    }
}
