<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Top screen that popTos its OWN uri — the already-target no-op. The
 * router must re-enter it without unmount/resume; the re-enter path
 * skips BOTH mount() and onResume(), so no pre-runloop intent can end
 * the probe. The Poll(1) finisher is the explicit exit instead: under
 * the FakeBridge the first idle tick fires it (deterministically, no
 * wall-clock sleep), it backs out with intent + stop, and the probe
 * terminates — reachable ONLY while the screen is still the live top,
 * which is exactly what the no-op guarantees.
 */
class PopToSelfScreen extends NativeComponent
{
    public static int $unmounts = 0;

    public static function resetCounters(): void
    {
        self::$unmounts = 0;
    }

    public function mount(): void
    {
        PopToTrace::record('self.mount');
        $this->popTo('/log/thread');
    }

    #[Poll(1)]
    public function finish(): void
    {
        PopToTrace::record('self.poll-back');
        $this->back();
    }

    public function unmount(): void
    {
        self::$unmounts++;
        PopToTrace::record('self.unmount');
        parent::unmount();
    }

    public function render(): Element|View
    {
        return Column::make(Text::make('self target'));
    }
}
