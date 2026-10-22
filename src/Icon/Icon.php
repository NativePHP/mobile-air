<?php

namespace Native\Mobile\Icon;

use InvalidArgumentException;

/**
 * App-supplied SVG icons.
 *
 * Drop `resources/icons/<name>.svg` into the app and the build converts it
 * into a template image asset (iOS) and a VectorDrawable (Android). At
 * runtime the icon is addressed by the wire string `custom:<name>`, which
 * rides every existing string icon prop (tab, top-bar action, menu item,
 * `<native:icon name>`, …). Renderers decide what to draw from the prefix.
 *
 * Per-platform overrides keep working unchanged:
 *
 *   ->icon(Icon::custom('bell'), android: AndroidOutlined::Notifications)
 *   ->icon('bell', ios: Icon::custom('bell'))
 *
 * A custom icon never carries `material_variant`: there is no font to pick.
 */
final class Icon
{
    public const PREFIX = 'custom:';

    /** Lowercase words joined by single hyphens: `git-commit-vertical`. */
    public const NAME_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * The wire string for the icon built from `resources/icons/<name>.svg`.
     *
     * @throws InvalidArgumentException when `$name` can't be an icon file name
     */
    public static function custom(string $name): string
    {
        if (! self::isValidName($name)) {
            throw new InvalidArgumentException(
                "Invalid custom icon name [{$name}]. Use lowercase letters, numbers and single hyphens, matching resources/icons/<name>.svg."
            );
        }

        return self::PREFIX.$name;
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Whether a resolved icon string points at an app-supplied SVG.
     */
    public static function isCustom(?string $icon): bool
    {
        return $icon !== null && str_starts_with($icon, self::PREFIX);
    }
}
