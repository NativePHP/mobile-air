<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\Component;

/** Blade class component that declines to render, so its slot never runs. */
class HiddenBladeComponent extends Component
{
    public function shouldRender(): bool
    {
        return false;
    }

    public function render(): string
    {
        return '<native:column>{{ $slot }}</native:column>';
    }
}
