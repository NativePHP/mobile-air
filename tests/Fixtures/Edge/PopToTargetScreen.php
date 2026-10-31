<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * The screen whose mount() performs the popTo under test. The target
 * comes from params so the same fixture covers unwind / fallback cases.
 */
class PopToTargetScreen extends NativeComponent
{
    public static int $constructed = 0;

    public static int $mounts = 0;

    public static int $unmounts = 0;

    public static ?string $mountUri = null;

    public static ?string $token = null;

    /** @var list<string>|null Live stack token list at this screen's unmount. */
    public static ?array $unmountTokens = null;

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
        self::$unmountTokens = null;
    }

    public function mount(): void
    {
        self::$mounts++;
        self::$token = $this->screenToken();
        self::$mountUri = $this->nativeRouter?->currentUri();
        PopToTrace::record('pop-to-target.mount:'.self::$mountUri);
        $this->popTo((string) $this->param('target', '/log'));
    }

    public function unmount(): void
    {
        self::$unmounts++;
        self::$unmountTokens = $this->nativeRouter?->stackScreenTokens();
        PopToTrace::record('pop-to-target.unmount');
        parent::unmount();
    }

    public function render(): Element|View
    {
        return Column::make(Text::make('pop-to target screen'));
    }
}
