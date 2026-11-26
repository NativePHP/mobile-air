<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Concerns\ValidatesInput;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Pressable;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/** Nested fixture with a bag of its own, emitting to ValidatingHostScreen. */
class ValidatingChild extends NativeComponent
{
    use ValidatesInput;

    public string $note = '';

    public bool $emitted = false;

    protected function rules(): array
    {
        return ['note' => 'required'];
    }

    public function save(): void
    {
        $this->emit('draft-saved');

        $this->emitted = true;
    }

    public function pick(): void
    {
        $this->emit('picked');
    }

    public function render(): Element
    {
        return Column::make(
            Pressable::make(Text::make('Save draft'))->onPress('save'),
            Pressable::make(Text::make('Pick'))->onPress('pick'),
        );
    }
}
