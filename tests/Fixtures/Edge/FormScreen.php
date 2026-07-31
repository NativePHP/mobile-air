<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\BottomSheet;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Elements\TextInput;
use Native\Mobile\Edge\Elements\Toggle;
use Native\Mobile\Edge\NativeComponent;

/**
 * Fixture covering every wire event type the harness can dispatch:
 * long-press, submit, checkbox/slider/radio/select/tab changes, and
 * sheet dismissal. Elements register the callbacks; event types without
 * a dedicated element yet (slider, radio, select, tabs) are targeted by
 * method name — dispatch only needs the registered callback id.
 */
class FormScreen extends NativeComponent
{
    public string $draft = '';

    public string $submitted = '';

    public bool $held = false;

    public bool $gasHeld = false;

    public bool $agreed = false;

    public float $volume = 0.0;

    public string $color = 'none';

    public string $size = 'none';

    public int $activeTab = 0;

    public bool $sheetOpen = true;

    public function hold(): void
    {
        $this->held = true;
    }

    public function gasDown(): void
    {
        $this->gasHeld = true;
    }

    public function gasUp(): void
    {
        $this->gasHeld = false;
    }

    public function updateDraft(string $text): void
    {
        $this->draft = $text;
    }

    /** Submit events carry the field's text; empty falls back to the draft. */
    public function submitDraft(string $text): void
    {
        $this->submitted = $text !== '' ? $text : $this->draft;
    }

    public function agree(bool $value): void
    {
        $this->agreed = $value;
    }

    public function setVolume(float $value): void
    {
        $this->volume = $value;
    }

    public function pickColor(string $value): void
    {
        $this->color = $value;
    }

    public function pickSize(string $value): void
    {
        $this->size = $value;
    }

    public function switchTab(int $index): void
    {
        $this->activeTab = $index;
    }

    public function closeSheet(): void
    {
        $this->sheetOpen = false;
    }

    public function render(): Element|View
    {
        $tree = Column::make(
            Text::make("Draft: {$this->draft}"),
            Text::make("Submitted: {$this->submitted}"),
            Text::make($this->held ? 'Holding' : 'Released'),
            Text::make("Volume: {$this->volume}"),
            Text::make("Color: {$this->color} / Size: {$this->size}"),
            Text::make("Tab: {$this->activeTab}"),
            Button::make('Hold me')->onLongPress('hold')->ref('hold-button'),
            Text::make($this->gasHeld ? 'Accelerating' : 'Coasting'),
            Button::make('Gas')->onPressDown('gasDown')->onPressUp('gasUp')->ref('gas-button'),
            TextInput::make()->onChange('updateDraft')->onSubmit('submitDraft')->ref('draft-input'),
            Toggle::make()->onChange('agree')->ref('agree-toggle'),
            Button::make('Volume')->onPress('setVolume'),
            Button::make('Color')->onPress('pickColor'),
            Button::make('Size')->onPress('pickSize'),
            Button::make('Tabs')->onPress('switchTab'),
        );

        if ($this->sheetOpen) {
            $tree->addChild(
                BottomSheet::make()->onDismiss('closeSheet')->ref('confirm-sheet')
            );
        }

        return $tree;
    }
}
