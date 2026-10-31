<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Inert middle screen (a thread under the log feed). It only records
 * its lifecycle; when it becomes top again after a single back(), its
 * onResume() finisher leaves explicitly (intent + stop, before the
 * runloop could enter its event wait) so the probe stays bounded.
 */
class ThreadScreen extends NativeComponent
{
    public static int $constructed = 0;

    public static int $unmounts = 0;

    public static ?string $token = null;

    /** @var list<string>|null Live stack token list observed at this resume. */
    public static ?array $resumeTokens = null;

    public function __construct()
    {
        self::$constructed++;
    }

    public static function resetCounters(): void
    {
        self::$constructed = 0;
        self::$unmounts = 0;
        self::$token = null;
        self::$resumeTokens = null;
    }

    public function mount(): void
    {
        self::$token = $this->screenToken();
        PopToTrace::record('thread.mount');
    }

    public function onResume(): void
    {
        self::$resumeTokens = $this->nativeRouter?->stackScreenTokens();
        PopToTrace::record('thread.resume');
        $this->back();
    }

    public function unmount(): void
    {
        self::$unmounts++;
        PopToTrace::record('thread.unmount');
        parent::unmount();
    }

    public function render(): Element|View
    {
        return Column::make(Text::make('thread'));
    }
}
