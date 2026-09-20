# Android native-event profiling

This trace answers a specific performance question: when a native scanner emits
an event, how much time is spent before PHP starts, inside the persistent PHP
runtime, and in the Laravel event handler?

It is deliberately not a barcode-decoder benchmark. Camera exposure, frame
selection, and decoder implementation must be measured separately when
comparing frameworks.

## Trace points

Every native event dispatched through `NativeActionCoordinator` receives a UUID
and emits these `logcat` records under the `NativePHPTrace` tag:

| Stage | Meaning |
| --- | --- |
| `native_dispatch` | Kotlin handed the event to WebView JavaScript. |
| `webview_intercept` | The event POST reached the WebView request interceptor. |
| `php_executor_start` | The persistent PHP executor began the request; `queue_ns` exposes contention. |
| `php_executor_end` | JNI/PHP request execution completed. |
| `webview_response` | The PHP response was parsed and returned to WebView. |
| `php_event_handler` | Laravel reports synchronous event construction and dispatch time in microseconds. |

The native stages use `SystemClock.elapsedRealtimeNanos()`, so their deltas are
monotonic and comparable within the same Android process. PHP handler time uses
`hrtime(true)` only for the handler-local measurement; it must not be subtracted
from Android timestamps.

## Controlled barcode run

Use a release build on one device. Keep the QR image, camera position, screen
brightness, barcode format, app state, and repetition count fixed. Record:

1. Device model, Android version, battery/thermal state, and build SHA.
2. Scanner/decoder implementation and version.
3. 1,000 successful scans after a warm-up period.
4. Median, p95, and maximum for each native trace delta.
5. A Perfetto trace captured over the same interval.

Collect the bridge timeline with:

```bash
adb logcat -v epoch -s NativePHPTrace:I '*:S'
```

The trace IDs must be used to join records; never aggregate unrelated events by
log order. The data distinguishes bridge queuing, PHP execution, and WebView
transport from camera/decoder time instead of attributing the entire result to
PHP or React Native.

Save the output, then calculate the distribution with:

```bash
bin/analyse-native-event-trace native-event-trace.log
```

The report gives median, p95, and maximum values for native-to-WebView,
executor queue, PHP request, native round trip, and the PHP event handler.
