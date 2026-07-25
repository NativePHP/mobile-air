<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Inline chrome carrying platform icon enums — `:ios-icon` /
 * `:android-icon` on top-bar-action, bottom-nav-item, and fab, each
 * with a shared `icon` string fallback (except the fab, which is
 * enum-only to cover that shape).
 */
class PlatformIconChromeScreen extends NativeComponent
{
    public function noop(): void {}

    public function render(): View
    {
        return view('platform-icon-chrome-screen');
    }
}
