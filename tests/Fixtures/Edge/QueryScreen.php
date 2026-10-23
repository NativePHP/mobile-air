<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Prints what the screen was opened with, so a test can read query() and
 * data() side by side. query() is protected, as screens use it.
 */
class QueryScreen extends NativeComponent
{
    public function render(): Element|View
    {
        return Column::make(
            Text::make('query '.json_encode($this->query())),
            Text::make('code '.json_encode($this->query('code', 'none'))),
            Text::make('first tag '.json_encode($this->query('tag.0', 'none'))),
            Text::make('from data '.json_encode($this->data('from', 'nowhere'))),
        );
    }
}
