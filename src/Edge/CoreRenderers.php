<?php

namespace Native\Mobile\Edge;

/**
 * The element types core renders natively itself, rather than through a UI
 * plugin. This is the single list both sides agree on:
 *
 * - iOS registers exactly IOS in `BridgeFunctionRegistration.swift`.
 * - Android registers exactly ANDROID in `NativeChromeRendererRegistration.kt`.
 * - PluginRegistry::detectConflicts() refuses a plugin that declares a
 *   component for any of them, so a plugin can't swap out the root chrome.
 *
 * CoreRenderersTest reads the Swift and Kotlin sources and fails when they
 * drift from these lists.
 *
 * Plugins (mobile-ui, for one) do provide the native renderers for most of
 * core's Element classes: `text`, `column`, `top_bar` and so on. Those are not
 * listed here and are not conflicts.
 */
final class CoreRenderers
{
    public const OWNER = 'core';

    /** @var list<string> */
    public const IOS = [
        'native_root_stack',
        'native_root_tabs',
        'bottom_bar',
    ];

    /** @var list<string> */
    public const ANDROID = [
        'native_root_stack',
        'native_root_tabs',
        'tab_accessory',
    ];

    /**
     * Every type core keeps for itself, on either platform.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return array_values(array_unique([...self::IOS, ...self::ANDROID]));
    }

    public static function owns(string $type): bool
    {
        return in_array($type, self::types(), true);
    }
}
