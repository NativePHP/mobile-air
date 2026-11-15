<native:column>
    @foreach ($labels as $label)
        <native:photo-child :label="$label" key="{{ $label }}" />
    @endforeach
</native:column>
