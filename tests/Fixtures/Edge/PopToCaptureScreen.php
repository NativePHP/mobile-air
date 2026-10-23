<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NavigationIntent;

/**
 * Harness-driven screen capturing the exact NavigationIntent popTo()
 * and back() produce — the wire-level contract of the primitive.
 */
class PopToCaptureScreen extends NativeComponent
{
    public static ?NavigationIntent $popToIntent = null;

    public static ?NavigationIntent $backIntent = null;

    public static function resetCounters(): void
    {
        self::$popToIntent = null;
        self::$backIntent = null;
    }

    public function popLog(): void
    {
        $this->popTo('/log');
        self::$popToIntent = $this->getNavigationIntent();
    }

    public function backOnce(): void
    {
        $this->back();
        self::$backIntent = $this->getNavigationIntent();
    }

    public function render(): Element|View
    {
        return Column::make(Text::make('capture'));
    }
}
