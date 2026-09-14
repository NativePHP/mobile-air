<?php

declare(strict_types=1);

namespace Native\Mobile\Commands;

use Native\Mobile\Platform;

enum ScreenshotPlatform: string
{
    case Ios = Platform::IOS;
    case Android = Platform::ANDROID;

    /**
     * Parse a `native:screenshot {os}` argument, accepting the short
     * aliases (`i`/`a`) the rest of the `native:*` commands also accept.
     */
    public static function fromInput(string $value): ?self
    {
        return match (strtolower($value)) {
            'ios', 'i' => self::Ios,
            'android', 'a' => self::Android,
            default => null,
        };
    }
}
