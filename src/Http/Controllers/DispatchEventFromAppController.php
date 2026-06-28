<?php

namespace Native\Mobile\Http\Controllers;

use Closure;
use Illuminate\Http\Request;
use Native\Mobile\Support\NativeCallbacks;
use ReflectionFunction;

class DispatchEventFromAppController
{
    public function __invoke(Request $request)
    {
        $eventClass = $request->get('event');
        $payload = $request->get('payload', []);

        if (! class_exists($eventClass)) {
            return response()->json([
                'success' => false,
            ]);
        }

        $event = new $eventClass(...$payload);

        // Existing path: dispatch to Laravel listeners and #[On] handlers. Untouched.
        event($event);

        // New path: fire any fluent callback registered for this capture
        // (Camera::getPhoto()->photoTaken(...)). Coexists with the event above.
        $handled = $this->fireCallback($eventClass, $payload, $event);

        return response()->json([
            'success' => true,
            'callback' => $handled,
        ]);
    }

    /**
     * Resolve and invoke a callback correlated by the event's `id`, if one was
     * registered. The callback receives the result event object.
     */
    protected function fireCallback(string $eventClass, array $payload, object $event): bool
    {
        $id = $payload['id'] ?? null;

        if ($id === null) {
            return false;
        }

        // A component-bound ($this) closure can only be fired correctly by the
        // Edge component loop, which rebinds it to the live instance. In an Edge
        // app the same event also arrives here via the WebView fetch; if we
        // consumed it we'd run it against a dead instance (and race the Edge
        // path for the one-shot registration). Peek without consuming and bail.
        $peek = NativeCallbacks::resolve($id, $eventClass, consume: false);

        if ($peek === null) {
            return false;
        }

        if ($peek instanceof Closure && (new ReflectionFunction($peek))->getClosureThis() !== null) {
            return false;
        }

        $callback = NativeCallbacks::resolve($id, $eventClass);

        // Normalise an invokable class-string into a resolved instance so it can
        // be called like any other callable. Closures and [$obj, 'method'] arrays
        // are already callable.
        if (is_string($callback) && class_exists($callback)) {
            $callback = app($callback);
        }

        call_user_func($callback, $event);

        // One outcome per capture — drop the success/cancel/denied siblings too.
        NativeCallbacks::forget($id, $eventClass);

        return true;
    }
}
