<native:column>
    <native:error-probe native:model="name" />
    <native:error-probe native:model="email" />
    <native:error-probe error-for="email" />
    <native:error-probe native:model="email" error="Authored error" />
    @error('name')
        <native:text>Blade: {{ $message }}</native:text>
    @enderror
    <native:text>{{ $saved ? 'Saved' : 'Not saved' }}</native:text>
    <native:pressable @tap="save"><native:text>Save</native:text></native:pressable>
    <native:pressable @tap="reject"><native:text>Reject</native:text></native:pressable>
</native:column>
