<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Native\Mobile\Commands\BuildIosAppCommand;
use ReflectionClass;
use Tests\TestCase;

/**
 * The in-app hot-reload server binds a host-wide port — a simulator shares the
 * host's localhost, and physical devices are tunnelled to the same host port by
 * iproxy — so two apps that both want hot reload need different ports.
 *
 * Swift can't read the Laravel config, so `nativephp.hot_reload.port` is baked
 * into Info.plist at build time and read back from the bundle at launch. If the
 * key stops being written the app silently falls back to 9999 and reload
 * triggers land on whichever app got there first.
 */
class IosHotReloadPortTest extends TestCase
{
    protected string $plistPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plistPath = sys_get_temp_dir().'/nativephp_hot_reload_port_test_'.uniqid().'.plist';
    }

    protected function tearDown(): void
    {
        File::delete($this->plistPath);

        parent::tearDown();
    }

    public function test_the_configured_port_is_written_to_the_plist(): void
    {
        config(['nativephp.hot_reload.port' => 9998]);

        $this->updatePlist($this->writePlist());

        $this->assertStringContainsString('<key>NATIVEPHP_HOT_RELOAD_PORT</key>', $this->plist());
        $this->assertStringContainsString('<string>9998</string>', $this->plist());
    }

    /** An app whose published config predates the key still has to build. */
    public function test_an_unset_port_falls_back_to_the_default(): void
    {
        config(['nativephp.hot_reload.port' => null]);

        $this->updatePlist($this->writePlist());

        $this->assertStringContainsString('<string>9999</string>', $this->plist());
    }

    /**
     * The plist is updated in place rather than regenerated, so changing the
     * port between builds must overwrite the key — a duplicate would leave the
     * app reading whichever copy Bundle.main returns first.
     */
    public function test_an_existing_key_is_updated_rather_than_duplicated(): void
    {
        config(['nativephp.hot_reload.port' => 9998]);

        $this->updatePlist($this->writePlist(
            "\t<key>NATIVEPHP_HOT_RELOAD_PORT</key>\n\t<string>9999</string>\n"
        ));

        $this->assertSame(1, substr_count($this->plist(), 'NATIVEPHP_HOT_RELOAD_PORT'));
        $this->assertStringContainsString('<string>9998</string>', $this->plist());
        $this->assertStringNotContainsString('<string>9999</string>', $this->plist());
    }

    /** Write a minimal Info.plist, optionally with extra keys already in it. */
    protected function writePlist(string $extraKeys = ''): string
    {
        File::put($this->plistPath, <<<PLIST
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0">
        <dict>
        \t<key>CFBundleURLTypes</key>
        \t<array>
        \t\t<dict>
        \t\t\t<key>CFBundleTypeRole</key>
        \t\t\t<string>Viewer</string>
        \t\t\t<key>CFBundleURLName</key>
        \t\t\t<string>com.nativephp.app</string>
        \t\t\t<key>CFBundleURLSchemes</key>
        \t\t\t<array>
        \t\t\t\t<string>nativephp</string>
        \t\t\t</array>
        \t\t</dict>
        \t</array>
        {$extraKeys}</dict>
        </plist>
        PLIST);

        return $this->plistPath;
    }

    protected function updatePlist(string $path): void
    {
        $command = new BuildIosAppCommand;

        (new ReflectionClass($command))
            ->getMethod('updateInfoPlistFile')
            ->invoke($command, $path, 'com.nativephp.test', 'nativephp');
    }

    protected function plist(): string
    {
        return File::get($this->plistPath);
    }
}
