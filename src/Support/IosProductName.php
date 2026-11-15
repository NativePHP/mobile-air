<?php

namespace Native\Mobile\Support;

use Illuminate\Support\Str;

/**
 * The PRODUCT_NAME of the iOS app target: the name of the built bundle
 * ("My App.app") and of its executable.
 *
 * The template's target is "NativePHP", so without this every app shipped
 * as NativePHP.app. The value lands in project.pbxproj and in file paths,
 * so it keeps to ASCII letters, digits, spaces, dots, dashes and
 * underscores (no leading or trailing dot, dash or space), and falls back
 * to "NativePHP" when nothing is left.
 */
class IosProductName
{
    public static function resolve(): string
    {
        $name = (string) (config('nativephp.ios.product_name') ?: config('app.name'));

        $name = preg_replace('/[^A-Za-z0-9 ._-]+/', '', Str::ascii($name));
        $name = trim(preg_replace('/ +/', ' ', $name), ' .-');

        return $name !== '' ? $name : 'NativePHP';
    }
}
