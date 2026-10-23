<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Middle screen of the harness flow stack: one below the feed, one
 * above the reply. Counts its unmounts so a popTo() unwind can assert
 * it popped exactly once.
 */
class PopToFlowThreadScreen extends NativeComponent
{
    public static int $unmounts = 0;

    public static function resetCounters(): void
    {
        self::$unmounts = 0;
    }

    public function openReply(): void
    {
        $this->navigate('/log/thread/reply');
    }

    public function popToLog(): void
    {
        $this->popTo('/log');
    }

    public function popToMissing(): void
    {
        $this->popTo('/fallback');
    }

    public function unmount(): void
    {
        self::$unmounts++;
        parent::unmount();
    }

    public function render(): Element|View
    {
        return Column::make(
            Text::make('thread'),
            Button::make('Open reply')->onPress('openReply'),
            Button::make('Return to log')->onPress('popToLog'),
        );
    }
}
