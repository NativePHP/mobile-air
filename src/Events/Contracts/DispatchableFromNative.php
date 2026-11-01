<?php

namespace Native\Mobile\Events\Contracts;

/**
 * Marks an event the native side is allowed to dispatch back into PHP through
 * the `/_native/api/events` endpoint.
 *
 * That endpoint constructs the event class named in the request and fires it.
 * JavaScript running in a web view can POST to it (the route carries no
 * middleware), so without a gate a page could instantiate any autoloadable
 * class with constructor arguments of its choosing — an object-injection
 * gadget (e.g. `SplFileObject` to truncate a file). The controller therefore
 * refuses any class that does not implement this marker.
 *
 * Every device event NativePHP posts from native code implements it. A plugin
 * that dispatches its own device events back to PHP opts in the same way, by
 * implementing this interface on those event classes.
 */
interface DispatchableFromNative {}
