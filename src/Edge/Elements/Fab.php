<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;

/**
 * Floating action button — `<native:fab icon="add" @tap="create" />`.
 *
 * Composed from existing primitives rather than a first-class native
 * renderer: the element IS a `pressable` on the wire (so `@tap`, press
 * feedback, and `:url` navigation all reuse the standard pressable
 * machinery on both platforms), pre-styled as a Material-style FAB and
 * absolutely positioned against its container's bottom corner.
 *
 * `NativeComponent::wrapWithChrome()` hoists a top-level fab out of the
 * screen's flex flow into a Stack overlay so it floats above the content
 * (including scroll views) regardless of where the blade declared it.
 * A fab nested deeper in the tree still renders — absolutely positioned
 * within its nearest container.
 *
 * Attributes (kebab-case in blade):
 *   icon              Icon name (required for a visible fab)
 *   label             Optional label → extended FAB (icon + text pill)
 *   url               Navigate on tap (when no @tap handler is set)
 *   size              small | regular (default) | large
 *   position          end (default) | start — horizontal corner
 *   bottom-offset     Distance from the container bottom (default 16)
 *   edge-offset       Distance from the side edge (default 16)
 *   corner-radius     Override the default circular radius
 *   container-color   Background (default: theme `primary`, else #6750A4)
 *   content-color     Icon/label color (default: theme `on-primary`, else white)
 *   elevation         Shadow elevation (default 6)
 *
 * The Gen-B `event` attribute (WebView-mode JS event dispatch) is gone
 * with the Edge bridge — use `@tap` or `url`.
 */
class Fab extends Pressable
{
    private ?string $url = null;

    /** Container size (dp) per size token. */
    private const SIZES = ['small' => 40, 'regular' => 56, 'large' => 96];

    /** Icon size (dp) per size token. */
    private const ICON_SIZES = ['small' => 24, 'regular' => 24, 'large' => 36];

    /**
     * Guards the one-shot FAB composition. `class()` re-enters
     * applyAttributes with parsed Tailwind attrs (we call it ourselves for
     * the theme-token defaults below), so without the latch the default
     * `bg-theme-primary` application would recurse forever.
     */
    private bool $composed = false;

    public function applyAttributes(array $attrs): void
    {
        parent::applyAttributes($attrs); // `:menu` support from Pressable

        if ($this->composed) {
            return;
        }
        $this->composed = true;

        foreach ([
            'bottom-offset' => 'bottomOffset',
            'edge-offset' => 'edgeOffset',
            'corner-radius' => 'cornerRadius',
            'container-color' => 'containerColor',
            'content-color' => 'contentColor',
        ] as $kebab => $camel) {
            if (isset($attrs[$kebab]) && ! isset($attrs[$camel])) {
                $attrs[$camel] = $attrs[$kebab];
            }
        }

        $size = $attrs['size'] ?? 'regular';
        $label = isset($attrs['label']) ? (string) $attrs['label'] : null;
        $extended = $label !== null && $label !== '';

        $diameter = (float) (self::SIZES[$size] ?? self::SIZES['regular']);
        $iconSize = (float) (self::ICON_SIZES[$size] ?? self::ICON_SIZES['regular']);
        if ($extended) {
            // Extended FABs are a fixed-height pill regardless of `size`.
            $diameter = 56.0;
            $iconSize = 24.0;
        }

        $this->url = isset($attrs['url']) ? (string) $attrs['url'] : null;

        // ── Placement: absolute, pinned to a bottom corner ──
        $bottomOffset = (float) ($attrs['bottomOffset'] ?? 16);
        $edgeOffset = (float) ($attrs['edgeOffset'] ?? 16);
        $this->absolute();
        if (($attrs['position'] ?? 'end') === 'start') {
            $this->insets(0, 0, $bottomOffset, $edgeOffset);
        } else {
            // `end` (default; unknown values fall back here too).
            $this->insets(0, $edgeOffset, $bottomOffset, 0);
        }

        // ── Container ──
        $this->height($diameter);
        if (! $extended) {
            $this->width($diameter);
        } else {
            $this->padding(0, 20, 0, 20);
            $this->gap(12);
        }
        $this->flexDirection(1); // row (icon + optional label)
        $this->center();

        $this->borderRadius((float) ($attrs['cornerRadius'] ?? $diameter / 2));
        $this->elevation((float) ($attrs['elevation'] ?? 6));

        // Background: explicit color wins; otherwise the theme's `primary`
        // token via the Tailwind theme seam, with a Material-baseline hex
        // fallback when no theme resolver is registered (bg-theme-* parses
        // to nothing in that case, so the fallback survives).
        if (isset($attrs['containerColor'])) {
            $this->bg((string) $attrs['containerColor']);
        } else {
            $this->bg('#6750A4');
            $this->class('bg-theme-primary');
        }

        $contentColor = isset($attrs['contentColor']) ? (string) $attrs['contentColor'] : null;

        // ── Content ──
        // `:ios-icon` / `:android-icon` (or `<icon>`-style `:ios` /
        // `:android`) — enum case or raw string; usable with or without
        // the shared `icon` fallback.
        $iosIcon = $attrs['ios-icon'] ?? $attrs['iosIcon'] ?? $attrs['ios'] ?? null;
        $androidIcon = $attrs['android-icon'] ?? $attrs['androidIcon'] ?? $attrs['android'] ?? null;
        if (isset($attrs['icon']) || $iosIcon !== null || $androidIcon !== null) {
            $icon = Icon::make(
                isset($attrs['icon']) ? (string) $attrs['icon'] : null,
                $iosIcon,
                $androidIcon,
            )->size($iconSize);
            $this->applyContentColor($icon, $contentColor, 'text-theme-on-primary');
            $this->addChild($icon);
        }

        if ($extended) {
            $text = Text::make($label)->class('text-base font-medium');
            $this->applyContentColor($text, $contentColor, 'text-theme-on-primary');
            $this->addChild($text);
        }
    }

    /**
     * Color a content child: explicit content-color wins, else the theme
     * `on-primary` token with a white fallback (mirrors the container's
     * default `primary` background).
     */
    private function applyContentColor(Icon|Text $child, ?string $explicit, string $themeClass): void
    {
        if ($explicit !== null) {
            $child->applyAttributes(['color' => $explicit]);

            return;
        }

        $child->applyAttributes(['color' => '#FFFFFF']);
        $child->class($themeClass);
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        // `url` auto-navigates when no explicit press handler / navigate
        // config was wired — same pattern as TopBarAction / SideNavItem.
        if ($this->url !== null && $this->pressMethod === null && $this->navigateConfig === null) {
            $this->setNavigateConfig([
                'type' => 'navigate',
                'uri' => $this->url,
                'data' => [],
                'transition' => 'none',
            ]);
        }

        return [];
    }
}
