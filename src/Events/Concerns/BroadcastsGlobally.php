<?php

namespace Native\Mobile\Events\Concerns;

/**
 * Marks a native event that, when it arrives over the bridge, should ALSO be
 * pushed through Laravel's global event dispatcher (`event()`) — not only
 * routed to the active component's `#[On]` handlers.
 *
 * This is what lets code *anywhere* in the app react to system-level signals
 * (theme flips, orientation, accessibility changes), e.g.:
 *
 *     Event::listen(AppearanceChanged::class, fn ($e) => Cache::forget('theme'));
 *
 * `NativeComponent::dispatchNativeEvent()` checks for this interface and, when
 * present, rebuilds the event from its payload and dispatches it globally in
 * addition to the normal component routing.
 */
interface BroadcastsGlobally {}
