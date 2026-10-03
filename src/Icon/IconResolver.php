<?php

namespace Native\Mobile\Icon;

use Native\Mobile\Platform;
use SupaNative\Core\Platform as RenderPlatform;

/**
 * Stateless helper that resolves a `(name, ios, android)` triple to the
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
 *   - macOS:   iosOverride ?? sharedName — see below
 *   - Android: androidOverride ?? sharedName
 *   - Windows: windowsOverride ?? sharedName ?? iosOverride — see below
 *   - Unknown platform: sharedName (so non-mobile snapshot tests still
 *     get a sensible value).
 *
 * ## Why macOS takes the iOS slot
 *
 * SF Symbols is one catalogue shared by every Apple platform, so the name in
 * the `ios` slot is the right name on a Mac as well — `IosSymbol::ChevronDown`
 * is `chevron.down` in both places. Before this, macOS matched neither arm and
 * fell through to the shared `name`, which a tag written
 * `<native:icon :ios="…" :android="…" />` never sets: the result was a resolved
 * icon of null, and an icon node that drew nothing at all. The `/counter`
 * demo's + and − buttons were empty coloured rectangles for exactly this
 * reason.
 *
 * ## Windows has a slot of its own
 *
 * Windows draws from Segoe Fluent Icons, which is neither catalogue, so it
 * gets its own `windows` slot rather than an alias onto someone else's. The
 * slot is optional and comes last in the signature, so every existing caller
 * is unchanged.
 *
 * When the slot is empty the shared name is used, and when that is empty too
 * the iOS name is sent. That last step is not the macOS aliasing above: the
 * Windows shell does not draw SF Symbols. It keeps a table that translates
 * the common SF Symbols and Material names to their nearest Segoe glyph, so
 * an app written before the slot existed gets a close icon instead of an
 * empty box. Where the exact icon matters, name it in the slot.
 *
 * macOS taking the iOS slot is deliberately the Apple-platform case and not a general
 * platform → symbol-slot mechanism. Linux has its own icon
 * vocabulary and it is not SF Symbols, so it cannot be folded in there;
 * when it arrives it needs a slot of its own, as Windows has, rather than an alias onto
 * someone else's. See "Per-platform icon overrides beyond iOS/Android" in
 * `docs/roadmap-notes.md` in the desktop shell.
 *
 * ## Which platform is "current"
 *
 * Mobile's own [Platform] answers first, because on a device it is the truth
 * and in a test it is the seam `Native::test(..., platform: 'ios')` sets.
 * It only knows how to say `ios`, `android` or "don't know", and the way it
 * finds out is a bridge probe — so on a Mac it says "don't know". The
 * declared platform from `supanative/core` answers then: every shell exports
 * `NATIVEPHP_PLATFORM` before the interpreter starts, so `macos` is available
 * without a bridge round-trip and without this class having to know how a Mac
 * might be detected.
 *
 * The `variant` ('filled' / 'outlined' / null) is only set when the
 * Android override is an `AndroidSymbol` enum instance, and only on
 * Android — on iOS the value is irrelevant.
 */
class IconResolver
{
    /**
     * @return array{icon: ?string, variant: ?string}
     */
    public static function resolve(
        ?string $name,
        IosSymbol|string|null $ios,
        AndroidSymbol|string|null $android,
        WindowsSymbol|string|null $windows = null,
    ): array {
        $platform = Platform::current() ?? RenderPlatform::current();

        $override = match ($platform) {
            Platform::IOS, RenderPlatform::MACOS => $ios,
            Platform::ANDROID => $android,
            RenderPlatform::WINDOWS => $windows ?? $name ?? $ios,
            default => null,
        };

        if ($override === null) {
            $icon = $name;
        } elseif (is_string($override)) {
            $icon = $override;
        } else {
            $icon = $override->value;
        }

        $variant = ($platform === Platform::ANDROID && $android instanceof AndroidSymbol)
            ? $android->variant()
            : null;

        return ['icon' => $icon, 'variant' => $variant];
    }
}
