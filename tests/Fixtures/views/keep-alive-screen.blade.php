<native:column>
    <native:text>{{ $label }}</native:text>
    @foreach (['increment', 'openDetail', 'goShop', 'signOut'] as $method)
        <native:pressable @tap="{{ $method }}">
            <native:text>call:{{ $method }}</native:text>
        </native:pressable>
    @endforeach
</native:column>
