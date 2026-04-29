<?php

namespace Native\Mobile\Edge\Elements;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * Sentinel element emitted by `wrapWithChrome` when a layout opts into
 * native chrome via `NativeLayout::usesNativeChrome() = true` and only a
 * NavBar (no TabBar) is present.
 *
 * Carries the NavBar config as flat props (title, subtitle, back,
 * background_color, text_color, elevation) plus per-screen action items
 * as `top_bar_action` children. The screen's rendered content is
 * appended as the final child.
 *
 * iOS / Android renderers detect this element type and route to native
 * `NavigationStack` / `NavHost` chrome instead of laying out chrome via
 * the custom `TopBar` HStack renderer.
 */
class NativeRootStack extends Element
{
    protected string $type = 'native_root_stack';

    protected array $props = [];

    public static function make(): static
    {
        return new static;
    }

    public function applyAttributes(array $attrs): void
    {
        if (isset($attrs['title']))           $this->props['title']            = $attrs['title'];
        if (isset($attrs['subtitle']))        $this->props['subtitle']         = $attrs['subtitle'];
        if (isset($attrs['back']))            $this->props['back']             = (bool) $attrs['back'];
        if (isset($attrs['backgroundColor'])) $this->props['background_color'] = $attrs['backgroundColor'];
        if (isset($attrs['textColor']))       $this->props['text_color']       = $attrs['textColor'];
        if (isset($attrs['elevation']))       $this->props['elevation']        = (int) $attrs['elevation'];
    }

    protected function resolveProps(CallbackRegistry $registry): array
    {
        return $this->props;
    }
}
