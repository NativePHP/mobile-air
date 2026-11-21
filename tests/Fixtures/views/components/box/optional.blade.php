@isset($actions)
    <native:row>{{ $actions }}</native:row>
@endisset
@if ($slot->isNotEmpty())
    <native:column>{{ $slot ?? '' }}</native:column>
@endif
