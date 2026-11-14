<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Attributes\Locked;
use Native\Mobile\Edge\NativeComponent;

/**
 * Handlers that set properties instead of calling methods:
 * `$open = true`, `$open = !$open` and friends, on a press, a sheet's
 * dismiss and a child's event binding.
 */
class HandlerExpressionsScreen extends NativeComponent
{
    public bool $open = false;

    public string $tab = 'home';

    public int|float $count = 5;

    public ?string $note = 'kept';

    public string $label = 'text';

    public bool $saved = false;

    #[Locked]
    public bool $admin = false;

    /** @var list<string> */
    public array $hooks = [];

    /** Set by the test to pick which broken handler the view draws. */
    public string $broken = '';

    public function updatedOpen(bool $value): void
    {
        $this->hooks[] = 'open:'.($value ? 'true' : 'false');
    }

    public function render(): View
    {
        return view('handler-expressions-screen');
    }
}
