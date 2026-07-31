<?php

namespace Native\Mobile\Attributes;

use Attribute;

/**
 * Periodically wakes the render loop.
 *
 * On a method — calls the method every `ms` milliseconds, then re-renders:
 *
 *     #[Poll(2000)]
 *     public function refresh(): void
 *     {
 *         $this->messages = Message::latest()->get();
 *     }
 *
 * On the class — re-renders every `ms` milliseconds with no callback
 * (the equivalent of Livewire's bare `wire:poll`), useful when `render()`
 * reads a value that changes on its own (e.g. a relative timestamp):
 *
 *     #[Poll(1000)]
 *     class Clock extends NativeComponent { ... }
 *
 * Repeatable, so a class may declare several independent poll cadences.
 * Intervals are best-effort: the loop also wakes on user interaction, and
 * a poll only fires once its interval has actually elapsed.
 */
#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Poll
{
    public function __construct(public int $ms = 2000) {}
}
