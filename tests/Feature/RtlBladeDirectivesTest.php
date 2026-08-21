<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class RtlBladeDirectivesTest extends TestCase
{
    public function test_native_head_outputs_language_and_direction_for_an_rtl_locale(): void
    {
        app()->setLocale('ar');

        $html = Blade::render('<html @nativeHead><head></head><body></body></html>');

        $this->assertStringContainsString('lang="ar" dir="rtl"', $html);
    }

    public function test_native_head_outputs_ltr_for_an_ltr_locale(): void
    {
        app()->setLocale('en');

        $html = Blade::render('<html @nativeHead></html>');

        $this->assertStringContainsString('lang="en" dir="ltr"', $html);
    }

    public function test_native_head_uses_the_base_language_for_variant_locales(): void
    {
        app()->setLocale('ar-SA');

        $html = Blade::render('<html @nativeHead></html>');

        $this->assertStringContainsString('lang="ar-SA" dir="rtl"', $html);
    }

    public function test_rtl_directive_renders_the_rtl_branch_for_an_rtl_locale(): void
    {
        app()->setLocale('ar');

        $html = Blade::render(<<<'BLADE'
        @rtl
        RTL
        @else
        LTR
        @endrtl
        BLADE);

        $this->assertStringContainsString('RTL', $html);
        $this->assertStringNotContainsString('LTR', $html);
    }

    public function test_rtl_directive_renders_the_else_branch_for_an_ltr_locale(): void
    {
        app()->setLocale('en');

        $html = Blade::render(<<<'BLADE'
        @rtl
        RTL
        @else
        LTR
        @endrtl
        BLADE);

        $this->assertStringContainsString('LTR', $html);
        $this->assertStringNotContainsString('RTL', $html);
    }
}
