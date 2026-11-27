<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Accessibility;

/** Controls composed from core elements with the a11y role and state props. */
class ComposedControlsScreen extends NativeComponent
{
    public bool $accepted = true;

    public int $volume = 40;

    public string $priority = 'routine';

    public string $error = 'Choose a priority.';

    public bool $complete = true;

    public ?int $removed = null;

    public function toggleTerms(): void
    {
        $this->accepted = ! $this->accepted;
    }

    public function louder(): void
    {
        $this->volume = min(100, $this->volume + 10);
    }

    public function quieter(): void
    {
        $this->volume = max(0, $this->volume - 10);
    }

    public function choose(string $priority): void
    {
        $this->priority = $priority;
    }

    public function remove(int $id): void
    {
        $this->removed = $id;
    }

    public function close(): void
    {
        //
    }

    public function save(): void
    {
        Accessibility::focus('priority');
        Accessibility::announce('Draft saved');
    }

    public function render(): Element|View
    {
        return view('composed-controls-screen');
    }
}
