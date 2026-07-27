<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Grandchild fixture (mounted inside UserCardChild's view): emits an
 * event only the SCREEN listens for via #[On('badge-poked')], proving
 * emits bubble past a non-listening parent.
 */
class BadgeChild extends NativeComponent
{
    public string $owner = '';

    public function poke(): void
    {
        $this->emit('badge-poked', 'badge-of-'.$this->owner);
    }

    public function render(): View
    {
        return view('badge-child');
    }
}
