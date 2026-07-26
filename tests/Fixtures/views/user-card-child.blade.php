<native:column>
    <native:text>Card: {{ $name }} L{{ $level }} C{{ $clicks }}</native:text>
    <native:pressable ref="bump-{{ $name }}" @tap="bump">
        <native:text>Bump</native:text>
    </native:pressable>
    <native:pressable ref="save-{{ $name }}" @tap="save">
        <native:text>Save</native:text>
    </native:pressable>
    <native:text-input ref="note-{{ $name }}" label="Note" native:model="note" />
    <native:badge-child :owner="$name" />
</native:column>
