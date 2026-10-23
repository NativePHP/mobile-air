<?php

namespace Native\Mobile\Events;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Testing\Fakes\EventFake;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use Native\Mobile\Support\GlobalEventTransport;
use Native\Mobile\Support\Lane;
use WeakMap;

/**
 * Laravel's dispatcher as an app that loads this package has it. An event
 * marked BroadcastsGlobally, fired from PHP with event(), is handed to
 * the live screen as well, once Laravel has called its listeners.
 *
 * That happens in invokeListeners(), where Laravel runs the listeners
 * of an event. A ShouldDispatchAfterCommit event gets there once
 * the transaction that it was held for has committed.
 *
 * On a lane next to the main one (a queued job, an async task, a web view in a
 * native screen) there is no screen to hand it to. The event is sent to the
 * main lane at that point, and its listeners run on the main lane alone.
 *
 * {@see GlobalEventTransport} carries it there, and says how it crosses.
 * {@see BroadcastsGlobally} lists when it stays in its own lane, with
 * the listeners of that lane, and what is different under Jump.
 *
 * @internal
 */
class GlobalEventDispatcher extends Dispatcher
{
    /** @var WeakMap<object, true>|null Event objects that were built from a device payload. */
    protected static ?WeakMap $fromNative = null;

    /**
     * Put this dispatcher in the place of Laravel's own. A dispatcher of
     * another class stays where it is, as does Laravel's when it has
     * no invokeListeners(), and the log says what that leaves out.
     */
    public static function install(Application $app): void
    {
        $kept = null;

        if (static::hooksIntoLaravel()) {
            $app->extend('events', function (object $events) use (&$kept) {
                if ($events::class === Dispatcher::class) {
                    return static::replacing($events);
                }

                if (! $events instanceof self) {
                    $kept = 'the event dispatcher is ['.$events::class."], and only Laravel's own can be replaced";
                }

                return $events;
            });

            // The facade may have kept the dispatcher this one has just replaced.
            Event::clearResolvedInstance('events');
        } else {
            $kept = "the event dispatcher of Laravel {$app->version()} has no invokeListeners(), which came with 10.30";
        }

        $app->booted(function () use (&$kept) {
            if ($kept !== null) {
                Log::warning("Events marked BroadcastsGlobally that PHP fires with event() will not reach native screens: {$kept}.");
            }
        });
    }

    /**
     * A dispatcher that shares all the original holds, by reference, so a
     * listener added through either one is heard through the two. What
     * took the original before this replaced it works on as it did.
     */
    public static function replacing(Dispatcher $original): static
    {
        $replacement = new static($original->container);

        foreach (array_keys(get_object_vars($original)) as $property) {
            $replacement->{$property} = &$original->{$property};
        }

        return $replacement;
    }

    /**
     * Mark an event object as one that the native side built, before it
     * goes through event(). The screen heard that event on its way in,
     * so this dispatcher never hands the same object to it again.
     */
    public static function markFromNative(object $event): void
    {
        static::$fromNative ??= new WeakMap;

        static::$fromNative[$event] = true;
    }

    /**
     * Tell whether the dispatcher that the app has bound has a listener for
     * the class of an event or for one of its interfaces. A screen asks
     * this for an event it fires, as such a listener needs a frame.
     *
     * @internal
     */
    public static function laravelListensFor(object $event): bool
    {
        $events = app('events');

        // Event::fake() wraps the dispatcher. The listeners registered on
        // the wrapped one decide, also when the fake keeps them from
        // running, so a test renders as often as without a fake.
        // Before Laravel 10.1.5 a fake does not show what it
        // wraps, and then the fake itself is asked below.
        if ($events instanceof EventFake) {
            $events = $events->dispatcher ?? $events;
        }

        if ($events instanceof self) {
            return $events->hasNamedListeners($event::class);
        }

        // Another dispatcher only has hasListeners() to ask. It counts
        // wildcards, which at worst renders once too often, and it
        // leaves out interfaces, so each of them is asked for.
        foreach ([$event::class, ...array_values(class_implements($event))] as $name) {
            if ($events->hasListeners($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tell whether a listener is registered for an event class by its name
     * or by the name of one of its interfaces, as Laravel collects them.
     * A wildcard listener is left out, since a logging tool has one
     * for every event and no render may depend on such a tool.
     *
     * @internal
     */
    public function hasNamedListeners(string $eventName): bool
    {
        if (isset($this->listeners[$eventName])) {
            return true;
        }

        foreach (class_exists($eventName) ? class_implements($eventName) : [] as $interface) {
            if (isset($this->listeners[$interface])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tell whether Laravel ends its dispatch in an invokeListeners() of
     * its own. It does from 10.30 on, and without that method there
     * is nothing for this dispatcher to hook the hand-over into.
     */
    protected static function hooksIntoLaravel(): bool
    {
        return method_exists(Dispatcher::class, 'invokeListeners');
    }

    /**
     * Call Laravel's listeners for an event, and hand a marked one that
     * PHP fired to the live screen afterwards. The screen takes it
     * on its next loop turn, so no handler of it runs in here.
     *
     * On another lane a marked event that PHP fired is sent to the main lane
     * first. Once it is delivered there, its listeners run there, and the
     * dispatch here answers what Laravel answers when no listener did.
     *
     * @param  string  $event
     * @param  array<array-key, mixed>  $payload
     * @param  bool  $halt
     * @return mixed
     */
    protected function invokeListeners($event, $payload, $halt = false)
    {
        $marked = $payload[0] ?? null;

        // A marked object that a string event carries is the payload of that
        // event, and it stays with Laravel. So does an event the device
        // sent, as the screen heard that one already on its way in.
        if (! $marked instanceof BroadcastsGlobally
            || $marked::class !== $event
            || isset(static::$fromNative[$marked])) {
            return parent::invokeListeners($event, $payload, $halt);
        }

        // On another lane the event goes to the main lane, which runs its
        // listeners and has the live screen. No listener runs in here,
        // and this answers as for an event that nobody listened to.
        if ($this->sentToMainLane($marked)) {
            return $halt ? null : [];
        }

        $responses = parent::invokeListeners($event, $payload, $halt);

        NativeComponent::active()?->receiveGlobalEvent($marked);

        return $responses;
    }

    /**
     * Tell whether this lane sent an event to the main lane, where a
     * live native screen got it. The main lane sends nothing, and
     * an event that could not be delivered stays in this lane.
     *
     * {@see GlobalEventTransport::send()} decides what counts as
     * delivered, and says what that is for a task under Jump.
     */
    protected function sentToMainLane(object $event): bool
    {
        return ! Lane::isMain() && $this->container->make(GlobalEventTransport::class)->send($event);
    }
}
