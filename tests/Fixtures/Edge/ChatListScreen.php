<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

class ChatListScreen extends NativeComponent
{
    public function open(): void
    {
        $this->navigate('/chats/1');
    }

    public function render(): Element
    {
        return $this->wrapWithChrome(Column::make(
            Text::make('Chat list'),
            Button::make('Open chat')->onPress('open'),
        ));
    }
}
