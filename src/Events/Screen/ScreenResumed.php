<?php

namespace Native\Mobile\Events\Screen;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A screen was revealed again after the screen above it was popped.
 *
 * Dispatched by [[NativeRouter]] around the component's own lifecycle
 * hook, so an observer does not have to subclass or wrap anything:
 *
 *     Event::listen(ScreenResumed::class, fn ($e) => ...);
 *
 * This exists for cross-cutting listeners — telemetry, analytics, crash
 * breadcrumbs — that need the screen lifecycle without competing with the
 * app for the `mount()` / `onResume()` / `unmount()` overrides.
 */
class ScreenResumed
{
    use Dispatchable;

    public function __construct(
        /** @var class-string */
        public string $component,
        public ?string $uri = null,
        public ?string $componentId = null,
    ) {}
}
