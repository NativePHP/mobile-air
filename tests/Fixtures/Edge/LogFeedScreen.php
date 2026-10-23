<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NavigationIntent;

/**
 * The existing feed screen a popTo() must RESUME — the same mounted
 * instance, with its cursor / list key / row window untouched. Its
 * onResume() records a snapshot and then ends the runloop via an
 * EXIT_WEB intent (deterministic terminal for the router loop; back()
 * at the root screen would depend on the Platform probe instead).
 */
class LogFeedScreen extends NativeComponent
{
    public int $cursor = 0;

    public string $listKey = 'log-feed-v1';

    public static int $constructed = 0;

    public static int $mounts = 0;

    public static int $resumes = 0;

    public static int $unmounts = 0;

    public static ?self $instance = null;

    /** Opaque per-mount screen token captured at mount (frame-metadata tests). */
    public static ?string $token = null;

    /** @var list<string>|null Live stack token list observed at this resume. */
    public static ?array $resumeTokens = null;

    /** @var array{cursor: int, listKey: string, uri: ?string, depth: ?int}|null */
    public static ?array $resumeSnapshot = null;

    public function __construct()
    {
        self::$constructed++;
    }

    public static function resetCounters(): void
    {
        self::$constructed = 0;
        self::$mounts = 0;
        self::$resumes = 0;
        self::$unmounts = 0;
        self::$instance = null;
        self::$resumeSnapshot = null;
        self::$token = null;
        self::$resumeTokens = null;
    }

    public function mount(): void
    {
        self::$mounts++;
        self::$instance = $this;
        self::$token = $this->screenToken();
        $this->cursor = 42;
        PopToTrace::record('feed.mount');
    }

    public function onResume(): void
    {
        self::$resumes++;
        self::$resumeTokens = $this->nativeRouter?->stackScreenTokens();
        self::$resumeSnapshot = [
            'cursor' => $this->cursor,
            'listKey' => $this->listKey,
            'uri' => $this->nativeRouter?->currentUri(),
            'depth' => $this->nativeRouter?->stackDepth(),
        ];
        PopToTrace::record('feed.resume');
        // Deterministic terminal: observe the resume, then leave.
        $this->nativeNavigationIntent = new NavigationIntent(NavigationIntent::EXIT_WEB, '/feed-done');
        $this->stop();
    }

    public function unmount(): void
    {
        self::$unmounts++;
        PopToTrace::record('feed.unmount');
        parent::unmount();
    }

    public function render(): Element|View
    {
        return Column::make(Text::make("Log feed cursor {$this->cursor}"));
    }
}
