<native:scroll-view>
    @foreach ($tasks as $task)
        <native:text>{{ $task }}</native:text>
    @endforeach
    <native:fab icon="add" @tap="create" />
</native:scroll-view>
