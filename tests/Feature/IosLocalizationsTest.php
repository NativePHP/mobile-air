<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Native\Mobile\Commands\BuildIosAppCommand;
use ReflectionClass;
use Tests\TestCase;

/**
 * `nativephp.localizations` is written into the generated Info.plist as
 * CFBundleLocalizations so iOS recognizes the languages the app supports.
 * When unset, the key is left out entirely so existing builds don't change.
 */
class IosLocalizationsTest extends TestCase
{
    protected string $plistPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plistPath = sys_get_temp_dir().'/nativephp_localizations_test_'.uniqid().'.plist';
    }

    protected function tearDown(): void
    {
        File::delete($this->plistPath);

        parent::tearDown();
    }

    public function test_configured_localizations_are_written_to_the_plist(): void
    {
        config(['nativephp.localizations' => ['en', 'ar']]);

        $this->updatePlist($this->writePlist());

        $this->assertStringContainsString('<key>CFBundleLocalizations</key>', $this->plist());
        $this->assertStringContainsString('<string>en</string>', $this->plist());
        $this->assertStringContainsString('<string>ar</string>', $this->plist());
    }

    public function test_no_localizations_leaves_the_key_out(): void
    {
        config(['nativephp.localizations' => []]);

        $this->updatePlist($this->writePlist());

        $this->assertStringNotContainsString('CFBundleLocalizations', $this->plist());
    }

    public function test_unset_localizations_leaves_the_key_out(): void
    {
        config(['nativephp.localizations' => null]);

        $this->updatePlist($this->writePlist());

        $this->assertStringNotContainsString('CFBundleLocalizations', $this->plist());
    }

    public function test_an_existing_key_is_replaced_rather_than_duplicated(): void
    {
        config(['nativephp.localizations' => ['en', 'ar']]);

        $this->updatePlist($this->writePlist(
            "\t<key>CFBundleLocalizations</key>\n\t<array>\n\t\t<string>fr</string>\n\t</array>\n"
        ));

        $this->assertSame(1, substr_count($this->plist(), 'CFBundleLocalizations'));
        $this->assertStringContainsString('<string>en</string>', $this->plist());
        $this->assertStringContainsString('<string>ar</string>', $this->plist());
        $this->assertStringNotContainsString('<string>fr</string>', $this->plist());
    }

    public function test_clearing_localizations_removes_a_previously_written_key(): void
    {
        config(['nativephp.localizations' => []]);

        $this->updatePlist($this->writePlist(
            "\t<key>CFBundleLocalizations</key>\n\t<array>\n\t\t<string>en</string>\n\t\t<string>ar</string>\n\t</array>\n"
        ));

        $this->assertStringNotContainsString('CFBundleLocalizations', $this->plist());
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
