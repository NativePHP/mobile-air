<?php

namespace Native\Mobile\Events\App;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * The app came back from the background. Fired from native (iOS
 * `willEnterForegroundNotification`, Android when the first activity starts
 * again). A cold launch does not fire it.
 *
 * `$secondsAway` is measured natively on a clock that keeps counting while
 * the device sleeps, so it suits timeouts: lock the app, or refresh data that
 * has gone stale while the user was away.
 *
 *     #[On(AppForegrounded::class)]
 *     public function foregrounded(int $secondsAway): void { ... }
 *
 * …or anywhere in the app (see [[BroadcastsGlobally]]):
 *
 *     Event::listen(AppForegrounded::class, fn ($e) => ...);
 *
 * Screens are not resumed when the app returns (`onResume()` runs only when
 * a screen is shown again after the one above it is popped), so this is the
 * hook for "the user is back".
 */
class AppForegrounded implements BroadcastsGlobally
{
    use Dispatchable;

    public function __construct(
        public int $secondsAway = 0,
    ) {}
}
