<?php

use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use Native\Mobile\Events\Motion\ShakeDetected;

it('marks ShakeDetected for global dispatch', function () {
    // A shake has no owning component, and webview screens already reach
    // app-wide listeners through POST /_native/api/events. The marker gives
    // Event::listen the same reach on edge screens.
    expect(is_subclass_of(ShakeDetected::class, BroadcastsGlobally::class))->toBeTrue();
});
