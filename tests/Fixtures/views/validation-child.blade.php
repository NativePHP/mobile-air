<native:column>
    <native:text-input native:model="nickname" ref="nickname-input" />
    <native:pressable @tap="saveChild" ref="child-save-btn"><native:text>Save child</native:text></native:pressable>
    @error('nickname')<native:text>CHILD-ERR {{ $message }}</native:text>@enderror
</native:column>
