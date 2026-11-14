<native:column>
    <native:text>Row: {{ $label }} T{{ $taps }}</native:text>
    <native:pressable ref="tap-{{ $slug }}" @tap="tap">
        <native:text>Tap {{ $slug }}</native:text>
    </native:pressable>
    <native:memo-tally-child :owner="$slug" />
</native:column>
