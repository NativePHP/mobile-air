<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\NativeAction;
use Native\Mobile\Edge\NativeComponent;

/** A child with a region named like its screen's, and an action of its own. */
class FragmentActionChild extends NativeComponent
{
    /** @var list<string> */
    public array $ran = [];

    #[NativeAction]
    public function celebrate(string $who, int $times = 1): void
    {
        $this->ran[] = "celebrate:{$who}:{$times}";
    }

    public function render(): View
    {
        return view('fragment-action-child');
    }
}
