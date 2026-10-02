<?php

namespace Tests\Unit\Support;

use Native\Mobile\Support\SupportedLocales;
use PHPUnit\Framework\TestCase;

/**
 * Resolution of the one list both compilers build everything else from.
 *
 * Kept container-free on purpose: the rules here — base language first, no
 * duplicates, nothing unvalidated reaching an XML attribute — are the whole
 * contract, and they are much cheaper to pin down than through a compiler
 * standing up a fake Gradle or Xcode project.
 */
final class SupportedLocalesTest extends TestCase
{
    /** @test */
    public function an_app_that_declares_nothing_supports_its_own_language(): void
    {
        $this->assertSame(['en'], (new SupportedLocales([], 'en'))->all());
        $this->assertFalse((new SupportedLocales([], 'en'))->offersChoice());
    }

    /** @test */
    public function the_base_language_leads_the_list(): void
    {
        $locales = new SupportedLocales(['fr', 'nl'], 'en');

        $this->assertSame(['en', 'fr', 'nl'], $locales->all());
        $this->assertSame('en', $locales->base());
        $this->assertTrue($locales->offersChoice());
    }

    /** @test */
    public function declaring_the_base_language_does_not_list_it_twice(): void
    {
        $this->assertSame(['fr', 'nl'], (new SupportedLocales(['nl', 'fr'], 'fr'))->all());
    }

    /** @test */
    public function laravel_style_identifiers_become_bcp_47(): void
    {
        $this->assertSame(['en', 'nl-NL', 'pt-BR'], (new SupportedLocales(['nl_NL', 'pt_BR'], 'en'))->all());
    }

    /** @test */
    public function the_same_language_spelled_two_ways_is_listed_once(): void
    {
        $this->assertSame(['en', 'pt-BR'], (new SupportedLocales(['pt-BR', 'pt_br'], 'en'))->all());
    }

    /** @test */
    public function entries_that_are_not_locales_are_dropped_and_reported(): void
    {
        $locales = new SupportedLocales(['fr', 'not a locale', '', ['nl'], 12], 'en');

        $this->assertSame(['en', 'fr'], $locales->all());
        $this->assertSame(['not a locale', 'array', '12'], $locales->invalid());
        $this->assertNotEmpty($locales->warnings(''));
    }

    /** @test */
    public function allows_answers_for_locales_declared_elsewhere(): void
    {
        $locales = new SupportedLocales(['zh-Hans'], 'en');

        $this->assertTrue($locales->allows('en'));
        $this->assertTrue($locales->allows('zh-Hans'));
        $this->assertTrue($locales->allows('zh_hans'));
        $this->assertFalse($locales->allows('nl'));
    }

    /** @test */
    public function translations_without_a_declaration_are_named(): void
    {
        $langPath = sys_get_temp_dir().'/nativephp-lang-'.uniqid();

        mkdir($langPath.'/de', 0777, true);
        mkdir($langPath.'/vendor/some-package', 0777, true);
        touch($langPath.'/fr.json');
        touch($langPath.'/en.json');

        $locales = new SupportedLocales(['fr'], 'en');

        $this->assertSame(['de'], $locales->undeclared($langPath));

        $warnings = $locales->warnings($langPath);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('de', $warnings[0]);

        // A fully declared app says nothing.
        $this->assertSame([], (new SupportedLocales(['fr', 'de'], 'en'))->undeclared($langPath));

        exec('rm -rf '.escapeshellarg($langPath));
    }

    /** @test */
    public function a_missing_lang_directory_is_not_a_problem(): void
    {
        $this->assertSame([], (new SupportedLocales(['fr'], 'en'))->undeclared('/nope/not/here'));
    }
}
