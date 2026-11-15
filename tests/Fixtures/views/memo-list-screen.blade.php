<native:column>
    <native:text>{{ $title }}</native:text>
    <native:column native:key="list">
        @foreach ($rows as $slug => $label)
            <native:memo-list-row-child :slug="$slug" :label="$label" :keyed="$keyed" key="row-{{ $slug }}" />
        @endforeach
    </native:column>
</native:column>
