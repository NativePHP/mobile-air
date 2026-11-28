<?php

namespace Native\Mobile\Events\Notification;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * The user opened a notification: tapped it, or on iOS chose one of its
 * actions or sent a reply from it. Fired from native for push and local
 * notifications alike.
 *
 * A tap that launches the app arrives before PHP has booted. It is held and
 * fired once the first screen is live, so a cold launch still delivers it.
 *
 *     #[On(NotificationTapped::class)]
 *     public function opened(string $action, array $data): void { ... }
 *
 * …or anywhere in the app (see [[BroadcastsGlobally]]):
 *
 *     Event::listen(NotificationTapped::class, fn ($e) => ...);
 *
 * - `$action` is `default` for a tap on the notification itself, `dismiss`
 *   when an iOS category asks to hear dismissals, or the identifier of the
 *   action the user chose.
 * - `$id` is the FCM message ID for a push sent through FCM, on both
 *   platforms. Otherwise it is the notification's identifier on iOS, and
 *   null on Android.
 * - `$data` holds the notification's custom keys: the `userInfo` without
 *   `aps` on iOS, the intent's string extras on Android. Firebase's own keys
 *   (`google.*`, `gcm.*`, `fcm_options`, `from`, `collapse_key`) are left
 *   out on both.
 * - `$text` is what the user typed into a text input action (iOS), else null.
 *
 * On iOS core is the notification center's delegate. A plugin that sets its
 * own delegate from its init function, or from an onAppLaunch callback,
 * keeps receiving every call, because core passes them on to it. On Android this fires for a tap on a
 * notification that FCM displayed, or on one whose intent carries a
 * `notification_url` extra. An Android action button goes to the component
 * its plugin gave it, so core does not see it.
 */
class NotificationTapped implements BroadcastsGlobally
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $action = 'default',
        public ?string $id = null,
        public array $data = [],
        public ?string $text = null,
    ) {}
}
