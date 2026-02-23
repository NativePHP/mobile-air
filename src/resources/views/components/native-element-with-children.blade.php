{{-- NativeElementCollector system: renders slot then closes the current element on the collector stack. --}}
{{ $slot }}
@php
    \Native\Mobile\Edge\NativeElementCollector::close();
@endphp