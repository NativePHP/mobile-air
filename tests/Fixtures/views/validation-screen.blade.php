<native:column>
    <native:text-input native:model="title" ref="title-input" />
    <native:text-input native:model="bio" ref="bio-input" />

    <native:pressable @tap="save" ref="save-btn"><native:text>Save</native:text></native:pressable>

    <native:text>{{ $saved ? 'SAVED' : 'UNSAVED' }}</native:text>

    @error('title')<native:text>TITLE-ERR {{ $message }}</native:text>@enderror
    @error('bio')<native:text>BIO-ERR {{ $message }}</native:text>@enderror
    @error('tags.1')<native:text>TAG-ERR {{ $message }}</native:text>@enderror

    @nativeError('bio', '#AB12CD')
</native:column>
@error('tags.0')<native:text>TAG0-ERR {{ $message }}</native:text>@enderror
@error('handle')<native:text>HANDLE-ERR {{ $message }}</native:text>@enderror
