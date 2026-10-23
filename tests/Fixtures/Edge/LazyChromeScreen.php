<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\Lazy;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

/**
 * #[Lazy] screen used with a chrome layout: its placeholder frame
 * publishes BEFORE mount(), so the frame-metadata tests can assert the
 * per-mount screen token is already on the wire for the placeholder —
 * not just the first real frame. Renders through the View path on
 * purpose: layout chrome wrapping (wrapWithChrome) happens in
 * view()/fromView(), not for raw Element returns (see ChromeScreen).
 */
#[Lazy]
class LazyChromeScreen extends NativeComponent
{
    public static bool $mounted = false;

    public static ?string $token = null;

    public static function resetCounters(): void
    {
        self::$mounted = false;
        self::$token = null;
    }

    public function mount(): void
    {
        self::$mounted = true;
        self::$token = $this->screenToken();
    }

    public function bump(): void
    {
        // Any state change to force a re-render of the same instance.
    }

    public function render(): View
    {
        return view('lazy-chrome-screen');
    }
}
