<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * Marker element wrapping a `NativeLayout::bottomBar()` result so the
 * iOS / Android renderer can pick it out of the chrome sentinel's
 * children (alongside tabs, top-bar actions, and screen content) and
 * pin it via `.safeAreaInset(edge: .bottom)` (iOS) /
 * `Scaffold(bottomBar = …)` + `imePadding()` (Compose).
 *
 * Used for the chat-input pattern, search bars, contextual menus —
 * anything pinned above the keyboard / safe-area-bottom that should
 * survive pushes inside a `NavigationStack` and hide / appear with the
 * keyboard.
 *
 * The wrapped Element is whatever the layout composed — typically a
 * Row of glass-styled capsules. The renderer extracts the single child
 * and renders it as the bottom-bar content.
 *
 * With `class="glass"` the iOS renderers draw soft Liquid Glass behind
 * the whole bar, faded in at its top edge. `glass-opacity` (0 to 1,
 * default 1) lowers how strongly that glass covers what scrolls under
 * the bar. Android draws no glass behind a bottom bar, so it has
 * nothing to apply the opacity to.
 */
class BottomBar extends Element
{
    protected string $type = 'bottom_bar';

    /** Opacity of the soft glass behind the bar, or null for the renderer's default (fully opaque). */
    protected ?float $glassOpacity = null;

    public static function make(): static
    {
        return new static;
    }

    /**
     * How opaque the soft glass behind a `glass` bar is at its fullest,
     * from 0 (none) to 1 (the default). Values outside that range are
     * clamped.
     */
    public function glassOpacity(float $opacity): static
    {
        $this->glassOpacity = max(0.0, min(1.0, $opacity));

        return $this;
    }

    public function applyAttributes(array $attrs): void
    {
        // The wrapped child carries the actual content. The bar itself
        // only takes the opacity of the glass drawn behind it.
        $opacity = $attrs['glass-opacity'] ?? $attrs['glassOpacity'] ?? null;

        if (is_numeric($opacity)) {
            $this->glassOpacity((float) $opacity);
        }
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->glassOpacity === null ? [] : ['glass_opacity' => $this->glassOpacity];
    }
}
