<native:column>
    <x-box.tappable>
        <native:pressable ref="inner" @tap="innerTapped">
            <native:text>Inner</native:text>
        </native:pressable>
        <native:user-card-child name="slotted" />
    </x-box.tappable>
</native:column>
