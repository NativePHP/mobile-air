<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * Sentinel element emitted by `wrapWithChrome` when a layout opts into
 * native chrome via `NativeLayout::usesNativeChrome() = true` and a
 * TabBar is present (with or without a NavBar — when both are present,
 * the NavBar config is folded into the tabs root since each tab hosts
 * its own NavigationStack natively).
 *
 * Carries the TabBar config as flat props (dark, active_color,
 * background_color, text_color, label_visibility) plus the tab items
 * themselves as `bottom_nav_item` children. The active tab's screen
 * content is appended as the final child; inactive tabs render as
 * empty placeholders until the user navigates to them (PHP only ever
 * has one tab's tree alive at a time).
 *
 * When a NavBar is also present, its config is folded in via
 * `nav_*` prefixed props (nav_title, nav_subtitle, …) plus
 * `top_bar_action` children — same routing pattern as NavigationStack.
 *
 * iOS / Android renderers detect this element type and route to native
 * `TabView` / `Scaffold(bottomBar = NavigationBar)` chrome.
 */
class NativeRootTabs extends Element
{
    protected string $type = 'native_root_tabs';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        // TabBar config
        if (isset($attrs['dark']))            $this->props['dark']             = (bool) $attrs['dark'];
        if (isset($attrs['activeColor']))     $this->props['active_color']     = $attrs['activeColor'];
        if (isset($attrs['backgroundColor'])) $this->props['background_color'] = $attrs['backgroundColor'];
        if (isset($attrs['textColor']))       $this->props['text_color']       = $attrs['textColor'];
        if (isset($attrs['labelVisibility'])) $this->props['label_visibility'] = $attrs['labelVisibility'];
        if (isset($attrs['minimizeOnScroll'])) $this->props['minimize_on_scroll'] = (bool) $attrs['minimizeOnScroll'];

        // Optional folded NavBar config (when this layout supplies both bars).
        if (isset($attrs['navTitle']))           $this->props['nav_title']            = $attrs['navTitle'];
        if (isset($attrs['navSubtitle']))        $this->props['nav_subtitle']         = $attrs['navSubtitle'];
        if (isset($attrs['navBack']))            $this->props['nav_back']             = (bool) $attrs['navBack'];
        if (isset($attrs['navBackgroundColor'])) $this->props['nav_background_color'] = $attrs['navBackgroundColor'];
        if (isset($attrs['navTextColor']))       $this->props['nav_text_color']       = $attrs['navTextColor'];
        if (isset($attrs['navElevation']))       $this->props['nav_elevation']        = (int) $attrs['navElevation'];

        // The URI of the active tab's screen. The iOS bridge keys its
        // per-URI tree diff off this so tab-switch publishes reuse
        // unchanged subtree refs and don't trigger a full re-render.
        if (isset($attrs['currentUri']))      $this->props['current_uri']      = $attrs['currentUri'];
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
