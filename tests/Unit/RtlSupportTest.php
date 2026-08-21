<?php

namespace Tests\Unit;

use Native\Mobile\Support\Rtl;
use Tests\TestCase;

class RtlSupportTest extends TestCase
{
    public function test_rtl_support_defaults_to_false()
    {
        $this->assertFalse(config('nativephp.rtl_support'));
    }

    public function test_localizations_defaults_to_an_empty_array()
    {
        $this->assertSame([], config('nativephp.localizations'));
    }

    /**
     * @testWith ["ar", true]
     *           ["ar-SA", true]
     *           ["ar-AE", true]
     *           ["he", true]
     *           ["he-IL", true]
     *           ["fa", true]
     *           ["fa-IR", true]
     *           ["ur", true]
     *           ["ur-PK", true]
     *           ["en", false]
     *           ["en-US", false]
     *           ["fr-FR", false]
     *           ["en-SA", false]
     */
    public function test_rtl_language_detection(string $locale, bool $expected): void
    {
        $this->assertSame($expected, Rtl::isRtlLanguage($locale));
        $this->assertSame($expected, Rtl::isRtlLocale($locale));
    }

    /**
     * @testWith ["ar-SA", "ar"]
     *           ["ar-AE", "ar"]
     *           ["he-IL", "he"]
     *           ["fa-IR", "fa"]
     *           ["ur-PK", "ur"]
     *           ["en-US", "en"]
     *           ["fr-FR", "fr"]
     *           ["zh-Hans-CN", "zh"]
     *           ["ar_SA", "ar"]
     *           ["ar", "ar"]
     */
    public function test_base_language_extraction(string $locale, string $expected): void
    {
        $this->assertSame($expected, Rtl::baseLanguage($locale));
    }

    public function test_empty_locale_has_no_base_language(): void
    {
        $this->assertSame('', Rtl::baseLanguage(''));
        $this->assertFalse(Rtl::isRtlLanguage(''));
    }

    public function test_base_language_is_case_insensitive(): void
    {
        $this->assertSame('ar', Rtl::baseLanguage('AR-SA'));
        $this->assertTrue(Rtl::isRtlLanguage('AR-sa'));
    }
}
