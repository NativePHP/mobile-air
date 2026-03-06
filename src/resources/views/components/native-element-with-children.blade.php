{{-- NativeElementCollector system: renders slot then closes the current element on the collector stack. --}}
{{ $slot }}
@php
    if (\Native\Mobile\Edge\NativeElementCollector::isStreaming()) {
        \Native\Mobile\Edge\NativeElementCollector::closeStreaming();
    } else {
        \Native\Mobile\Edge\NativeElementCollector::close();
    }
@endphp