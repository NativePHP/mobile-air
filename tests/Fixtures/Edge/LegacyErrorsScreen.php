<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * The documented v3 pattern: a hand-rolled `public array $errors` prop
 * consumed by @nativeError. The injected ViewErrorBag must be a
 * FALLBACK that never clobbers it (regression: review finding 3).
 */
class LegacyErrorsScreen extends NativeComponent
{
    public array $errors = ['title' => 'Legacy message', 'zero' => '0'];

    public function render(): View
    {
        return view('legacy-errors');
    }
}
