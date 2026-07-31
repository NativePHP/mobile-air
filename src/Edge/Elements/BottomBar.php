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
 */
class BottomBar extends Element
{
    protected string $type = 'bottom_bar';

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        // No own attributes — the wrapped child carries the actual content.
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return [];
    }
}
