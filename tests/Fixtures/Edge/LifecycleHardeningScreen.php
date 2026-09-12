<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

class LifecycleHardeningScreen extends NativeComponent
{
    public ?string $status = 'unset';

    public array $log = [];

    /** Claim 1: a cleared select sends '' for a nullable backed enum. */
    public function chooseStatus(?LifecycleStatus $status): void
    {
        $this->status = $status?->value ?? 'null';
    }

    /** Claim 2: an #[On] listener whose name collides with the lifecycle guard. */
    #[On('probe-updated')]
    public function updatedFromEvent(string $note): void
    {
        $this->log[] = 'listener:'.$note;
    }

    public function fireListenerEvent(): void
    {
        $this->dispatch('probe-updated', note: 'hello');
    }

    public function render(): Element
    {
        return Column::make(Text::make('Status '.$this->status));
    }
}

enum LifecycleStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
