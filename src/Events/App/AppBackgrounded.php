<?php

namespace Native\Mobile\Events\App;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * The app moved to the background: the user went home, switched apps or
 * locked the device. Fired from native (iOS `didEnterBackgroundNotification`,
 * Android when the last started activity stops).
 *
 * The OS may suspend PHP straight after this, so do not count on finishing
 * slow work here. Save what must survive, such as a draft, and pick the rest
 * up in AppForegrounded.
 *
 *     #[On(AppBackgrounded::class)]
 *     public function backgrounded(): void { ... }
 *
 * …or anywhere in the app (see [[BroadcastsGlobally]]):
 *
 *     Event::listen(AppBackgrounded::class, fn () => ...);
 */
class AppBackgrounded implements BroadcastsGlobally
{
    use Dispatchable;
}
