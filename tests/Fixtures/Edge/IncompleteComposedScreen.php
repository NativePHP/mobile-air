<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\GestureArea;
use Native\Mobile\Edge\Elements\Pressable;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/** Composed controls each missing what their role needs. */
class IncompleteComposedScreen extends NativeComponent
{
    public function noop(): void
    {
        //
    }

    public function render(): Element
    {
        return Column::make(
            Pressable::make(Text::make('Terms'))->ref('terms')->onPress('noop')->a11yRole('checkbox'),
            GestureArea::make()->ref('volume')->a11yLabel('Volume')->a11yRole('adjustable'),
            GestureArea::make()->ref('upload')->a11yLabel('Upload')->a11yRole('progress'),
            Column::make()->ref('tabs')->a11yRole('tablist'),
            Column::make(Text::make('Discard?'))->ref('dialog')->a11yModal(),
            Button::make()->ref('save')->setProp('label', 'Save')->a11yRole('checkbox')->a11yLive(),
        );
    }
}
