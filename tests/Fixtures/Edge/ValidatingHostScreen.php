<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Concerns\ValidatesInput;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture whose listeners, poll and system back each validate
 * a field that is still empty. It hosts ValidatingChild, which
 * validates a field of its own and emits to this screen.
 */
class ValidatingHostScreen extends NativeComponent
{
    use ValidatesInput;

    public string $title = '';

    protected function rules(): array
    {
        return ['title' => 'required'];
    }

    #[On('draft-saved')]
    public function draftSaved(): void
    {
        $this->validate();
    }

    public function childPicked(): void
    {
        $this->validate();
    }

    #[On(PingReceived::class)]
    public function pinged(): void
    {
        $this->validate();
    }

    #[Poll(1000)]
    public function refresh(): void
    {
        $this->validate();
    }

    public function onBackPressed(): void
    {
        $this->validate();

        $this->back();
    }

    public function render(): View
    {
        return view('validating-host-screen');
    }
}
