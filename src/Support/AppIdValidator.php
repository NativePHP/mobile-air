<?php

namespace Native\Mobile\Support;

class AppIdValidator
{
    /**
     * Validate an app ID for Android (Java package name rules).
     * Each segment must start with a letter and contain only letters, digits, and underscores.
     */
    public static function validateForAndroid(string $appId): ?string
    {
        if (trim($appId) === '') {
            return 'App ID cannot be empty.';
        }

        $segments = explode('.', $appId);

        if (count($segments) < 2) {
            return 'App ID must have at least two segments (e.g. com.example).';
        }

        foreach ($segments as $segment) {
            if ($segment === '') {
                return 'App ID must not contain empty segments.';
            }

            if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $segment)) {
                return "Invalid Android app ID segment \"{$segment}\". Each segment must start with a letter and contain only letters, digits, and underscores. Hyphens are not allowed.";
            }
        }

        return null;
    }

    /**
     * Validate an app ID for iOS (bundle identifier rules).
     * Each segment must start and end with an alphanumeric character, and may contain hyphens in between.
     */
    public static function validateForIos(string $appId): ?string
    {
        if (trim($appId) === '') {
            return 'App ID cannot be empty.';
        }

        $segments = explode('.', $appId);

        if (count($segments) < 2) {
            return 'App ID must have at least two segments (e.g. com.example).';
        }

        foreach ($segments as $segment) {
            if ($segment === '') {
                return 'App ID must not contain empty segments.';
            }

            if (! preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?$/', $segment)) {
                return "Invalid iOS bundle ID segment \"{$segment}\". Each segment must start and end with a letter or digit, and may contain hyphens in between.";
            }
        }

        return null;
    }

    /**
     * Validate an app ID for both platforms.
     *
     * @return array{android: string|null, ios: string|null}
     */
    public static function validate(string $appId): array
    {
        return [
            'android' => static::validateForAndroid($appId),
            'ios' => static::validateForIos($appId),
        ];
    }

    /**
     * Validate an app ID for use in a prompt validation closure.
     * Returns the first error found (Android rules are strictest), or null if valid for both platforms.
     */
    public static function validateForPrompt(string $appId): ?string
    {
        return static::validateForAndroid($appId) ?? static::validateForIos($appId);
    }
}
