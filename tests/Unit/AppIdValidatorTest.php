<?php

namespace Tests\Unit;

use Native\Mobile\Support\AppIdValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppIdValidatorTest extends TestCase
{
    // --- Android validation ---

    #[DataProvider('validAndroidIds')]
    public function test_valid_android_ids(string $appId): void
    {
        $this->assertNull(AppIdValidator::validateForAndroid($appId));
    }

    public static function validAndroidIds(): array
    {
        return [
            'simple two segments' => ['com.example'],
            'three segments' => ['com.example.myapp'],
            'with underscore' => ['com.example.my_app'],
            'with digits' => ['com.example.app123'],
            'uppercase' => ['com.Example.MyApp'],
            'many segments' => ['com.example.sub.package.app'],
        ];
    }

    #[DataProvider('invalidAndroidIds')]
    public function test_invalid_android_ids(string $appId): void
    {
        $this->assertNotNull(AppIdValidator::validateForAndroid($appId));
    }

    public static function invalidAndroidIds(): array
    {
        return [
            'empty string' => [''],
            'single segment' => ['myapp'],
            'hyphen in segment' => ['com.example.my-app'],
            'segment starts with digit' => ['com.123example.app'],
            'empty segment (double dot)' => ['com..example'],
            'trailing dot' => ['com.example.'],
            'leading dot' => ['.com.example'],
            'spaces' => ['com .example'],
            'special characters' => ['com.example.my@app'],
        ];
    }

    // --- iOS validation ---

    #[DataProvider('validIosIds')]
    public function test_valid_ios_ids(string $appId): void
    {
        $this->assertNull(AppIdValidator::validateForIos($appId));
    }

    public static function validIosIds(): array
    {
        return [
            'simple two segments' => ['com.example'],
            'three segments' => ['com.example.myapp'],
            'with hyphen' => ['com.example.my-app'],
            'with digits' => ['com.example.app123'],
            'segment starts with digit' => ['com.1example.app'],
            'uppercase' => ['com.Example.MyApp'],
            'many segments' => ['com.example.sub.package.app'],
        ];
    }

    #[DataProvider('invalidIosIds')]
    public function test_invalid_ios_ids(string $appId): void
    {
        $this->assertNotNull(AppIdValidator::validateForIos($appId));
    }

    public static function invalidIosIds(): array
    {
        return [
            'empty string' => [''],
            'single segment' => ['myapp'],
            'empty segment (double dot)' => ['com..example'],
            'trailing dot' => ['com.example.'],
            'leading dot' => ['.com.example'],
            'segment starts with hyphen' => ['com.-example.app'],
            'segment ends with hyphen' => ['com.example-.app'],
            'underscore' => ['com.example.my_app'],
        ];
    }

    // --- Cross-platform hyphens ---

    public function test_hyphens_rejected_for_android_allowed_for_ios(): void
    {
        $appId = 'com.japseyz.ikast-musikliv';

        $this->assertNotNull(AppIdValidator::validateForAndroid($appId));
        $this->assertNull(AppIdValidator::validateForIos($appId));
    }

    // --- Cross-platform validate() ---

    public function test_validate_returns_both_platforms(): void
    {
        $result = AppIdValidator::validate('com.example.myapp');

        $this->assertArrayHasKey('android', $result);
        $this->assertArrayHasKey('ios', $result);
        $this->assertNull($result['android']);
        $this->assertNull($result['ios']);
    }

    public function test_validate_returns_errors_for_both_platforms(): void
    {
        // Single segment is invalid for both
        $result = AppIdValidator::validate('myapp');

        $this->assertNotNull($result['android']);
        $this->assertNotNull($result['ios']);
    }

    public function test_validate_returns_android_error_only_for_hyphens(): void
    {
        $result = AppIdValidator::validate('com.example.my-app');

        $this->assertNotNull($result['android']);
        $this->assertNull($result['ios']);
    }

    // --- validateForPrompt() ---

    public function test_validate_for_prompt_returns_null_for_valid(): void
    {
        $this->assertNull(AppIdValidator::validateForPrompt('com.example.myapp'));
    }

    public function test_validate_for_prompt_returns_string_for_invalid(): void
    {
        $result = AppIdValidator::validateForPrompt('com.example.my-app');

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_validate_for_prompt_returns_android_error_first(): void
    {
        // Hyphens fail Android but pass iOS — prompt should return the Android error
        $result = AppIdValidator::validateForPrompt('com.example.my-app');

        $this->assertStringContainsString('Android', $result);
    }
}
