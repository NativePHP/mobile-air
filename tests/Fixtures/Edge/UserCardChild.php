<?php

namespace Tests\Fixtures\Edge;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Child-component fixture for the nested-components tests: declared props
 * (`name`, `level`), its own persistent state (`clicks`), a model-bound
 * property (`note`), an emit that a parent maps via a tag binding, and a
 * nested grandchild (BadgeChild) so child-in-child recursion is covered.
 */
class UserCardChild extends NativeComponent
{
    /** Lifecycle log, keyed test-side: mount:<name> / unmount:<name>. */
    public static array $events = [];

    // Props (assigned from the mounting tag's attributes).
    public string $name = '';

    public int $level = 0;

    // Own state — must persist across parent re-renders.
    public int $clicks = 0;

    public string $note = '';

    public string $lastHook = '';

    public function mount(): void
    {
        static::$events[] = 'mount:'.$this->name;
    }

    public function unmount(): void
    {
        static::$events[] = 'unmount:'.$this->name;

        parent::unmount();
    }

    public function bump(): void
    {
        $this->clicks++;
    }

    public function save(): void
    {
        $this->emit('card-saved', $this->name, $this->clicks);
    }

    public function updatedNote(string $value): void
    {
        $this->lastHook = "note:{$value}";
    }

    public function render(): View
    {
        return view('user-card-child');
    }
}
