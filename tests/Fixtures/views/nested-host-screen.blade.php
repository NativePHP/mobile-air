<native:column>
    <native:text>{{ $title }}</native:text>
    <native:text>Pokes: {{ implode(',', $pokes) }}</native:text>
    @if ($showCard)
        <native:user-card-child name="solo" level="3" @card-saved="markSaved('tag')" />
    @endif
    @foreach ($names as $n)
        <native:user-card-child :name="$n" key="card-{{ $n }}" :level="$loop->index" />
    @endforeach
</native:column>
