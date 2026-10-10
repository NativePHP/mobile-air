<?php

namespace Native\Mobile\Support;

use Illuminate\Contracts\Container\Container;
use Native\Mobile\Edge\NativeComponent;
use SupaNative\Core\Contracts\BroadcastsGlobally;
use SupaNative\Core\Contracts\RoutesGlobalEvents;

/**
 * The answers of this package to the dispatcher of supanative/core, about an
 * event marked BroadcastsGlobally that PHP fires with event(): whether it
 * crosses to the main lane, and which screen gets it once it is there.
 *
 * {@see \Native\Mobile\Events\Concerns\BroadcastsGlobally} lists when
 * an event stays in its lane instead, and what differs under Jump.
 * {@see GlobalEventTransport} carries one across, and says how.
 *
 * @internal
 */
class GlobalEventRouter implements RoutesGlobalEvents
{
    public function __construct(protected Container $container) {}

    /**
     * Tell whether this lane sent an event to the main lane, where a
     * live native screen got it. The main lane sends nothing, and
     * an event that could not be delivered stays in this lane.
     *
     * {@see GlobalEventTransport::send()} decides what counts as
     * delivered, and says what that is for a task under Jump.
     */
    public function sendElsewhere(BroadcastsGlobally $event): bool
    {
        return ! Lane::isMain() && $this->container->make(GlobalEventTransport::class)->send($event);
    }

    /**
     * Put an event in the inbox of the live screen, the one whose
     * loop is running now. No other screen hears it, and while
     * no screen is live it reaches none and waits for none.
     */
    public function handToScreens(BroadcastsGlobally $event): void
    {
        NativeComponent::active()?->receiveGlobalEvent($event);
    }
}
