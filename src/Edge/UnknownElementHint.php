<?php

namespace Native\Mobile\Edge;

use Native\Mobile\Plugins\Plugin;
use Native\Mobile\Plugins\PluginRegistry;

/**
 * Works out what to tell a developer about an element type nothing has
 * registered: which package provides it and what to run, or what a
 * renamed element is called now.
 */
class UnknownElementHint
{
    /**
     * Element types shipped by first-party plugins, so the hint works even
     * when the plugin is not installed at all. Types core registers itself
     * are left out. Taken from nativephp/mobile-ui 0.6.0's nativephp.json.
     */
    public const FIRST_PARTY = [
        'nativephp/mobile-ui' => [
            'accordion', 'accordion_content', 'accordion_header', 'activity_indicator',
            'background_layer', 'badge', 'bare_text_input', 'bottom_sheet', 'button',
            'button_group', 'carousel', 'checkbox', 'chip', 'date_picker',
            'filled_text_input', 'floating_overlay', 'horizontal_divider', 'lazy_grid',
            'list', 'list_item', 'list_section', 'modal', 'native_drawer',
            'outlined_text_input', 'pager', 'progress_bar', 'radio', 'radio_group',
            'select', 'sheet_pane', 'slider', 'tab', 'tab_row', 'toggle',
            'virtual_list', 'webview',
        ],
    ];

    /** Types that used to exist under a different name. */
    public const RENAMED = [
        'text_input' => 'There is no text-input element any more. nativephp/mobile-ui provides '
            .'<outlined-text-input>, <filled-text-input> and <bare-text-input>.',
    ];

    /** @var array<string, string>|null type => package, for installed but unregistered plugins */
    protected static ?array $unregisteredTypes = null;

    public static function for(string $type): ?string
    {
        if (isset(static::RENAMED[$type])) {
            return static::RENAMED[$type];
        }

        $tag = str_replace('_', '-', $type);

        if (ComponentRegistry::has($type)) {
            return "<{$tag}> is a child component. Write it as <native:{$tag}>.";
        }

        if ($package = static::unregisteredTypes()[$type] ?? null) {
            return "It comes from {$package}, which is installed but not registered. "
                ."Run `php artisan native:plugin:register {$package}`, or add its service provider "
                .'to plugins() in app/Providers/NativeServiceProvider.php.';
        }

        foreach (static::FIRST_PARTY as $package => $types) {
            if (in_array($type, $types, true)) {
                if (static::isRegistered($package)) {
                    return "It comes from {$package}, which is registered but does not provide it. "
                        ."Update {$package}.";
                }

                return "It comes from {$package}. Install it with `composer require {$package}`, "
                    ."then register it with `php artisan native:plugin:register {$package}`.";
            }
        }

        return null;
    }

    /**
     * Override plugin discovery. Pass null to go back to the plugin registry.
     *
     * @param  array<string, string>|null  $types  type => package name
     */
    public static function useUnregisteredTypes(?array $types): void
    {
        static::$unregisteredTypes = $types;
    }

    /** @return array<string, string> */
    protected static function unregisteredTypes(): array
    {
        if (static::$unregisteredTypes !== null) {
            return static::$unregisteredTypes;
        }

        $types = [];

        try {
            /** @var Plugin $plugin */
            foreach (app(PluginRegistry::class)->unregistered() as $plugin) {
                foreach ($plugin->getComponents() as $component) {
                    if (isset($component['type'])) {
                        $types[$component['type']] ??= $plugin->name;
                    }
                }
            }
        } catch (\Throwable) {
            // Discovery is best effort. The error is still thrown without a hint.
        }

        return $types;
    }

    protected static function isRegistered(string $package): bool
    {
        try {
            return app(PluginRegistry::class)->isRegistered($package);
        } catch (\Throwable) {
            return false;
        }
    }
}
