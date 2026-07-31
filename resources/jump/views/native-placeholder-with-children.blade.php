{{-- Render slot content to trigger child components --}}
{{ $slot }}

{{-- After slot renders, close the context --}}
@php
    \Native\Mobile\Edge\Edge::endContext($contextIndex, $type, $props);
@endphp
