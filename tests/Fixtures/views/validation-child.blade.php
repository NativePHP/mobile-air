<native:column>
    <native:text-input native:model="nickname" ref="nickname-input" />
    <native:pressable @tap="saveChild" ref="child-save-btn"><native:text>Save child</native:text></native:pressable>
    <native:pressable @tap="pingParent" ref="ping-btn"><native:text>Ping parent</native:text></native:pressable>
    @error('nickname')<native:text>CHILD-ERR {{ $message }}</native:text>@enderror
    {{-- The PARENT's hostField errors must never reach this child's bag
         when its emit() triggers a failing parent handler. --}}
    @error('hostField')<native:text>CHILD-LEAK {{ $message }}</native:text>@enderror
</native:column>
