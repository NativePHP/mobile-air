<native:column>
    <native:text>{{ $title }}</native:text>
    @foreach ($rows as $slug => $label)
        @if ($plain)
            <native:plain-row-child :slug="$slug" :label="$label" key="plain-{{ $slug }}" />
        @else
            <native:memo-row-child :slug="$slug" :label="$label" key="row-{{ $slug }}" />
        @endif
    @endforeach
    @if ($withClosureChild)
        <native:memo-closure-child />
    @endif
    @if ($withObjectChild)
        <native:memo-object-child :item="$item" :tone="$tone" />
    @endif
</native:column>
