<native:column>
    @foreach ($names as $n)
        <native:user-card-child :name="$n" />
    @endforeach
</native:column>
