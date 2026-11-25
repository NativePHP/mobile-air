<native:column>
    @error('title')
        <native:text>Host: {{ $message }}</native:text>
    @enderror
    <native:validating-child @picked="childPicked" />
</native:column>
