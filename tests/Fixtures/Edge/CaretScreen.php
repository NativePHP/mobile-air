<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\Elements\TextInput;
use Native\Mobile\Edge\NativeComponent;

/**
 * Fixture for `@selectionChange` — a selection-aware input alongside a
 * plain one, so tests can cover both the caret-carrying path and full
 * backward compatibility of plain input().
 */
class CaretScreen extends NativeComponent
{
    public string $draft = '';

    /** Text the selection handler last saw. */
    public string $seen = '';

    /** Offsets the selection handler last saw. -1 == never fired. */
    public int $start = -1;

    public int $end = -1;

    /** Bound arg delivered before the (text, start, end) triple. */
    public string $label = '';

    public function updateDraft(string $text): void
    {
        $this->draft = $text;
    }

    public function caretMoved(string $text, int $start, int $end): void
    {
        $this->seen = $text;
        $this->start = $start;
        $this->end = $end;
    }

    public function caretMovedWith(string $label, string $text, int $start, int $end): void
    {
        $this->label = $label;
        $this->caretMoved($text, $start, $end);
    }

    public function render(): Element|View
    {
        return Column::make(
            Text::make("Draft: {$this->draft}"),
            Text::make("Caret: {$this->start}-{$this->end}"),
            SelectionTextInput::make()
                ->value($this->draft)
                ->onChange('updateDraft')
                ->onSelectionChange('caretMoved')
                ->ref('caret-input'),
            SelectionTextInput::make()
                ->value('preset')
                ->onSelectionChange("caretMovedWith('field-a')")
                ->ref('labelled-input'),
            TextInput::make()
                ->onChange('updateDraft')
                ->ref('plain-input'),
        );
    }
}
