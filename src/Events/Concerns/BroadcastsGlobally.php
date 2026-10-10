<?php

namespace Native\Mobile\Events\Concerns;

/**
 * Marks an event that is heard in both places at once: by the `#[On]` handlers
 * of the live native screen, and by the listeners of Laravel's dispatcher.
 * Without the marker an event stays on the side where it started.
 *
 * A marked event that arrives from the device over the bridge is also sent
 * through `event()`, so code anywhere in the app reacts to system signals
 * such as a theme flip or a change of orientation:
 *
 *     Event::listen(AppearanceChanged::class, fn ($e) => Cache::forget('theme'));
 *
 * A marked event that PHP fires with `event()` also reaches the live screen,
 * the one whose loop is running. Its public properties are the payload,
 * matched by `when` and bound to the parameters as a device payload is:
 *
 *     class OrderShipped implements BroadcastsGlobally
 *     {
 *         public function __construct(public int $orderId, public string $status) {}
 *     }
 *
 *     event(new OrderShipped(42, 'shipped'));
 *
 *     #[On(OrderShipped::class)]
 *     public function shipped(int $orderId, string $status): void {}
 *
 * The handler does not run inside `event()`. It runs on the next turn of the
 * screen's loop, after the current interaction, and the screen renders once
 * for all that arrived. Only the screen hears it, not a nested component,
 * and a screen that is covered by another one hears nothing, also not
 * when it comes back. Nothing waits for a screen that is not live.
 *
 * Under Jump, on the dev machine, an event that an async task fires is
 * the exception: it waits in a spool file, and the screen whose loop
 * is the next to drain that file hears it, however late that is.
 *
 * On a device, a queued job, an async task and the web view inside a native
 * screen each run in a PHP interpreter of their own. A marked event that
 * one of them fires is sent to the interpreter of the native screens.
 *
 * Laravel's listeners for it then run there, not where it was fired,
 * and the live screen hears it. The event crosses as itself: it
 * is serialized with everything it holds, signed with the app
 * key, and sent in memory through the native event queue.
 *
 * So a model, a date or a collection arrives as it was fired. With
 * the `SerializesModels` trait only the key of a model crosses,
 * which keeps the event small, and the model is fetched again
 * from the database on arrival there, as in a queued job.
 *
 * The event stays where it was fired, and its listeners run there,
 * when it cannot be serialized (it holds a closure, say), when
 * it is too large for the queue, when the app has no key to
 * sign it with, when no native screen session is live, or
 * when the queue of events of that session is full.
 *
 * Under Jump an async task cannot tell whether a screen is live,
 * so its event leaves all the same: its listeners do not run
 * in the task, and it waits in the spool file for a loop.
 */
interface BroadcastsGlobally {}
