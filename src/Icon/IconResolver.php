<?php

namespace Native\Mobile\Icon;

use Native\Mobile\Platform;

/**
 * Stateless helper that resolves a `(name, sf, material)` triple to the
 * platform-correct wire pair `(icon, variant)`.
 *
 * Used by:
 *   - `HasPlatformIcon` for single-slot builders (NavAction, Tab, Chip, …).
 *   - Multi-slot classes (Button: leading + trailing, ListItem: leading +
 *     trailing + trailingIconButton, BaseTextInput: leading + trailing)
 *     that can't use the single-slot trait — they call this directly per
 *     slot.
 *
 * Resolution rules match the trait:
 *   - iOS:     iosOverride ?? sharedName
 *   - Android: androidOverride ?? sharedName
 *   - Unknown platform: sharedName (so non-mobile snapshot tests still
 *     get a sensible value).
 *
 * The `variant` ('filled' / 'outlined' / null) is only set when the
 * Material override is a `MaterialSymbol` enum instance, and only on
 * Android — on iOS the value is irrelevant.
 */
class IconResolver
{
    /**
     * @return array{icon: ?string, variant: ?string}
     */
    public static function resolve(
        ?string $name,
        SFSymbol|string|null $sf,
        MaterialSymbol|string|null $material,
    ): array {
        $platform = Platform::current();

        $override = match ($platform) {
            Platform::IOS     => $sf,
            Platform::ANDROID => $material,
            default           => null,
        };

        if ($override === null) {
            $icon = $name;
        } elseif (is_string($override)) {
            $icon = $override;
        } else {
            $icon = $override->value;
        }

        $variant = ($platform === Platform::ANDROID && $material instanceof MaterialSymbol)
            ? $material->variant()
            : null;

        return ['icon' => $icon, 'variant' => $variant];
    }
}
