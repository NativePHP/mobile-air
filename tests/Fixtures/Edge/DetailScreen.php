<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

class DetailScreen extends NativeComponent
{
    public function goBack(): void
    {
        $this->back();
    }

    public function render(): Element|View
    {
        return Column::make(
            Text::make("Detail {$this->param('id')} from {$this->data('from', 'nowhere')}"),
            Button::make('Go back')->onPress('goBack'),
        );
    }
}
