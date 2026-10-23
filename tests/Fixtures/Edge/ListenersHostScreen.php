<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;

/**
 * Screen fixture that hosts the nested ListenersChild. It hears the
 * component event of that child in three listeners, one of them
 * filtered, and a marked event the child listens for as well.
 */
class ListenersHostScreen extends NativeComponent
{
    /** @var list<string> */
    public array $log = [];

    public function pickFromScreen(int $id): void
    {
        $this->dispatch('item-picked', id: $id);
    }

    #[On('item-picked', when: ['id' => 5])]
    public function pickedFive(int $id): void
    {
        $this->log[] = "pickedFive:{$id}";
    }

    #[On('item-picked')]
    public function pickedAny(int $id): void
    {
        $this->log[] = "pickedAny:{$id}";
    }

    #[On('item-picked')]
    public function countPick(int $id): void
    {
        $this->log[] = "countPick:{$id}";
    }

    #[On(OrderShipped::class)]
    public function shipped(int $orderId): void
    {
        $this->log[] = "shipped:{$orderId}";
    }

    public function render(): View
    {
        return view('listeners-host-screen');
    }
}
