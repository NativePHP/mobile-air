<?php

use Native\Mobile\Facades\System;

/**
 * Global convenience helpers over the core System facade. Thin, guarded with
 * function_exists so an app defining its own never collides. Autoloaded via
 * composer `autoload.files`.
 */
if (! function_exists('isDark')) {
    /** Is the device currently in dark mode? */
    function isDark(): bool
    {
        return System::isDarkMode();
    }
}

if (! function_exists('theme')) {
    /**
     * Resolve a theme value for the current appearance from the native-ui theme
     * config — the single source of truth the renderers also read. e.g.
     * `theme('primary')` returns `config('native-ui.theme.dark.primary')` in
     * dark mode (…`light`… otherwise). Returns $default when the key is unset
     * (or native-ui isn't installed), so callers expecting a string can pass a
     * fallback instead of risking a null into a typed setter like activeColor().
     */
    function theme(string $modifier, mixed $default = null): mixed
    {
        $mode = isDark() ? 'dark' : 'light';

        return config("native-ui.theme.$mode.$modifier", $default);
    }
}

if (! function_exists('isLight')) {
    /** Is the device currently in light mode? */
    function isLight(): bool
    {
        return System::isLightMode();
    }
}

if (! function_exists('isIos')) {
    function isIos(): bool
    {
        return System::isIos();
    }
}

if (! function_exists('isAndroid')) {
    function isAndroid(): bool
    {
        return System::isAndroid();
    }
}

if (! function_exists('isMobile')) {
    function isMobile(): bool
    {
        return System::isMobile();
    }
}
