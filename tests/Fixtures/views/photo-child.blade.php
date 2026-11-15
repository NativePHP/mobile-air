<native:column>
    <native:text>Photo {{ $label }}: {{ $photo ?? 'none' }}</native:text>
    <native:pressable ref="photo-{{ $label }}" @tap="takePhoto">
        <native:text>Take photo</native:text>
    </native:pressable>
</native:column>
