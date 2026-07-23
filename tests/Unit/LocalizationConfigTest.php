<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Native\Mobile\Traits\PreparesBuild;
use Tests\Concerns\MocksPreparesBuildDependencies;
use Tests\TestCase;

class LocalizationConfigTest extends TestCase
{
    use MocksPreparesBuildDependencies;
    use PreparesBuild {
        updateLocalizationConfiguration as public runUpdateLocalizationConfiguration;
    }

    protected string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testProjectPath = sys_get_temp_dir().'/nativephp_localization_test_'.uniqid();
        app()->setBasePath($this->testProjectPath);

        $this->createDirectoryStructure($this->testProjectPath, [
            'nativephp/android/app/src/main' => [
                'AndroidManifest.xml' => '<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <application android:label="NativePHP">
    </application>
</manifest>',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testProjectPath);
        parent::tearDown();
    }

    // iOS

    public function test_ios_injects_cf_bundle_localizations_into_plist()
    {
        $path = $this->writePlist('<key>CFBundleIdentifier</key><string>com.test.app</string>');

        $this->injectLocalizations($path, ['en', 'fr', 'es']);

        $content = file_get_contents($path);
        $this->assertStringContainsString('<string>en</string>', $content);
        $this->assertStringContainsString('<string>fr</string>', $content);
        $this->assertStringContainsString('<string>es</string>', $content);
        $this->assertStringContainsString('CFBundleIdentifier', $content);
    }

    public function test_ios_replaces_existing_cf_bundle_localizations()
    {
        $path = $this->writePlist(
            '<key>CFBundleLocalizations</key><array><string>en</string></array>'
        );

        $this->injectLocalizations($path, ['fr', 'de']);

        $content = file_get_contents($path);
        $this->assertStringContainsString('<string>fr</string>', $content);
        $this->assertStringContainsString('<string>de</string>', $content);
        $this->assertStringNotContainsString('<string>en</string>', $content);
    }

    // Android

    public function test_android_generates_locales_config_and_updates_manifest()
    {
        config(['nativephp.locales' => ['en', 'fr', 'es']]);
        $this->runUpdateLocalizationConfiguration();

        $xml = File::get($this->xmlPath());
        $this->assertStringContainsString('<locale android:name="en"/>', $xml);
        $this->assertStringContainsString('<locale android:name="fr"/>', $xml);
        $this->assertStringContainsString('<locale android:name="es"/>', $xml);
        $this->assertStringContainsString('android:localeConfig="@xml/locales_config"', File::get($this->manifestPath()));
    }

    public function test_android_skips_when_fewer_than_two_locales()
    {
        config(['nativephp.locales' => ['en']]);
        $this->runUpdateLocalizationConfiguration();

        $this->assertFileDoesNotExist($this->xmlPath());
        $this->assertStringNotContainsString('localeConfig', File::get($this->manifestPath()));
    }

    public function test_android_cleans_up_when_locales_removed()
    {
        config(['nativephp.locales' => ['en', 'fr']]);
        $this->runUpdateLocalizationConfiguration();
        $this->assertFileExists($this->xmlPath());

        config(['nativephp.locales' => []]);
        $this->runUpdateLocalizationConfiguration();

        $this->assertFileDoesNotExist($this->xmlPath());
        $this->assertStringNotContainsString('localeConfig', File::get($this->manifestPath()));
    }

    public function test_android_does_not_duplicate_locale_config_attribute()
    {
        config(['nativephp.locales' => ['en', 'fr']]);
        $this->runUpdateLocalizationConfiguration();
        $this->runUpdateLocalizationConfiguration();

        $this->assertEquals(1, substr_count(File::get($this->manifestPath()), 'android:localeConfig'));
    }

    private function manifestPath(): string
    {
        return $this->testProjectPath.'/nativephp/android/app/src/main/AndroidManifest.xml';
    }

    private function xmlPath(): string
    {
        return $this->testProjectPath.'/nativephp/android/app/src/main/res/xml/locales_config.xml';
    }

    private function writePlist(string $dictContent): string
    {
        $path = $this->testProjectPath.'/Info.plist';
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>'.$dictContent.'</dict></plist>');

        return $path;
    }

    private function injectLocalizations(string $filePath, array $locales): void
    {
        $dom = new \DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->load($filePath);

        $rootDict = $dom->getElementsByTagName('dict')->item(0);
        $existing = null;

        foreach ($rootDict->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child->nodeName === 'key' && $child->nodeValue === 'CFBundleLocalizations') {
                $existing = $child->nextSibling;
                while ($existing && $existing->nodeType !== XML_ELEMENT_NODE) {
                    $existing = $existing->nextSibling;
                }
                break;
            }
        }

        if ($existing) {
            while ($existing->firstChild) {
                $existing->removeChild($existing->firstChild);
            }
            $arrayNode = $existing;
        } else {
            $rootDict->appendChild($dom->createElement('key', 'CFBundleLocalizations'));
            $arrayNode = $dom->createElement('array');
            $rootDict->appendChild($arrayNode);
        }

        foreach ($locales as $locale) {
            $arrayNode->appendChild($dom->createElement('string', $locale));
        }

        file_put_contents($filePath, $dom->saveXML());
    }
}
