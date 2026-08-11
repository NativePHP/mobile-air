<native:column>
    <native:text>HOST</native:text>
    <native:validation-child @saved="parentSave" />
    {{-- The child's `nickname` errors belong to the CHILD's bag — this
         parent-side probe must never render. --}}
    @error('nickname')<native:text>HOST-LEAKED {{ $message }}</native:text>@enderror
    {{-- The parent's own validation failure (triggered via the child's
         emit) records HERE. --}}
    @error('hostField')<native:text>HOST-ERR {{ $message }}</native:text>@enderror
</native:column>
