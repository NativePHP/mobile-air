{{-- Edge navigation system: collects children into an Edge context for navigation chrome (top bar, bottom nav, side nav). --}}
{{ $slot }}
@php
    \Native\Mobile\Edge\Edge::endContext($contextIndex, $type, $props);
@endphp