<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Bottom screen of the harness flow stack: two below the feed. Its
 * reselect handler popTos the feed's uri — the app wiring the child
 * screens will use (`onReselect` → `popTo('/log')`, no scroll target).
 */
class PopToFlowReplyScreen extends NativeComponent
{
    public static int $unmounts = 0;

    public static function resetCounters(): void
    {
        self::$unmounts = 0;
    }

    public function popToLog(): void
    {
        $this->popTo('/log');
    }

    public function unmount(): void
    {
        self::$unmounts++;
        parent::unmount();
    }

    public function render(): Element|View
    {
        return Column::make(
            Text::make('reply'),
            Button::make('Return to log')->onPress('popToLog'),
        );
    }
}
