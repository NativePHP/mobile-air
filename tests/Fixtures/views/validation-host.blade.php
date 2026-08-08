<native:column>
    <native:text>HOST</native:text>
    <native:validation-child />
    {{-- The child's `nickname` errors belong to the CHILD's bag — this
         parent-side probe must never render. --}}
    @error('nickname')<native:text>HOST-LEAKED {{ $message }}</native:text>@enderror
</native:column>
