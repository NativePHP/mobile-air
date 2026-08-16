<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Renderless;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture hosting nested child components: one unkeyed static
 * card behind an @if (unmount coverage), a keyed list (identity across
 * reorders), a tag-level @card-saved binding, and an #[On] string
 * listener for the grandchild's emit.
 */
class NestedHostScreen extends NativeComponent
{
    public string $title = 'Host';

    /** @var list<string> */
    public array $names = ['a', 'b'];

    public bool $showCard = true;

    /** @var list<string> tag-binding deliveries: "<bound>:<name>:<clicks>" */
    public array $savedEvents = [];

    /** @var list<string> grandchild emits received via #[On] */
    public array $pokes = [];

    public function markSaved(string $bound, string $name, int $clicks): void
    {
        $this->savedEvents[] = "{$bound}:{$name}:{$clicks}";
    }

    #[On('badge-poked')]
    #[Renderless]
    public function onBadgePoked(string $from): void
    {
        $this->pokes[] = $from;
    }

    public function render(): View
    {
        return view('nested-host-screen');
    }
}
