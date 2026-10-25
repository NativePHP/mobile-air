<?php

namespace Native\Mobile\Http\Controllers;

use Illuminate\Http\Request;
use Native\Mobile\Events\Contracts\DispatchableFromNative;

class DispatchEventFromAppController
{
    public function __invoke(Request $request)
    {
        $event = $request->get('event');
        $payload = $request->get('payload', []);

        // This endpoint constructs the named class and fires it, and JavaScript
        // in a web view can reach it (the `api` routes carry no middleware). So
        // the class must be one the native side is allowed to dispatch — every
        // device event implements DispatchableFromNative — or a page could name
        // any autoloadable class and hand it constructor arguments of its own,
        // an object-injection gadget (e.g. SplFileObject to truncate a file).
        if (is_string($event)
            && class_exists($event)
            && is_subclass_of($event, DispatchableFromNative::class)) {
            $event = new $event(...$payload);
            event($event);

            return response()->json([
                'success' => true,
            ]);

        } else {

            return response()->json([
                'success' => false,
            ]);
        }
    }
}
