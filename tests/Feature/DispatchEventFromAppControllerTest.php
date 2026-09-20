<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Native\Mobile\Http\Controllers\DispatchEventFromAppController;
use Tests\Fixtures\NativeEventTraceFixture;

it('returns a timing header for an opt-in native event trace', function () {
    Event::fake();

    $request = Request::create('/_native/api/events', 'POST', [
        'event' => NativeEventTraceFixture::class,
        'payload' => ['value' => 'benchmark'],
    ], server: [
        'HTTP_X_NATIVEPHP_TRACE_ID' => 'trace-123',
        'HTTP_X_NATIVEPHP_EVENT_TRACE' => '1',
    ]);

    $response = app(DispatchEventFromAppController::class)($request);

    $this->assertTrue($response->getData()->success);
    $this->assertSame('trace-123', $response->headers->get('X-NativePHP-Trace-Id'));
    $this->assertIsNumeric($response->headers->get('X-NativePHP-Event-Handler-Us'));

    Event::assertDispatched(NativeEventTraceFixture::class, fn (NativeEventTraceFixture $event) => $event->value === 'benchmark');
});
