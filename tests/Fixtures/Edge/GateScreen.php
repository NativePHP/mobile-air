<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Redirects from mount() — the auth-gate pattern. The harness must honor
 * the intent and never render, exactly like the on-device runloop.
 */
class GateScreen extends NativeComponent
{
    public function mount(): void
    {
        $this->replace('/detail/1');
    }

    public function render(): Element|View
    {
        return Column::make(Text::make('You should never see this'));
    }
}
