<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Child of ActionPipelineScreen. One emit() lands on the parent twice: once
 * through the tag-level @child-said binding, once through the screen's
 * #[On('child-said')] listener.
 */
class ActionPipelineChild extends NativeComponent
{
    public string $word = 'hi';

    public function speak(): void
    {
        $this->emit('child-said', $this->word);
    }

    public function render(): View
    {
        return view('action-pipeline-child');
    }
}
