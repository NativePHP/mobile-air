<?php

namespace Tests\Fixtures\Edge;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/** An app screen, to show app routes win over server routes. */
class AppPromoScreen extends NativeComponent
{
    public function render(): Element
    {
        return Text::make('App promo');
    }
}
