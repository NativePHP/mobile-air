<?php

namespace Native\Mobile\Concerns;

use Native\Mobile\Icon\AndroidSymbol;
use Native\Mobile\Icon\IconResolver;
use Native\Mobile\Icon\IosSymbol;

/**
 * Cross-platform icon storage + resolution for builders / elements.
 *
 * Three nullable slots: a shared name (string only — used as a fallback
 * on whichever platform doesn't have an explicit override), an iOS
 * override (IosSymbol enum case or raw string), an Android override
 * (AndroidSymbol enum case or raw string).
 *
 * Resolution happens at PHP-side serialization via [Platform::current]
 * — the wire only ever carries one resolved string per icon prop, plus
 * a `material_variant` companion when the Android override is an
 * `AndroidSymbol` enum (so the Compose `MaterialIcon` composable knows
 * which font to use).
 *
 * Type-hinting against the [IosSymbol] / [AndroidSymbol] marker
 * interfaces (rather than the concrete plugin enums) keeps core free of
 * the native-ui plugin dependency. Plugin-supplied enum catalogs (the
 * `Ios`, `Android`, `AndroidOutlined` enums in
 * `Nativephp\NativeUi\Icon\*`) implement these interfaces.
 *
 * Usage on a builder:
 *
 *   class NavAction
 *   {
 *       use HasPlatformIcon;
 *
 *       public function toElement(): Element
 *       {
 *           // ...
 *           if (($icon = $this->resolvedIcon()) !== null) {
 *               $attrs['icon'] = $icon;
 *               if (($variant = $this->resolvedMaterialVariant()) !== null) {
 *                   $attrs['material_variant'] = $variant;
 *               }
 *           }
 *           // ...
 *       }
 *   }
 *
 * Call sites:
 *
 *   ->icon('save')
 *   ->icon(ios: Ios::BellSlash, android: Android::NotificationsOff)
 *   ->icon('share', ios: Ios::SquareAndArrowUp)
 *   ->icon(android: AndroidOutlined::Home)
 */
trait HasPlatformIcon
{
    private ?string $iconName = null;

    private IosSymbol|string|null $iconIos = null;

    private AndroidSymbol|string|null $iconAndroid = null;

    /**
     * Set the icon. All three args are nullable; named-arg call sites
     * pick whichever combination they need:
     *
     *   ->icon('save')                      // shared name (works on both)
     *   ->icon(ios: Ios::BellSlash)         // iOS override only
     *   ->icon(android: Android::Search)    // Android override only
     *   ->icon(ios: …, android: …)          // explicit per-platform
     *   ->icon('share', ios: Ios::SquareAndArrowUp)  // shared + iOS override
     *
     * The Android override may be either a Filled (`Android`) or
     * Outlined (`AndroidOutlined`) enum case — the chosen variant is
     * propagated to the renderer via [resolvedMaterialVariant].
     */
    public function icon(
        ?string $name = null,
        IosSymbol|string|null $ios = null,
        AndroidSymbol|string|null $android = null,
    ): static {
        if ($name !== null) {
            $this->iconName = $name;
        }
        if ($ios !== null) {
            $this->iconIos = $ios;
        }
        if ($android !== null) {
            $this->iconAndroid = $android;
        }

        return $this;
    }

    /**
     * Returns the platform-correct icon string, or null if no slot is
     * set for the current platform AND no shared fallback was supplied.
     *
     * Resolution rules:
     *   - iOS:     `iosOverride ?? sharedName`
     *   - Android: `androidOverride ?? sharedName`
     *   - Unknown platform (tests / web preview): falls back to
     *     `sharedName` so non-mobile call sites still get a sensible
     *     default for serialization snapshot tests.
     */
    public function resolvedIcon(): ?string
    {
        return IconResolver::resolve($this->iconName, $this->iconIos, $this->iconAndroid)['icon'];
    }

    /**
     * `'filled'` / `'outlined'` / `null` — only meaningful on Android.
     *
     * - Returns the enum's `variant()` value when the Android override
     *   is an [AndroidSymbol] enum case.
     * - Returns `null` for raw-string overrides and shared-name fallback
     *   so the renderer keeps its current default behavior unchanged.
     *
     * On iOS the value is irrelevant — SF Symbols don't have variant
     * fonts, and the renderer doesn't read this prop.
     */
    public function resolvedMaterialVariant(): ?string
    {
        return IconResolver::resolve($this->iconName, $this->iconIos, $this->iconAndroid)['variant'];
    }
}
