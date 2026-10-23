<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * Registered fallback route used by the missing-target case: popTo to
 * a URI that is NOT on the stack must fall back to a fresh mount (the
 * documented replace semantics), never invent cached state.
 */
class FallbackProbeScreen extends NativeComponent
{
    public static int $constructed = 0;

    public static int $mounts = 0;

    public static int $unmounts = 0;

    public static ?string $mountUri = null;

    public static ?string $token = null;

    /** @var list<string>|null Live stack token list at this fresh mount. */
    public static ?array $mountTokens = null;

    public function __construct()
    {
        self::$constructed++;
    }

    public static function resetCounters(): void
    {
        self::$constructed = 0;
        self::$mounts = 0;
        self::$unmounts = 0;
        self::$mountUri = null;
        self::$token = null;
        self::$mountTokens = null;
    }

    public function mount(): void
    {
        self::$mounts++;
        self::$token = $this->screenToken();
        self::$mountTokens = $this->nativeRouter?->stackScreenTokens();
        self::$mountUri = $this->nativeRouter?->currentUri();
        PopToTrace::record('fallback.mount:'.self::$mountUri);
        $this->back();
    }

    public function unmount(): void
    {
        self::$unmounts++;
        PopToTrace::record('fallback.unmount');
        parent::unmount();
    }

    public function render(): Element|View
    {
        return Column::make(Text::make('fallback'));
    }
}
