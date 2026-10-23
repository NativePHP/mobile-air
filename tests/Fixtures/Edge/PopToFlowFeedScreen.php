<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Flow-stack fixtures for the harness-side popTo() tests (see
 * PopToNavigationTest's "flow stack" section): unlike the raw-router
 * LogFeedScreen, none of these auto-exit on resume — the harness keeps
 * driving them after followNavigation() returns.
 *
 * The feed is the screen a popTo() must return to: the same mounted
 * instance with its cursor / list key untouched.
 */
class PopToFlowFeedScreen extends NativeComponent
{
    public int $cursor = 0;

    public string $listKey = 'log-feed-v1';

    public static int $mounts = 0;

    public static int $resumes = 0;

    public static int $unmounts = 0;

    public static function resetCounters(): void
    {
        self::$mounts = 0;
        self::$resumes = 0;
        self::$unmounts = 0;
    }

    public function mount(): void
    {
        self::$mounts++;
    }

    public function onResume(): void
    {
        self::$resumes++;
    }

    public function unmount(): void
    {
        self::$unmounts++;
        parent::unmount();
    }

    public function scrollDeep(): void
    {
        $this->cursor = 231;
    }

    public function openThread(): void
    {
        $this->navigate('/log/thread');
    }

    public function render(): Element|View
    {
        return Column::make(
            Text::make("Log feed cursor {$this->cursor}"),
            Button::make('Open thread')->onPress('openThread'),
        );
    }
}
