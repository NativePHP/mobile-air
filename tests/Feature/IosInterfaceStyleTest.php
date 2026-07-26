<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Native\Mobile\Commands\BuildIosAppCommand;
use ReflectionClass;
use Tests\TestCase;

/**
 * `nativephp.appearance` pins the app to a single interface style by writing
 * UIUserInterfaceStyle into the Info.plist. Theme tokens can't reach the
 * system's own chrome — Liquid Glass bars, sheets, keyboards, the window
 * background behind the safe areas — so a fixed-identity app needs the plist
 * key or those surfaces follow the device instead.
 */
class IosInterfaceStyleTest extends TestCase
{
    protected string $plistPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plistPath = sys_get_temp_dir().'/nativephp_interface_style_test_'.uniqid().'.plist';
    }

    protected function tearDown(): void
    {
        File::delete($this->plistPath);

        parent::tearDown();
    }

    public function test_dark_appearance_writes_the_interface_style_key(): void
    {
        config(['nativephp.appearance' => 'dark']);

        $this->updatePlist($this->writePlist());

        $this->assertStringContainsString('<key>UIUserInterfaceStyle</key>', $this->plist());
        $this->assertStringContainsString('<string>Dark</string>', $this->plist());
    }

    public function test_light_appearance_writes_the_interface_style_key(): void
    {
        config(['nativephp.appearance' => 'light']);

        $this->updatePlist($this->writePlist());

        $this->assertStringContainsString('<string>Light</string>', $this->plist());
    }

    public function test_appearance_is_matched_case_insensitively(): void
    {
        config(['nativephp.appearance' => 'DARK']);

        $this->updatePlist($this->writePlist());

        $this->assertStringContainsString('<string>Dark</string>', $this->plist());
    }

    public function test_system_appearance_leaves_the_key_out(): void
    {
        config(['nativephp.appearance' => 'system']);

        $this->updatePlist($this->writePlist());

        $this->assertStringNotContainsString('UIUserInterfaceStyle', $this->plist());
    }

    public function test_unset_appearance_leaves_the_key_out(): void
    {
        config(['nativephp.appearance' => null]);

        $this->updatePlist($this->writePlist());

        $this->assertStringNotContainsString('UIUserInterfaceStyle', $this->plist());
    }

    /**
     * Switching an app back to `system` has to REMOVE a key an earlier build
     * wrote — the plist is updated in place, not regenerated from the stub.
     */
    public function test_switching_back_to_system_removes_a_previously_written_key(): void
    {
        config(['nativephp.appearance' => 'system']);

        $this->updatePlist($this->writePlist(
            "\t<key>UIUserInterfaceStyle</key>\n\t<string>Dark</string>\n"
        ));

        $this->assertStringNotContainsString('UIUserInterfaceStyle', $this->plist());
        $this->assertStringNotContainsString('<string>Dark</string>', $this->plist());
    }

    public function test_an_existing_key_is_updated_rather_than_duplicated(): void
    {
        config(['nativephp.appearance' => 'dark']);

        $this->updatePlist($this->writePlist(
            "\t<key>UIUserInterfaceStyle</key>\n\t<string>Light</string>\n"
        ));

        $this->assertSame(1, substr_count($this->plist(), 'UIUserInterfaceStyle'));
        $this->assertStringContainsString('<string>Dark</string>', $this->plist());
        $this->assertStringNotContainsString('<string>Light</string>', $this->plist());
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
