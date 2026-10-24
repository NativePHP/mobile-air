<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\System\AppearanceChanged;

/**
 * Grandchild fixture (mounted inside UserCardChild's view): emits an
 * event only the SCREEN listens for via #[On('badge-poked')], proving
 * emits bubble past a non-listening parent, and listens for a native
 * system event so delivery is covered more than one level deep.
 */
class BadgeChild extends NativeComponent
{
    public string $owner = '';

    public string $appearance = 'light';

    public function poke(): void
    {
        $this->emit('badge-poked', 'badge-of-'.$this->owner);
    }

    #[On(AppearanceChanged::class)]
    public function onAppearanceChanged(string $mode): void
    {
        $this->appearance = $mode;
    }

    public function render(): View
    {
        return view('badge-child');
    }
}
